<?php

declare(strict_types=1);

namespace App\Application\IslamicFinance;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

final class IslamicRegulatorySignoffService
{
    public const JURISDICTIONS = ['cameroon', 'cemac'];

    public const REGULATORS = ['cobac', 'beac', 'minfi', 'other'];

    public const APPROVAL_TYPES = ['allow', 'allow_with_conditions', 'deny'];

    public const STATUSES = ['draft', 'active', 'suspended', 'revoked', 'expired', 'retired'];

    public const LINK_TYPES = ['product_family', 'account_type'];

    public const RESTRICTION_MODES = ['allow', 'deny'];

    /**
     * @return array<int, string>
     */
    public function activationFailuresForProductFamily(string $productFamily, ?CarbonInterface $asOf = null): array
    {
        if (! in_array($productFamily, IslamicStandardsBaselineService::PRODUCT_FAMILIES, true)) {
            return ['Unsupported product family.'];
        }

        return $this->collectFailures('product_family', $productFamily, $asOf);
    }

    /**
     * @return array<int, string>
     */
    public function activationFailuresForAccountType(string $accountType, ?CarbonInterface $asOf = null): array
    {
        if (! in_array($accountType, IslamicStandardsBaselineService::ACCOUNT_TYPES, true)) {
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

        $links = DB::table('islamic_regulatory_signoff_links as l')
            ->join('islamic_regulatory_signoffs as s', 's.id', '=', 'l.islamic_regulatory_signoff_id')
            ->leftJoin('documents as d', 'd.id', '=', 's.document_id')
            ->where('l.linkable_type', $linkableType)
            ->where('l.linkable_code', $linkableCode)
            ->select([
                's.status as signoff_status',
                's.approval_type',
                's.effective_date',
                's.expiry_date',
                'd.status as document_status',
                'l.restriction_mode',
            ])
            ->get();

        if ($links->isEmpty()) {
            return ['No regulatory sign-off is linked to this scope; legal/compliance clearance is required before approval.'];
        }

        $hasApplicableAllow = false;
        $hasApplicableDeny = false;
        $sawDraft = false;
        $sawSuspended = false;
        $sawRevoked = false;
        $sawRetired = false;
        $sawFutureEffective = false;
        $sawExpired = false;
        $sawArchivedDocument = false;
        $sawDenyApprovalType = false;

        foreach ($links as $row) {
            $status = is_string($row->signoff_status) ? $row->signoff_status : '';
            $approvalType = is_string($row->approval_type) ? $row->approval_type : '';
            $effective = is_string($row->effective_date) ? $row->effective_date : '';
            $expiry = is_string($row->expiry_date) ? $row->expiry_date : null;
            $documentStatus = is_string($row->document_status) ? $row->document_status : '';
            $mode = is_string($row->restriction_mode) ? $row->restriction_mode : '';

            // Track lifecycle issues regardless of mode for diagnostics.
            if ($status === 'draft') {
                $sawDraft = true;

                continue;
            }
            if ($status === 'suspended') {
                $sawSuspended = true;

                continue;
            }
            if ($status === 'revoked') {
                $sawRevoked = true;

                continue;
            }
            if ($status === 'retired') {
                $sawRetired = true;

                continue;
            }
            if ($status === 'expired') {
                $sawExpired = true;

                continue;
            }

            $isDateValid = $effective !== '' && $effective <= $asOfDate
                && ($expiry === null || $expiry > $asOfDate);
            if (! $isDateValid) {
                if ($effective !== '' && $effective > $asOfDate) {
                    $sawFutureEffective = true;
                } elseif ($expiry !== null && $expiry <= $asOfDate) {
                    $sawExpired = true;
                }

                continue;
            }
            if ($documentStatus !== 'active') {
                $sawArchivedDocument = true;

                continue;
            }
            if ($status !== 'active') {
                continue;
            }

            // approval_type=deny means the whole sign-off denies anything it links.
            // This complements the workflow-level activation block as defense in depth.
            if ($approvalType === 'deny') {
                $sawDenyApprovalType = true;
                $hasApplicableDeny = true;

                continue;
            }

            if ($mode === 'deny') {
                $hasApplicableDeny = true;
            } elseif ($mode === 'allow') {
                $hasApplicableAllow = true;
            }
        }

        if ($hasApplicableDeny) {
            return ['Regulatory sign-off explicitly denies this scope.'];
        }

        if ($hasApplicableAllow) {
            return [];
        }

        $failures = [];
        if ($sawDraft) {
            $failures[] = 'Linked regulatory sign-off is still draft.';
        }
        if ($sawSuspended) {
            $failures[] = 'Linked regulatory sign-off is suspended.';
        }
        if ($sawRevoked) {
            $failures[] = 'Linked regulatory sign-off is revoked.';
        }
        if ($sawRetired) {
            $failures[] = 'Linked regulatory sign-off is retired.';
        }
        if ($sawFutureEffective) {
            $failures[] = 'Linked regulatory sign-off is future-effective and cannot satisfy readiness yet.';
        }
        if ($sawExpired) {
            $failures[] = 'Linked regulatory sign-off has expired.';
        }
        if ($sawArchivedDocument) {
            $failures[] = 'Linked regulatory sign-off evidence document is archived or inactive.';
        }
        if ($sawDenyApprovalType) {
            $failures[] = 'Linked regulatory sign-off approval type is deny.';
        }

        if ($failures === []) {
            $failures[] = 'No currently effective regulatory sign-off allows this scope.';
        }

        return $failures;
    }
}
