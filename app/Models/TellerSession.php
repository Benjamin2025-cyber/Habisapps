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
 * @property int $till_id
 * @property int $agency_id
 * @property int $teller_user_id
 * @property Carbon $business_date
 * @property Carbon|null $opened_at
 * @property Carbon|null $closed_at
 * @property int|null $opening_declaration_minor
 * @property int|null $closing_declaration_minor
 * @property string|null $currency
 * @property string $status
 */
#[Fillable([
    'public_id',
    'till_id',
    'agency_id',
    'accounting_day_id',
    'teller_user_id',
    'business_date',
    'opened_at',
    'closed_at',
    'opening_declaration_minor',
    'closing_declaration_minor',
    'currency',
    'status',
])]
final class TellerSession extends Model
{
    use HasAuditLog;
    use HasUlids;

    public const string STATUS_OPEN = 'open';

    public const string STATUS_CLOSED = 'closed';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'business_date' => 'date',
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
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

    /** @return BelongsTo<Till, $this> */
    public function till(): BelongsTo
    {
        return $this->belongsTo(Till::class);
    }

    /** @return BelongsTo<Agency, $this> */
    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }

    /** @return BelongsTo<AccountingDay, $this> */
    public function accountingDay(): BelongsTo
    {
        return $this->belongsTo(AccountingDay::class);
    }

    /** @return BelongsTo<User, $this> */
    public function teller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'teller_user_id');
    }
}
