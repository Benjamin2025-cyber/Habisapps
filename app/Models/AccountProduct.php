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
    'ledger_account_id',
    'code',
    'name',
    'account_family',
    'minimum_balance_minor',
    'currency',
    'allows_recovery_debit',
    'is_recovery_account',
    'is_ordinary_savings',
    'allows_overdraft',
    'overdraft_limit_minor',
    'status',
    'rules',
])]
final class AccountProduct extends Model
{
    use HasAuditLog, HasUlids;

    public const string FAMILY_SAVINGS = 'savings';

    public const string FAMILY_CURRENT = 'current';

    public const string FAMILY_RECOVERY = 'recovery';

    public const string FAMILY_ISLAMIC = 'islamic';

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
            'minimum_balance_minor' => 'integer',
            'allows_recovery_debit' => 'boolean',
            'is_recovery_account' => 'boolean',
            'is_ordinary_savings' => 'boolean',
            'allows_overdraft' => 'boolean',
            'overdraft_limit_minor' => 'integer',
            'rules' => 'array',
        ];
    }

    /** @return BelongsTo<Agency, $this> */
    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }

    /** @return BelongsTo<LedgerAccount, $this> */
    public function ledgerAccount(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class);
    }

    /** @return HasMany<CustomerAccount, $this> */
    public function customerAccounts(): HasMany
    {
        return $this->hasMany(CustomerAccount::class);
    }
}
