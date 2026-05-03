<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Traits\HasAuditLog;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $public_id
 * @property int $agency_id
 * @property int $journal_entry_id
 * @property int $ledger_account_id
 * @property int|null $customer_account_id
 * @property int|null $loan_id
 * @property int $debit_minor
 * @property int $credit_minor
 * @property string $currency
 * @property string|null $line_memo
 */
final class JournalLine extends Model
{
    use HasAuditLog;
    use HasUlids;

    protected $fillable = [
        'public_id',
        'agency_id',
        'journal_entry_id',
        'ledger_account_id',
        'customer_account_id',
        'loan_id',
        'debit_minor',
        'credit_minor',
        'currency',
        'line_memo',
    ];

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

    /** @return BelongsTo<JournalEntry, $this> */
    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    /** @return BelongsTo<LedgerAccount, $this> */
    public function ledgerAccount(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class);
    }

    /** @return BelongsTo<CustomerAccount, $this> */
    public function customerAccount(): BelongsTo
    {
        return $this->belongsTo(CustomerAccount::class);
    }
}
