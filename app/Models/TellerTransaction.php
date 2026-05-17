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
    'teller_session_id',
    'agency_id',
    'transaction_date',
    'till_id',
    'transaction_type',
    'client_id',
    'customer_account_id',
    'loan_id',
    'amount_minor',
    'currency',
    'status',
    'reference',
    'event_number',
    'idempotency_key',
    'journal_entry_id',
    'offset_ledger_account_id',
    'operation_code_id',
    'operation_code',
    'depositor_name',
    'depositor_address',
    'initiator_type',
    'initiator_proxy_id',
    'description',
    'reversal_of_teller_transaction_id',
])]
/**
 * @property int $id
 * @property string $public_id
 * @property int $teller_session_id
 * @property int $agency_id
 * @property string|null $transaction_date
 * @property int|null $till_id
 * @property string $transaction_type
 * @property int|null $client_id
 * @property int|null $customer_account_id
 * @property int|null $loan_id
 * @property int $amount_minor
 * @property string $currency
 * @property string $status
 * @property string $reference
 * @property string|null $event_number
 * @property string|null $idempotency_key
 * @property int|null $journal_entry_id
 * @property int|null $offset_ledger_account_id
 * @property string|null $operation_code
 * @property string|null $depositor_name
 * @property string|null $depositor_address
 * @property string|null $description
 */
final class TellerTransaction extends Model
{
    use HasAuditLog;
    use HasUlids;

    public const string TYPE_CASH_DEPOSIT = 'cash_deposit';

    public const string TYPE_CASH_WITHDRAWAL = 'cash_withdrawal';

    public const string TYPE_REVERSAL = 'cash_reversal';

    public const string TYPE_MANUAL_JOURNAL = 'cash_manual_journal';

    public const string STATUS_POSTED = 'posted';

    public const string STATUS_PENDING_REVIEW = 'pending_review';

    public const string STATUS_CANCELLED = 'cancelled';

    public const string STATUS_REVERSED = 'reversed';

    public const string INITIATOR_HOLDER = 'holder';

    public const string INITIATOR_PROXY = 'proxy';

    public const string INITIATOR_STAFF_ON_BEHALF = 'staff_on_behalf';

    public const string INITIATOR_SYSTEM = 'system';

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

    /** @return BelongsTo<TellerSession, $this> */
    public function tellerSession(): BelongsTo
    {
        return $this->belongsTo(TellerSession::class);
    }

    /** @return BelongsTo<Till, $this> */
    public function till(): BelongsTo
    {
        return $this->belongsTo(Till::class);
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

    /** @return BelongsTo<ClientProxy, $this> */
    public function initiatorProxy(): BelongsTo
    {
        return $this->belongsTo(ClientProxy::class, 'initiator_proxy_id');
    }
}
