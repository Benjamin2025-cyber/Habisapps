<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Finance\FormulaPolicyKey;
use App\Support\Traits\HasAuditLog;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'public_id',
    'code',
    'name',
    'status',
    'min_term_count',
    'max_term_count',
    'term_unit',
    'allowed_repayment_frequencies',
    'requires_guarantor',
    'requires_collateral',
    'interest_policy_key',
    'penalty_policy_key',
    'repayment_allocation_policy_key',
    'fee_policy_key',
    'min_amount_minor',
    'max_amount_minor',
    'due_date_day',
    'penalty_grace_days',
    'min_grace_period_days',
    'max_grace_period_days',
    'interest_rate',
    'tax_rate',
    'insurance_rate',
    'fee_rate',
    'dossier_fee_tax_rate',
    'tax_policy_key',
    'insurance_policy_key',
    'guarantee_deposit_policy_key',
    'guarantee_deposit_type',
    'guarantee_deposit_value',
    'operation_type',
    'constant_value',
    'rules',
])]
final class LoanProduct extends Model
{
    use HasAuditLog, HasUlids;

    public const string STATUS_ACTIVE = 'active';

    public const string STATUS_INACTIVE = 'inactive';

    public const string STATUS_ARCHIVED = 'archived';

    public const string TERM_UNIT_DAY = 'day';

    public const string TERM_UNIT_WEEK = 'week';

    public const string TERM_UNIT_MONTH = 'month';

    public const string DEFAULT_DOSSIER_FEE_TAX_RATE = '19.25';

    /**
     * How a setup component is carried. `upfront` is the default and needs no
     * entry; the other two move the component out of the setup charges and into
     * the installment schedule.
     *
     * @var array<int, string>
     */
    public const array INSTALLMENT_CHARGE_POLICIES = ['upfront', 'financed', 'periodic'];

    /** @var array<string, string> */
    public const array DEFAULT_POLICY_ATTRIBUTES = [
        'interest_policy_key' => FormulaPolicyKey::LoanInterestMethod->value,
        'penalty_policy_key' => FormulaPolicyKey::PenaltiesAndArrears->value,
        'repayment_allocation_policy_key' => FormulaPolicyKey::RepaymentAllocationOrder->value,
        'fee_policy_key' => FormulaPolicyKey::FeesTaxesInsurance->value,
        'tax_policy_key' => FormulaPolicyKey::FeesTaxesInsurance->value,
        'insurance_policy_key' => FormulaPolicyKey::FeesTaxesInsurance->value,
        'guarantee_deposit_policy_key' => FormulaPolicyKey::FeesTaxesInsurance->value,
    ];

    /** @var array<string, array<string, string>> */
    public const array DEFAULT_FORMULA_POLICY_RULES = [
        'formula_policies' => [
            'rounding_policy_key' => FormulaPolicyKey::XafRounding->value,
            'schedule_policy_key' => FormulaPolicyKey::LoanInstallmentAmount->value,
            'reporting_policy_key' => FormulaPolicyKey::PortfolioReportingMetrics->value,
        ],
    ];

    /**
     * @return array<int, string>
     */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    /**
     * The policy set every product carries once saved — exactly what booted()
     * imposes. The approval gate has to be checked against this rather than
     * against the request, because the request no longer carries policy keys at
     * all: reading them off the payload would gate on an always-empty set.
     *
     * @return array<string, mixed>
     */
    public static function attachedPolicyAttributes(): array
    {
        return [
            ...self::DEFAULT_POLICY_ATTRIBUTES,
            'rules' => self::DEFAULT_FORMULA_POLICY_RULES,
        ];
    }

    /**
     * Every credit carries every calculation policy — the stakeholder's
     * « toutes les politiques là entrent dans les paramètres d'un crédit ».
     */
    protected static function booted(): void
    {
        // On every save, not just creation: `rules` is a JSON column that an
        // update replaces wholesale, so attaching the policy block at creation
        // only would let an unrelated `PATCH {"rules": {...}}` silently strip
        // the three rule-level policy keys off a live product.
        self::saving(function (self $product): void {
            foreach (self::DEFAULT_POLICY_ATTRIBUTES as $field => $value) {
                $product->setAttribute($field, $value);
            }

            $rules = $product->getAttribute('rules');
            $product->setAttribute('rules', array_replace_recursive(
                is_array($rules) ? $rules : [],
                self::DEFAULT_FORMULA_POLICY_RULES,
            ));
        });

        // Seed values, not invariants: both stay editable afterwards, so they
        // are applied once at creation and never re-imposed on an update.
        self::creating(function (self $product): void {
            if ($product->getAttribute('dossier_fee_tax_rate') === null) {
                $product->setAttribute('dossier_fee_tax_rate', self::DEFAULT_DOSSIER_FEE_TAX_RATE);
            }
        });
    }

    /**
     * Liberal in what it reads, strict in what the request layer accepts: a
     * legacy row may carry a boolean `true` where new writes must use one of
     * INSTALLMENT_CHARGE_POLICIES.
     */
    public function isInstallmentComponentFinanced(string $component): bool
    {
        $rules = $this->getAttribute('rules');
        $installmentCharges = is_array($rules) && is_array($rules['installment_charges'] ?? null)
            ? $rules['installment_charges']
            : [];
        $policy = $installmentCharges[$component] ?? null;

        return $policy === true || $policy === 'financed' || $policy === 'periodic';
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'allowed_repayment_frequencies' => 'array',
            'requires_guarantor' => 'boolean',
            'requires_collateral' => 'boolean',
            'min_amount_minor' => 'integer',
            'max_amount_minor' => 'integer',
            'rules' => 'array',
        ];
    }

    /** @return HasMany<Loan, $this> */
    public function loans(): HasMany
    {
        return $this->hasMany(Loan::class);
    }
}
