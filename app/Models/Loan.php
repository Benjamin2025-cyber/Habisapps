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
    'client_id',
    'agency_id',
    'loan_product_id',
    'credit_agent_id',
    'amortization_account_id',
    'unpaid_account_id',
    'recovery_account_id',
    'transfer_account_id',
    'loan_number',
    'requested_amount_minor',
    'approved_principal_minor',
    'currency',
    'applied_on',
    'approved_on',
    'disbursed_on',
    'closed_on',
    'status',
    'processing_level',
    'purpose',
    'sector_id',
    'sub_sector_id',
    'financed_activity_code',
    'activity_address',
    'entrepreneur_address',
    'first_installment_date',
    'number_of_installments',
    'grace_period_duration',
    'tranche_duration',
    'total_loan_duration',
    'dossier_fees_minor',
    'dossier_fees_tax_minor',
    'guarantee_deposit_amount_minor',
    'insurance_amount_minor',
    'applied_interest_rate',
    'applied_tax_rate',
    'formula_policy_snapshot',
])]
final class Loan extends Model
{
    use HasAuditLog, HasUlids;

    public const string STATUS_APPLICATION = 'application';

    public const string STATUS_IN_REVIEW = 'in_review';

    public const string STATUS_APPROVED = 'approved';

    public const string STATUS_REJECTED = 'rejected';

    public const string STATUS_DISBURSED = 'disbursed';

    public const string STATUS_ACTIVE = 'active';

    public const string STATUS_RESCHEDULED = 'rescheduled';

    public const string STATUS_CLOSED = 'closed';

    public const string STATUS_WRITTEN_OFF = 'written_off';

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
            'applied_on' => 'date',
            'approved_on' => 'date',
            'disbursed_on' => 'date',
            'closed_on' => 'date',
            'first_installment_date' => 'date',
            'requested_amount_minor' => 'integer',
            'approved_principal_minor' => 'integer',
            'number_of_installments' => 'integer',
            'grace_period_duration' => 'integer',
            'tranche_duration' => 'integer',
            'total_loan_duration' => 'integer',
            'dossier_fees_minor' => 'integer',
            'dossier_fees_tax_minor' => 'integer',
            'guarantee_deposit_amount_minor' => 'integer',
            'insurance_amount_minor' => 'integer',
            'applied_interest_rate' => 'decimal:6',
            'applied_tax_rate' => 'decimal:6',
            'formula_policy_snapshot' => 'array',
        ];
    }

    /** @return BelongsTo<Client, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /** @return BelongsTo<Agency, $this> */
    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }

    /** @return BelongsTo<LoanProduct, $this> */
    public function loanProduct(): BelongsTo
    {
        return $this->belongsTo(LoanProduct::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creditAgent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'credit_agent_id');
    }

    /** @return BelongsTo<Sector, $this> */
    public function sector(): BelongsTo
    {
        return $this->belongsTo(Sector::class);
    }

    /** @return BelongsTo<SubSector, $this> */
    public function subSector(): BelongsTo
    {
        return $this->belongsTo(SubSector::class);
    }

    /** @return BelongsTo<CustomerAccount, $this> */
    public function amortizationAccount(): BelongsTo
    {
        return $this->belongsTo(CustomerAccount::class, 'amortization_account_id');
    }

    /** @return BelongsTo<CustomerAccount, $this> */
    public function unpaidAccount(): BelongsTo
    {
        return $this->belongsTo(CustomerAccount::class, 'unpaid_account_id');
    }

    /** @return BelongsTo<CustomerAccount, $this> */
    public function recoveryAccount(): BelongsTo
    {
        return $this->belongsTo(CustomerAccount::class, 'recovery_account_id');
    }

    /** @return BelongsTo<CustomerAccount, $this> */
    public function transferAccount(): BelongsTo
    {
        return $this->belongsTo(CustomerAccount::class, 'transfer_account_id');
    }

    /** @return HasMany<LoanStatusTransition, $this> */
    public function statusTransitions(): HasMany
    {
        return $this->hasMany(LoanStatusTransition::class);
    }

    /** @return HasMany<LoanApproval, $this> */
    public function approvals(): HasMany
    {
        return $this->hasMany(LoanApproval::class);
    }

    /** @return HasMany<LoanScheduleSnapshot, $this> */
    public function scheduleSnapshots(): HasMany
    {
        return $this->hasMany(LoanScheduleSnapshot::class);
    }

    /** @return HasMany<LoanGuaranteeObligation, $this> */
    public function guaranteeObligations(): HasMany
    {
        return $this->hasMany(LoanGuaranteeObligation::class);
    }

    /** @return HasMany<LoanDisbursement, $this> */
    public function disbursements(): HasMany
    {
        return $this->hasMany(LoanDisbursement::class);
    }

    /** @return HasMany<LoanRepayment, $this> */
    public function repayments(): HasMany
    {
        return $this->hasMany(LoanRepayment::class);
    }

    /** @return HasMany<DelinquencyTracking, $this> */
    public function delinquencyTrackings(): HasMany
    {
        return $this->hasMany(DelinquencyTracking::class);
    }

    /** @return HasMany<LoanTransfer, $this> */
    public function transfers(): HasMany
    {
        return $this->hasMany(LoanTransfer::class);
    }

    /** @return HasMany<LoanRecoveryAccount, $this> */
    public function recoveryAccounts(): HasMany
    {
        return $this->hasMany(LoanRecoveryAccount::class);
    }

    /** @return HasMany<LoanRecoveryAttempt, $this> */
    public function recoveryAttempts(): HasMany
    {
        return $this->hasMany(LoanRecoveryAttempt::class);
    }
}
