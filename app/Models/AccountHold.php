<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Traits\HasAuditLog;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

#[Fillable([
    'public_id',
    'customer_account_id',
    'amount_minor',
    'currency',
    'reason_type',
    'source_type',
    'source_public_id',
    'status',
    'placed_at',
    'expires_at',
    'placed_by_user_id',
    'released_at',
    'released_by_user_id',
    'release_reason',
    'reference',
])]
/**
 * @property int $id
 * @property string $public_id
 * @property int $customer_account_id
 * @property int $amount_minor
 * @property string $currency
 * @property string $reason_type
 * @property string $status
 * @property Carbon|null $placed_at
 * @property Carbon|null $released_at
 */
final class AccountHold extends Model
{
    use HasAuditLog;
    use HasUlids;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_RELEASED = 'released';

    public const STATUS_CANCELLED = 'cancelled';

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
            'placed_at' => 'datetime',
            'expires_at' => 'datetime',
            'released_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<CustomerAccount, $this> */
    public function customerAccount(): BelongsTo
    {
        return $this->belongsTo(CustomerAccount::class);
    }
}
