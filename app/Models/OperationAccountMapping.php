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
    'operation_code_id',
    'debit_ledger_account_id',
    'credit_ledger_account_id',
    'currency',
    'status',
    'rules',
])]
final class OperationAccountMapping extends Model
{
    use HasAuditLog, HasUlids;

    public const string STATUS_ACTIVE = 'active';

    public const string STATUS_INACTIVE = 'inactive';

    public const string STATUS_ARCHIVED = 'archived';

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
            'rules' => 'array',
        ];
    }

    /** @return BelongsTo<OperationCode, $this> */
    public function operationCode(): BelongsTo
    {
        return $this->belongsTo(OperationCode::class);
    }

    /** @return BelongsTo<LedgerAccount, $this> */
    public function debitLedgerAccount(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class, 'debit_ledger_account_id');
    }

    /** @return BelongsTo<LedgerAccount, $this> */
    public function creditLedgerAccount(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class, 'credit_ledger_account_id');
    }
}
