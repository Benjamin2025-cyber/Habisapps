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

/**
 * @property int $id
 * @property string $public_id
 * @property int $teller_session_id
 * @property int|null $counted_by_user_id
 * @property Carbon|null $counted_at
 * @property Carbon|null $reconciliation_date
 * @property int|null $theoretical_balance_minor
 * @property int|null $actual_balance_minor
 * @property int|null $difference_minor
 * @property string|null $currency
 * @property string $status
 * @property string|null $notes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'public_id',
    'teller_session_id',
    'counted_by_user_id',
    'counted_at',
    'reconciliation_date',
    'theoretical_balance_minor',
    'actual_balance_minor',
    'difference_minor',
    'currency',
    'status',
    'notes',
])]
final class TillReconciliation extends Model
{
    use HasAuditLog;
    use HasUlids;

    public const string STATUS_BALANCED = 'balanced';

    public const string STATUS_DRAFT = 'draft';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'counted_at' => 'datetime',
            'reconciliation_date' => 'datetime',
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

    /** @return BelongsTo<TellerSession, $this> */
    public function tellerSession(): BelongsTo
    {
        return $this->belongsTo(TellerSession::class);
    }

    /** @return BelongsTo<User, $this> */
    public function countedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'counted_by_user_id');
    }

    /** @return HasMany<TillReconciliationLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(TillReconciliationLine::class);
    }
}
