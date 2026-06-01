<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Traits\HasAuditLog;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $public_id
 * @property int $agency_id
 * @property string $code
 * @property string $name
 * @property string $type
 * @property string $status
 * @property string $daily_state
 * @property int|null $opening_balance_minor
 * @property int|null $last_closing_balance_minor
 * @property Carbon|null $last_closing_at
 * @property bool $requires_denominations
 * @property string|null $nature
 * @property bool $is_central_till
 * @property int|null $max_balance_limit_minor
 * @property int|null $max_withdrawal_limit_minor
 * @property string $currency
 * @property int|null $assigned_user_id
 * @property int|null $ledger_account_id
 */
#[Fillable([
    'public_id',
    'agency_id',
    'code',
    'name',
    'type',
    'status',
    'daily_state',
    'opening_balance_minor',
    'last_closing_balance_minor',
    'last_closing_at',
    'requires_denominations',
    'nature',
    'is_central_till',
    'max_balance_limit_minor',
    'max_withdrawal_limit_minor',
    'currency',
    'assigned_user_id',
    'ledger_account_id',
])]
final class Till extends Model
{
    use HasAuditLog;
    use HasUlids;

    public const string STATUS_ACTIVE = 'active';

    public const string STATUS_INACTIVE = 'inactive';

    public const string DAILY_STATE_OPEN = 'open';

    public const string DAILY_STATE_CLOSED = 'closed';

    public const string TYPE_COUNTER = 'counter';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_closing_at' => 'datetime',
            'requires_denominations' => 'boolean',
            'is_central_till' => 'boolean',
        ];
    }

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

    /** @return BelongsTo<User, $this> */
    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    /** @return BelongsTo<LedgerAccount, $this> */
    public function ledgerAccount(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class);
    }
}
