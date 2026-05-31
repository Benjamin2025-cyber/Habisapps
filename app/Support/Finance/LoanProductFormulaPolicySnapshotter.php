<?php

declare(strict_types=1);

namespace App\Support\Finance;

use App\Models\Loan;
use App\Models\LoanProduct;

final class LoanProductFormulaPolicySnapshotter
{
    /**
     * @var array<string, FormulaPolicyKey>
     */
    private const TOP_LEVEL_POLICY_FIELDS = [
        'interest_policy_key' => FormulaPolicyKey::LoanInterestMethod,
        'fee_policy_key' => FormulaPolicyKey::FeesTaxesInsurance,
        'tax_policy_key' => FormulaPolicyKey::FeesTaxesInsurance,
        'insurance_policy_key' => FormulaPolicyKey::FeesTaxesInsurance,
        'guarantee_deposit_policy_key' => FormulaPolicyKey::FeesTaxesInsurance,
        'penalty_policy_key' => FormulaPolicyKey::PenaltiesAndArrears,
        'repayment_allocation_policy_key' => FormulaPolicyKey::RepaymentAllocationOrder,
    ];

    /**
     * @var array<string, FormulaPolicyKey>
     */
    private const RULE_POLICY_FIELDS = [
        'rounding_policy_key' => FormulaPolicyKey::XafRounding,
        'schedule_policy_key' => FormulaPolicyKey::LoanInstallmentAmount,
        'reporting_policy_key' => FormulaPolicyKey::PortfolioReportingMetrics,
    ];

    public function __construct(
        private readonly FormulaPolicyRegistry $registry,
    ) {}

    /**
     * The single source of truth mapping loan-product request fields to the
     * formula policy they configure. Shared by the snapshotter, the formula
     * policy catalog, and loan-product validation.
     *
     * @return array<string, FormulaPolicyKey>
     */
    public static function policyFieldMap(): array
    {
        return array_merge(self::TOP_LEVEL_POLICY_FIELDS, self::RULE_POLICY_FIELDS);
    }

    /**
     * @param  array<string, mixed>|LoanProduct  $source
     * @return array<string, array<int, string>>
     */
    public function approvalErrors(array|LoanProduct $source): array
    {
        $errors = [];

        foreach ($this->configuredPolicies($source) as $field => $policy) {
            if (! $this->registry->isApproved($policy)) {
                $errors[$field] = [sprintf('Formula policy [%s] is not approved.', $policy->value)];
            }
        }

        return $errors;
    }

    /**
     * @return array<string, mixed>
     */
    public function snapshot(LoanProduct $product): array
    {
        $errors = $this->approvalErrors($product);
        if ($errors !== []) {
            // Report the policy that actually failed approval, not just the
            // first configured policy. The error keys returned by
            // approvalErrors() map back to the configured policy fields.
            $configured = $this->configuredPolicies($product);
            $failingField = array_key_first($errors);
            $failingPolicy = $configured[$failingField] ?? FormulaPolicyKey::LoanInterestMethod;

            throw FormulaPolicyNotApproved::forPolicy($failingPolicy);
        }

        return [
            'loan_product_public_id' => $product->public_id,
            'loan_product_code' => $product->code,
            'formula_policies' => $this->configuredPolicySnapshot($product),
            'product_terms' => [
                'interest_rate' => $product->interest_rate,
                'tax_rate' => $product->tax_rate,
                'insurance_rate' => $product->insurance_rate,
                'fee_amount_minor' => $product->fee_amount_minor,
                'guarantee_deposit_type' => $product->guarantee_deposit_type,
                'guarantee_deposit_value' => $product->guarantee_deposit_value,
                'penalty_grace_days' => $product->penalty_grace_days,
                'penalty_formula_type' => $product->penalty_formula_type,
                'penalty_formula_base' => $product->penalty_formula_base,
                'penalty_value_type' => $product->penalty_value_type,
                'penalty_value' => $product->penalty_value,
                'rules' => $product->rules,
            ],
            'snapshotted_at' => now()->toISOString(),
        ];
    }

    public function applyToLoan(Loan $loan, LoanProduct $product): Loan
    {
        $loan->forceFill([
            'loan_product_id' => $product->id,
            'formula_policy_snapshot' => $this->snapshot($product),
        ]);

        return $loan;
    }

    /**
     * @param  array<string, mixed>|LoanProduct  $source
     * @return array<string, FormulaPolicyKey>
     */
    private function configuredPolicies(array|LoanProduct $source): array
    {
        $policies = [];

        foreach (self::TOP_LEVEL_POLICY_FIELDS as $field => $policy) {
            $value = $source instanceof LoanProduct ? $source->{$field} : ($source[$field] ?? null);
            if ($value !== null && $value !== '') {
                $policies[$field] = $policy;
            }
        }

        $rules = $source instanceof LoanProduct ? $source->rules : ($source['rules'] ?? []);
        $formulaPolicies = is_array($rules) ? ($rules['formula_policies'] ?? []) : [];

        if (! is_array($formulaPolicies)) {
            return $policies;
        }

        foreach (self::RULE_POLICY_FIELDS as $field => $policy) {
            $value = $formulaPolicies[$field] ?? null;
            if ($value !== null && $value !== '') {
                $policies['rules.formula_policies.'.$field] = $policy;
            }
        }

        return $policies;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function configuredPolicySnapshot(LoanProduct $product): array
    {
        $snapshot = [];

        foreach ($this->configuredPolicies($product) as $field => $policy) {
            $snapshot[$field] = [
                'policy_key' => $policy->value,
                'approved' => true,
                'owner' => config('formulas.policies.'.$policy->value.'.owner'),
                'approved_at' => config('formulas.policies.'.$policy->value.'.approved_at'),
            ];
        }

        return $snapshot;
    }
}
