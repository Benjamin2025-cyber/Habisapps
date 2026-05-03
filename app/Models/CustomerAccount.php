<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Traits\HasAuditLog;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

#[Fillable([
    'public_id',
    'client_id',
    'agency_id',
    'ledger_account_id',
    'account_number',
    'account_type',
    'opened_on',
    'closed_on',
    'status',
])]
/**
 * @property int $id
 * @property string $public_id
 * @property int $client_id
 * @property int $agency_id
 * @property int|null $ledger_account_id
 * @property string $account_number
 * @property string|null $account_type
 * @property Carbon|null $opened_on
 * @property Carbon|null $closed_on
 * @property string $status
 */
final class CustomerAccount extends Model
{
    use HasAuditLog;
    use HasUlids;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_SUSPENDED = 'suspended';

    public const STATUS_CLOSED = 'closed';

    public const STATUS_ARCHIVED = 'archived';

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
            'opened_on' => 'date',
            'closed_on' => 'date',
        ];
    }

    /** @return BelongsTo<Client, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
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

    /** @return HasMany<AccountHold, $this> */
    public function holds(): HasMany
    {
        return $this->hasMany(AccountHold::class);
    }
}
