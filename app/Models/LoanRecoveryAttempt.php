<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Traits\HasAuditLog;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'public_id',
    'loan_id',
    'loan_recovery_account_id',
    'customer_account_id',
    'batch_run_id',
    'requested_amount_minor',
    'recovered_amount_minor',
    'currency',
    'status',
    'attempted_at',
    'failure_reason',
    'teller_transaction_id',
    'journal_entry_id',
])]
final class LoanRecoveryAttempt extends Model
{
    use HasAuditLog, HasUlids;

    public const string STATUS_SUCCEEDED = 'succeeded';

    public const string STATUS_FAILED = 'failed';

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

    /** @return BelongsTo<Loan, $this> */
    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }

    /** @return BelongsTo<LoanRecoveryAccount, $this> */
    public function recoveryAccount(): BelongsTo
    {
        return $this->belongsTo(LoanRecoveryAccount::class, 'loan_recovery_account_id');
    }

    /** @return BelongsTo<CustomerAccount, $this> */
    public function customerAccount(): BelongsTo
    {
        return $this->belongsTo(CustomerAccount::class);
    }

    /** @return BelongsTo<JournalEntry, $this> */
    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    protected function casts(): array
    {
        return [
            'requested_amount_minor' => 'integer',
            'recovered_amount_minor' => 'integer',
            'attempted_at' => 'datetime',
        ];
    }
}
