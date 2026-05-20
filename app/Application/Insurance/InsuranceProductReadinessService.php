<?php

declare(strict_types=1);

namespace App\Application\Insurance;

use App\Models\LedgerAccount;
use Illuminate\Support\Facades\DB;

final class InsuranceProductReadinessService
{
    /**
     * @return list<string>
     */
    public function activationFailures(object $product): array
    {
        $failures = [];
        $productId = $this->rowInt($product, 'id');
        $currency = $this->rowString($product, 'currency');

        $partner = $this->partner($product);
        $agencyId = is_object($partner) ? $this->rowNullableInt($partner, 'agency_id') : null;
        if (! is_object($partner)) {
            $failures[] = 'active insurance partner must be configured';
        } elseif ($agencyId === null) {
            $failures[] = 'insurance partner must be agency-scoped before activation';
        }

        if ($this->rowNullableString($product, 'business_model') === null) {
            $failures[] = 'business_model must be set before activating the product';
        }

        if ($this->rowNullableString($product, 'report_category') === null) {
            $failures[] = 'report_category must be set before activating the product';
        }

        if (! $this->hasApprovedRuleVersion($productId)) {
            $failures[] = 'at least one approved rule version is required';
        }

        if (! $this->hasEvidenceConfiguration($productId)) {
            $failures[] = 'at least one claim evidence requirement must be configured';
        }

        if ($agencyId !== null) {
            foreach ($this->requiredOperationCodes($product) as $operationCode) {
                if (! $this->hasActiveOperationMapping($operationCode, $agencyId, $currency)) {
                    $failures[] = 'active '.$operationCode.' accounting mapping is required';
                }
            }
        }

        return $failures;
    }

    private function partner(object $product): ?object
    {
        $partnerId = $this->rowNullableInt($product, 'insurance_partner_id');
        if ($partnerId === null) {
            return null;
        }

        $partner = DB::table('insurance_partners')
            ->where('id', $partnerId)
            ->where('status', 'active')
            ->first(['id', 'agency_id', 'ledger_account_id']);
        if (! is_object($partner)) {
            return null;
        }

        $partnerLedgerId = $this->rowNullableInt($partner, 'ledger_account_id');
        $agencyId = $this->rowNullableInt($partner, 'agency_id');
        if ($partnerLedgerId !== null && $agencyId !== null && ! $this->activeAgencyLedgerExists($partnerLedgerId, $agencyId)) {
            return null;
        }

        return $partner;
    }

    private function hasApprovedRuleVersion(int $productId): bool
    {
        return DB::table('insurance_product_rule_versions')
            ->where('insurance_product_id', $productId)
            ->where('status', 'approved')
            ->exists();
    }

    private function hasEvidenceConfiguration(int $productId): bool
    {
        return DB::table('insurance_claim_evidence_configs')
            ->where('insurance_product_id', $productId)
            ->exists();
    }

    /**
     * @return list<string>
     */
    private function requiredOperationCodes(object $product): array
    {
        $operationCodes = ['insurance_premium_collection', 'insurance_claim_settlement'];
        if ((bool) $this->rowValue($product, 'is_refundable')) {
            $operationCodes[] = 'insurance_premium_refund';
        }

        return $operationCodes;
    }

    private function hasActiveOperationMapping(string $operationCode, int $agencyId, string $currency): bool
    {
        if ($operationCode === 'insurance_premium_collection') {
            return $this->hasActiveCreditOperationMapping($operationCode, $agencyId, $currency);
        }

        return $this->hasActiveDebitCreditOperationMapping($operationCode, $agencyId, $currency);
    }

    private function hasActiveCreditOperationMapping(string $operationCode, int $agencyId, string $currency): bool
    {
        $mapping = DB::table('operation_account_mappings')
            ->join('operation_codes', 'operation_codes.id', '=', 'operation_account_mappings.operation_code_id')
            ->join('ledger_accounts as credit_ledgers', 'credit_ledgers.id', '=', 'operation_account_mappings.credit_ledger_account_id')
            ->where('operation_codes.code', $operationCode)
            ->where('operation_codes.module', 'insurance')
            ->where('operation_codes.status', 'active')
            ->where('operation_account_mappings.status', 'active')
            ->where('credit_ledgers.agency_id', $agencyId)
            ->where('credit_ledgers.status', LedgerAccount::STATUS_ACTIVE)
            ->where(function ($query) use ($currency): void {
                $query->whereNull('operation_account_mappings.currency')
                    ->orWhere('operation_account_mappings.currency', $currency);
            })
            ->first(['operation_account_mappings.credit_ledger_account_id']);

        return is_object($mapping);
    }

    private function hasActiveDebitCreditOperationMapping(string $operationCode, int $agencyId, string $currency): bool
    {
        $mapping = DB::table('operation_account_mappings')
            ->join('operation_codes', 'operation_codes.id', '=', 'operation_account_mappings.operation_code_id')
            ->join('ledger_accounts as debit_ledgers', 'debit_ledgers.id', '=', 'operation_account_mappings.debit_ledger_account_id')
            ->join('ledger_accounts as credit_ledgers', 'credit_ledgers.id', '=', 'operation_account_mappings.credit_ledger_account_id')
            ->where('operation_codes.code', $operationCode)
            ->where('operation_codes.module', 'insurance')
            ->where('operation_codes.status', 'active')
            ->where('operation_account_mappings.status', 'active')
            ->where('debit_ledgers.agency_id', $agencyId)
            ->where('debit_ledgers.status', LedgerAccount::STATUS_ACTIVE)
            ->where('credit_ledgers.agency_id', $agencyId)
            ->where('credit_ledgers.status', LedgerAccount::STATUS_ACTIVE)
            ->where(function ($query) use ($currency): void {
                $query->whereNull('operation_account_mappings.currency')
                    ->orWhere('operation_account_mappings.currency', $currency);
            })
            ->first(['operation_account_mappings.debit_ledger_account_id', 'operation_account_mappings.credit_ledger_account_id']);

        return is_object($mapping);
    }

    private function activeAgencyLedgerExists(int $ledgerAccountId, int $agencyId): bool
    {
        return DB::table('ledger_accounts')
            ->where('id', $ledgerAccountId)
            ->where('agency_id', $agencyId)
            ->where('status', LedgerAccount::STATUS_ACTIVE)
            ->exists();
    }

    private function rowString(object $row, string $key): string
    {
        $value = $this->rowValue($row, $key);

        return is_string($value) ? $value : (string) ($value ?? '');
    }

    private function rowNullableString(object $row, string $key): ?string
    {
        $value = $this->rowValue($row, $key);
        if ($value === null || $value === '') {
            return null;
        }

        return is_string($value) ? $value : (string) $value;
    }

    private function rowInt(object $row, string $key): int
    {
        $value = $this->rowValue($row, $key);

        return is_int($value) ? $value : (int) ($value ?? 0);
    }

    private function rowNullableInt(object $row, string $key): ?int
    {
        $value = $this->rowValue($row, $key);
        if ($value === null || $value === '') {
            return null;
        }

        return is_int($value) ? $value : (int) $value;
    }

    private function rowValue(object $row, string $key): string|int|float|bool|null
    {
        $value = get_object_vars($row)[$key] ?? null;
        if ($value === null || is_string($value) || is_int($value) || is_float($value) || is_bool($value)) {
            return $value;
        }

        return null;
    }
}
