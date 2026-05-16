<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Traits\HasAuditLog;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $public_id
 * @property int|null $agency_id
 * @property string $code
 * @property string $name
 * @property string $account_class
 * @property string|null $account_type
 * @property int|null $parent_account_id
 * @property string $normal_balance_side
 * @property string $status
 */
#[Fillable([
    'public_id',
    'agency_id',
    'code',
    'name',
    'account_class',
    'account_type',
    'parent_account_id',
    'normal_balance_side',
    'status',
])]
final class LedgerAccount extends Model
{
    use HasAuditLog;
    use HasUlids;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    public const STATUS_SUSPENDED = 'suspended';

    public const STATUS_ARCHIVED = 'archived';

    public const ACCOUNT_CLASS_ASSET = 'asset';

    public const ACCOUNT_CLASS_LIABILITY = 'liability';

    public const ACCOUNT_CLASS_EQUITY = 'equity';

    public const ACCOUNT_CLASS_REVENUE = 'revenue';

    public const ACCOUNT_CLASS_EXPENSE = 'expense';

    public const NORMAL_BALANCE_DEBIT = 'debit';

    public const NORMAL_BALANCE_CREDIT = 'credit';

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

    /** @return BelongsTo<Agency, $this> */
    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }

    /** @return BelongsTo<self, $this> */
    public function parentAccount(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_account_id');
    }

    /** @return HasMany<self, $this> */
    public function childAccounts(): HasMany
    {
        return $this->hasMany(self::class, 'parent_account_id');
    }

    /** @return HasMany<EmfLedgerAccountMapping, $this> */
    public function emfMappings(): HasMany
    {
        return $this->hasMany(EmfLedgerAccountMapping::class);
    }
}
