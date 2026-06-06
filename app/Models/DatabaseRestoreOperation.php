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
 * @property int|null $database_backup_id
 * @property string $status
 * @property string $target
 * @property string $mode
 * @property int|null $planned_by_user_id
 * @property int|null $executed_by_user_id
 * @property Carbon|null $started_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $expires_at
 * @property string|null $confirmation_method
 * @property int|null $pre_restore_backup_id
 * @property string|null $failure_reason
 * @property array<string, mixed>|null $metadata
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
#[Fillable([
    'public_id',
    'database_backup_id',
    'status',
    'target',
    'mode',
    'planned_by_user_id',
    'executed_by_user_id',
    'started_at',
    'completed_at',
    'expires_at',
    'confirmation_method',
    'pre_restore_backup_id',
    'failure_reason',
    'metadata',
])]
final class DatabaseRestoreOperation extends Model
{
    use HasAuditLog;
    use HasUlids;

    public const string STATUS_PLANNED = 'planned';

    public const string STATUS_PENDING = 'pending';

    public const string STATUS_RUNNING = 'running';

    public const string STATUS_COMPLETED = 'completed';

    public const string STATUS_FAILED = 'failed';

    public const string STATUS_CANCELLED = 'cancelled';

    public const string TARGET_SAME_DATABASE = 'same_database';

    public const string TARGET_STAGING_DATABASE = 'staging_database';

    public const string TARGET_EXTERNAL_DATABASE = 'external_database';

    public const string MODE_DRY_RUN = 'dry_run';

    public const string MODE_REPLACE = 'replace';

    public const string MODE_VERIFY_ONLY = 'verify_only';

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

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'expires_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isActive(): bool
    {
        return in_array($this->status, [self::STATUS_PENDING, self::STATUS_RUNNING], true);
    }

    /** @return BelongsTo<DatabaseBackup, $this> */
    public function backup(): BelongsTo
    {
        return $this->belongsTo(DatabaseBackup::class, 'database_backup_id');
    }

    /** @return BelongsTo<DatabaseBackup, $this> */
    public function preRestoreBackup(): BelongsTo
    {
        return $this->belongsTo(DatabaseBackup::class, 'pre_restore_backup_id');
    }

    /** @return BelongsTo<User, $this> */
    public function plannedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'planned_by_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function executedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'executed_by_user_id');
    }
}
