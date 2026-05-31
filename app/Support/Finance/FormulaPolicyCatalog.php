<?php

declare(strict_types=1);

namespace App\Support\Finance;

use Illuminate\Support\Str;

/**
 * Builds the discoverable catalog of formula policies for the loan-product UI
 * (FBI-020). Keys, approval state, owner, and approved_at come from the same
 * sources used at runtime (the FormulaPolicyKey enum, config/formulas.php, and
 * the snapshotter field map), so the catalog cannot drift from validation or
 * execution.
 */
final class FormulaPolicyCatalog
{
    /**
     * @return array<int, array{key: string, label: string, category: string, approved: bool, owner: string|null, approved_at: string|null, product_fields: array<int, string>}>
     */
    public static function all(): array
    {
        $fieldMap = LoanProductFormulaPolicySnapshotter::policyFieldMap();

        $catalog = [];
        foreach (FormulaPolicyKey::cases() as $policy) {
            $productFields = [];
            foreach ($fieldMap as $field => $mappedPolicy) {
                if ($mappedPolicy === $policy) {
                    $productFields[] = $field;
                }
            }

            $owner = config('formulas.policies.'.$policy->value.'.owner');
            $approvedAt = config('formulas.policies.'.$policy->value.'.approved_at');

            $catalog[] = [
                'key' => $policy->value,
                'label' => Str::headline($policy->value),
                'category' => self::category($policy),
                'approved' => config('formulas.policies.'.$policy->value.'.approved', false) === true,
                'owner' => is_string($owner) ? $owner : null,
                'approved_at' => is_string($approvedAt) ? $approvedAt : null,
                'product_fields' => $productFields,
            ];
        }

        return $catalog;
    }

    private static function category(FormulaPolicyKey $policy): string
    {
        return match ($policy) {
            FormulaPolicyKey::XafRounding => 'rounding',
            FormulaPolicyKey::LoanInterestMethod => 'interest',
            FormulaPolicyKey::LoanInstallmentAmount => 'schedule',
            FormulaPolicyKey::RepaymentAllocationOrder => 'repayment',
            FormulaPolicyKey::FeesTaxesInsurance => 'fees',
            FormulaPolicyKey::PenaltiesAndArrears => 'penalties',
            FormulaPolicyKey::AccountBalances => 'accounting',
            FormulaPolicyKey::CashTillReconciliation => 'cash',
            FormulaPolicyKey::PortfolioReportingMetrics => 'reporting',
        };
    }
}
