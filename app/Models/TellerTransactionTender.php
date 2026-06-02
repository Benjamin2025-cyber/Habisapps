<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'public_id',
    'teller_transaction_id',
    'method',
    'amount_minor',
    'currency',
    'status',
    'channel',
    'external_reference',
    'cheque_number',
    'cheque_bank_name',
    'cheque_issue_date',
    'debit_ledger_account_id',
    'credit_ledger_account_id',
    'ledger_mapping_evidence',
    'denomination_counts',
])]
final class TellerTransactionTender extends Model
{
    use HasUlids;

    public const string METHOD_CASH = 'cash';

    public const string METHOD_CHEQUE = 'cheque';

    public const string METHOD_TRANSFER = 'transfer';

    public const string STATUS_POSTED = 'posted';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'cheque_issue_date' => 'date',
            'ledger_mapping_evidence' => 'array',
            'denomination_counts' => 'array',
        ];
    }

    /**
     * @return array<int, string>
     */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    /** @return BelongsTo<TellerTransaction, $this> */
    public function tellerTransaction(): BelongsTo
    {
        return $this->belongsTo(TellerTransaction::class);
    }
}
