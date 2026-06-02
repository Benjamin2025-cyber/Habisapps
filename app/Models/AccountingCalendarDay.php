<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Traits\HasAuditLog;
use Database\Factories\AccountingCalendarDayFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

#[Fillable([
    'public_id',
    'scope_type',
    'agency_id',
    'calendar_date',
    'business_date',
    'is_business_day',
    'is_holiday',
    'holiday_name',
    'notes',
    'created_by_user_id',
    'approved_by_user_id',
    'approved_at',
])]
/**
 * @property int $id
 * @property string $public_id
 * @property string $scope_type
 * @property int|null $agency_id
 * @property Carbon $calendar_date
 * @property Carbon|null $business_date
 * @property bool $is_business_day
 * @property bool $is_holiday
 * @property string|null $holiday_name
 * @property string|null $notes
 */
final class AccountingCalendarDay extends Model
{
    use HasAuditLog;

    /** @use HasFactory<AccountingCalendarDayFactory> */
    use HasFactory;

    use HasUlids;

    public const string SCOPE_AGENCY = 'agency';

    public const string SCOPE_INSTITUTION = 'institution';

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
            'calendar_date' => 'date',
            'business_date' => 'date',
            'is_business_day' => 'boolean',
            'is_holiday' => 'boolean',
            'approved_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Agency, $this> */
    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }
}
