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
use Spatie\Activitylog\Support\LogOptions;

/**
 * @property int $id
 * @property string $public_id
 * @property string $filename
 * @property string $disk
 * @property string $path
 * @property string $status
 * @property string $database_connection
 * @property string $database_driver
 * @property int|null $size_bytes
 * @property string|null $checksum_sha256
 * @property bool $encrypted
 * @property string|null $compression
 * @property string|null $verification_status
 * @property Carbon|null $verified_at
 * @property Carbon|null $started_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $expires_at
 * @property int|null $created_by_user_id
 * @property int|null $deleted_by_user_id
 * @property string|null $failure_reason
 * @property array<string, mixed>|null $metadata
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
#[Fillable([
    'public_id',
    'filename',
    'disk',
    'path',
    'status',
    'database_connection',
    'database_driver',
    'size_bytes',
    'checksum_sha256',
    'encrypted',
    'compression',
    'verification_status',
    'verified_at',
    'started_at',
    'completed_at',
    'expires_at',
    'created_by_user_id',
    'deleted_by_user_id',
    'failure_reason',
    'metadata',
])]
final class DatabaseBackup extends Model
{
    use HasAuditLog;
    use HasUlids;

    public const string STATUS_PENDING = 'pending';

    public const string STATUS_RUNNING = 'running';

    public const string STATUS_COMPLETED = 'completed';

    public const string STATUS_FAILED = 'failed';

    public const string STATUS_VERIFIED = 'verified';

    public const string STATUS_DELETED = 'deleted';

    public const string VERIFICATION_PASSED = 'passed';

    public const string VERIFICATION_FAILED = 'failed';

    /**
     * Statuses for which the artifact file is expected to exist on disk and is
     * eligible (subject to verification/size checks) for download or restore.
     *
     * @var array<int, string>
     */
    public const array DOWNLOADABLE_STATUSES = [
        self::STATUS_COMPLETED,
        self::STATUS_VERIFIED,
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
     * The raw storage path must never reach the audit trail (ADM-DB-002/011),
     * so it is excluded from the automatic model activity log.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logExcept(['path'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('databasebackup');
    }

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'encrypted' => 'boolean',
            'verified_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'expires_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function isDownloadable(): bool
    {
        return in_array($this->status, self::DOWNLOADABLE_STATUSES, true);
    }

    public function isDeleted(): bool
    {
        return $this->status === self::STATUS_DELETED;
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function deletedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deleted_by_user_id');
    }

    /** @return HasMany<DatabaseRestoreOperation, $this> */
    public function restoreOperations(): HasMany
    {
        return $this->hasMany(DatabaseRestoreOperation::class, 'database_backup_id');
    }
}
