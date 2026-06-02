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
    'agency_id',
    'debit_ledger_account_id',
    'credit_ledger_account_id',
    'currency',
    'effective_from',
    'effective_to',
    'status',
    'approval_status',
    'accounting_owner_user_id',
    'sharia_approval_required',
    'sharia_approval_status',
    'approved_by_user_id',
    'approved_at',
    'rules',
])]
final class OperationAccountMapping extends Model
{
    use HasAuditLog, HasUlids;

    public const string STATUS_ACTIVE = 'active';

    public const string STATUS_INACTIVE = 'inactive';

    public const string STATUS_ARCHIVED = 'archived';

    public const string APPROVAL_DRAFT = 'draft';

    public const string APPROVAL_SUBMITTED = 'submitted';

    public const string APPROVAL_APPROVED = 'approved';

    public const string APPROVAL_REJECTED = 'rejected';

    public const string APPROVAL_SUSPENDED = 'suspended';

    public const string APPROVAL_REVOKED = 'revoked';

    public const string APPROVAL_EXPIRED = 'expired';

    public const string APPROVAL_ARCHIVED = 'archived';

    /**
     * @var array<int, string>
     */
    public const array APPROVAL_STATUSES = [
        self::APPROVAL_DRAFT,
        self::APPROVAL_SUBMITTED,
        self::APPROVAL_APPROVED,
        self::APPROVAL_REJECTED,
        self::APPROVAL_SUSPENDED,
        self::APPROVAL_REVOKED,
        self::APPROVAL_EXPIRED,
        self::APPROVAL_ARCHIVED,
    ];

    public const string SHARIA_NOT_REQUIRED = 'not_required';

    public const string SHARIA_PENDING = 'pending';

    public const string SHARIA_APPROVED = 'approved';

    public const string SHARIA_REJECTED = 'rejected';

    /**
     * @var array<int, string>
     */
    public const array SHARIA_APPROVAL_STATUSES = [
        self::SHARIA_NOT_REQUIRED,
        self::SHARIA_PENDING,
        self::SHARIA_APPROVED,
        self::SHARIA_REJECTED,
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

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'rules' => 'array',
            'effective_from' => 'date',
            'effective_to' => 'date',
            'approved_at' => 'datetime',
            'sharia_approval_required' => 'boolean',
        ];
    }

    /** @return BelongsTo<OperationCode, $this> */
    public function operationCode(): BelongsTo
    {
        return $this->belongsTo(OperationCode::class);
    }

    /** @return BelongsTo<Agency, $this> */
    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
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
