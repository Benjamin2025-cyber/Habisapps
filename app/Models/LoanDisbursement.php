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
    'agency_id',
    'loan_id',
    'journal_entry_id',
    'transfer_account_id',
    'disbursement_channel',
    'principal_amount_minor',
    'currency',
    'status',
    'posted_at',
    'posted_by_user_id',
    'idempotency_key',
    'metadata',
])]
final class LoanDisbursement extends Model
{
    use HasAuditLog, HasUlids;

    public const string CHANNEL_TRANSFER_ACCOUNT = 'transfer_account';

    public const string CHANNEL_CASH = 'cash';

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
            'principal_amount_minor' => 'integer',
            'posted_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    /** @return BelongsTo<Agency, $this> */
    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
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
    public function transferAccount(): BelongsTo
    {
        return $this->belongsTo(CustomerAccount::class, 'transfer_account_id');
    }

    /** @return BelongsTo<User, $this> */
    public function postedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by_user_id');
    }
}
