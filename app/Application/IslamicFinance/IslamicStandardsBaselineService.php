<?php

declare(strict_types=1);

namespace App\Application\IslamicFinance;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

final class IslamicStandardsBaselineService
{
    public const PRODUCT_FAMILIES = [
        'mourabaha',
        'ijara',
        'ijara_wa_iqtina',
        'salam',
        'istisnaa',
        'moudaraba',
        'moucharaka',
    ];

    public const ACCOUNT_TYPES = [
        'islamic_current_account',
        'islamic_savings_account',
        'islamic_investment_account',
    ];

    public const LINK_TYPES = [
        'product_family',
        'account_type',
        'accounting_mapping',
        'contract_template',
        'screening_policy',
    ];

    public function hasActiveBaseline(string $linkableType, string $linkableCode, ?CarbonInterface $asOf = null): bool
    {
        $asOfDate = ($asOf ?? CarbonImmutable::now())->toDateString();

        return DB::table('islamic_standard_links as l')
            ->join('islamic_standards as s', 's.id', '=', 'l.islamic_standard_id')
            ->join('documents as d', 'd.id', '=', 's.document_id')
            ->where('l.linkable_type', $linkableType)
            ->where('l.linkable_code', $linkableCode)
            ->where('s.status', 'active')
            ->where('s.effective_date', '<=', $asOfDate)
            ->where(function ($q) use ($asOfDate): void {
                $q->whereNull('s.expiry_date')->orWhere('s.expiry_date', '>', $asOfDate);
            })
            ->where('d.status', 'active')
            ->exists();
    }

    /**
     * @return array<int, string>
     */
    public function activationFailuresForProductFamily(string $productFamily, ?CarbonInterface $asOf = null): array
    {
        if (! in_array($productFamily, self::PRODUCT_FAMILIES, true)) {
            return ['Unsupported product family.'];
        }

        return $this->collectFailures('product_family', $productFamily, $asOf);
    }

    /**
     * @return array<int, string>
     */
    public function activationFailuresForAccountType(string $accountType, ?CarbonInterface $asOf = null): array
    {
        if (! in_array($accountType, self::ACCOUNT_TYPES, true)) {
            return ['Unsupported account type.'];
        }

        return $this->collectFailures('account_type', $accountType, $asOf);
    }

    /**
     * @return array<int, string>
     */
    private function collectFailures(string $linkableType, string $linkableCode, ?CarbonInterface $asOf): array
    {
        $asOfDate = ($asOf ?? CarbonImmutable::now())->toDateString();

        if ($this->hasActiveBaseline($linkableType, $linkableCode, $asOf)) {
            return [];
        }

        $links = DB::table('islamic_standard_links as l')
            ->join('islamic_standards as s', 's.id', '=', 'l.islamic_standard_id')
            ->leftJoin('documents as d', 'd.id', '=', 's.document_id')
            ->where('l.linkable_type', $linkableType)
            ->where('l.linkable_code', $linkableCode)
            ->select([
                's.status as standard_status',
                's.effective_date',
                's.expiry_date',
                'd.status as document_status',
            ])
            ->get();

        if ($links->isEmpty()) {
            return ['No standard is linked to this scope; a standards baseline is required before approval.'];
        }

        $failures = [];
        $sawFutureEffective = false;
        $sawExpired = false;
        $sawArchivedDocument = false;
        $sawInactiveStatus = false;
        $sawActiveButFutureEffective = false;

        foreach ($links as $row) {
            $status = is_string($row->standard_status) ? $row->standard_status : '';
            $effective = is_string($row->effective_date) ? $row->effective_date : '';
            $expiry = is_string($row->expiry_date) ? $row->expiry_date : null;
            $documentStatus = is_string($row->document_status) ? $row->document_status : '';

            if (in_array($status, ['draft', 'retired', 'superseded'], true)) {
                $sawInactiveStatus = true;

                continue;
            }
            if ($status === 'active' && $effective > $asOfDate) {
                $sawActiveButFutureEffective = true;

                continue;
            }
            if ($status === 'expired' || ($expiry !== null && $expiry <= $asOfDate)) {
                $sawExpired = true;

                continue;
            }
            if ($effective > $asOfDate) {
                $sawFutureEffective = true;

                continue;
            }
            if ($documentStatus !== 'active') {
                $sawArchivedDocument = true;

                continue;
            }
        }

        if ($sawInactiveStatus) {
            $failures[] = 'Linked standard exists but is draft, retired, or superseded.';
        }
        if ($sawActiveButFutureEffective) {
            $failures[] = 'A future-effective replacement exists but is not yet in force; the predecessor must remain the active baseline until its effective date.';
        }
        if ($sawFutureEffective) {
            $failures[] = 'Linked standard is future-effective and cannot satisfy readiness yet.';
        }
        if ($sawExpired) {
            $failures[] = 'Linked standard has expired and cannot satisfy readiness.';
        }
        if ($sawArchivedDocument) {
            $failures[] = 'Linked standard evidence document is archived or inactive.';
        }

        if ($failures === []) {
            $failures[] = 'No currently effective, evidenced standard is linked to this scope.';
        }

        return $failures;
    }
}
