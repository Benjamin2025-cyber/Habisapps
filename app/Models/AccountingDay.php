<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Traits\HasAuditLog;
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
    'business_date',
    'calendar_opened_at',
    'calendar_closed_at',
    'status',
    'is_holiday',
    'holiday_name',
    'opened_by_user_id',
    'closed_by_user_id',
    'reopened_by_user_id',
    'opening_batch_run_id',
    'closing_batch_run_id',
    'close_summary_payload',
    'close_failure_reason',
    'reopen_reason',
    'origin',
    'write_lock_version',
])]
/**
 * @property int $id
 * @property string $public_id
 * @property string $scope_type
 * @property int|null $agency_id
 * @property Carbon $business_date
 * @property Carbon|null $calendar_opened_at
 * @property Carbon|null $calendar_closed_at
 * @property string $status
 * @property bool $is_holiday
 * @property string|null $holiday_name
 * @property array<string, mixed>|null $close_summary_payload
 * @property string|null $close_failure_reason
 * @property string|null $reopen_reason
 * @property string $origin
 * @property int $write_lock_version
 */
final class AccountingDay extends Model
{
    use HasAuditLog;
    use HasFactory;
    use HasUlids;

    public const string SCOPE_AGENCY = 'agency';

    public const string SCOPE_INSTITUTION = 'institution';

    public const string STATUS_PLANNED = 'planned';

    public const string STATUS_OPEN = 'open';

    public const string STATUS_CLOSING = 'closing';

    public const string STATUS_CLOSED = 'closed';

    public const string STATUS_REOPENED = 'reopened';

    public const string STATUS_CANCELLED = 'cancelled';

    public const string ORIGIN_MANUAL = 'manual';

    public const string ORIGIN_MIGRATION = 'migration';

    /**
     * Statuses during which ordinary registration writes are permitted.
     *
     * @var array<int, string>
     */
    public const array REGISTRABLE_STATUSES = [
        self::STATUS_OPEN,
        self::STATUS_REOPENED,
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
            'business_date' => 'date',
            'calendar_opened_at' => 'datetime',
            'calendar_closed_at' => 'datetime',
            'is_holiday' => 'boolean',
            'close_summary_payload' => 'array',
            'write_lock_version' => 'integer',
        ];
    }

    /**
     * Whether ordinary registration writes can be accepted against this day.
     */
    public function allowsRegistration(): bool
    {
        return in_array($this->status, self::REGISTRABLE_STATUSES, true);
    }

    public function isClosed(): bool
    {
        return $this->status === self::STATUS_CLOSED;
    }

    public function isClosing(): bool
    {
        return $this->status === self::STATUS_CLOSING;
    }

    /** @return BelongsTo<Agency, $this> */
    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }

    /** @return BelongsTo<User, $this> */
    public function openedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function reopenedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reopened_by_user_id');
    }

    /** @return BelongsTo<BatchRun, $this> */
    public function openingBatchRun(): BelongsTo
    {
        return $this->belongsTo(BatchRun::class, 'opening_batch_run_id');
    }

    /** @return BelongsTo<BatchRun, $this> */
    public function closingBatchRun(): BelongsTo
    {
        return $this->belongsTo(BatchRun::class, 'closing_batch_run_id');
    }
}
