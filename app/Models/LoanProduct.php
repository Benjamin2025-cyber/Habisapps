<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Traits\HasAuditLog;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
    'ledger_account_id',
    'min_amount_minor',
    'max_amount_minor',
    'due_date_day',
    'penalty_grace_days',
    'min_grace_period_days',
    'max_grace_period_days',
    'interest_rate',
    'tax_rate',
    'insurance_rate',
    'fee_amount_minor',
    'floor_amount_minor',
    'tax_policy_key',
    'insurance_policy_key',
    'guarantee_deposit_policy_key',
    'guarantee_deposit_type',
    'guarantee_deposit_value',
    'penalty_formula_type',
    'penalty_formula_base',
    'penalty_value_type',
    'penalty_value',
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

    /**
     * Accepted descriptive values for the penalty configuration fields.
     * Authoritative penalty calculation is governed by the
     * `penalties_and_arrears` formula policy; these fields describe the
     * product's declared penalty shape and are validated against these enums
     * so typos cannot be persisted.
     *
     * @var array<int, string>
     */
    public const array PENALTY_FORMULA_TYPES = ['fixed', 'flat_rate', 'variable_rate', 'percentage'];

    /** @var array<int, string> */
    public const array PENALTY_FORMULA_BASES = ['principal', 'outstanding_principal', 'unpaid_scheduled_due', 'overdue_amount'];

    /** @var array<int, string> */
    public const array PENALTY_VALUE_TYPES = ['amount', 'percentage'];

    /**
     * @return array<int, string>
     */
    public function uniqueIds(): array
    {
        return ['public_id'];
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
            'fee_amount_minor' => 'integer',
            'floor_amount_minor' => 'integer',
            'rules' => 'array',
        ];
    }

    /** @return BelongsTo<LedgerAccount, $this> */
    public function ledgerAccount(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class);
    }

    /** @return HasMany<Loan, $this> */
    public function loans(): HasMany
    {
        return $this->hasMany(Loan::class);
    }
}
