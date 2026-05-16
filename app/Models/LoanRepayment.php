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
    'agency_id',
    'loan_id',
    'journal_entry_id',
    'customer_account_id',
    'received_amount_minor',
    'allocated_amount_minor',
    'overpayment_retained_minor',
    'currency',
    'paid_on',
    'status',
    'posted_at',
    'posted_by_user_id',
    'idempotency_key',
    'metadata',
])]
final class LoanRepayment extends Model
{
    use HasAuditLog, HasUlids;

    public const string STATUS_POSTED = 'posted';

    public const string STATUS_REVERSED = 'reversed';

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
            'received_amount_minor' => 'integer',
            'allocated_amount_minor' => 'integer',
            'overpayment_retained_minor' => 'integer',
            'paid_on' => 'date',
            'posted_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    /** @return BelongsTo<Loan, $this> */
    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }

    /** @return BelongsTo<JournalEntry, $this> */
    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    /** @return BelongsTo<CustomerAccount, $this> */
    public function customerAccount(): BelongsTo
    {
        return $this->belongsTo(CustomerAccount::class);
    }

    /** @return BelongsTo<User, $this> */
    public function postedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by_user_id');
    }

    /** @return HasMany<LoanRepaymentAllocation, $this> */
    public function allocations(): HasMany
    {
        return $this->hasMany(LoanRepaymentAllocation::class);
    }
}
