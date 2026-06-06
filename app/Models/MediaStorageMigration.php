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
 * @property string $public_id
 * @property string $source_disk
 * @property string $target_disk
 * @property string $status
 * @property bool $dry_run
 * @property int $total_candidates
 * @property int $processed_count
 * @property int $migrated_count
 * @property int $failed_count
 * @property int $total_bytes
 * @property Carbon|null $started_at
 * @property Carbon|null $completed_at
 * @property int|null $requested_by_user_id
 * @property string|null $failure_summary
 * @property array<string, mixed>|null $metadata
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'public_id',
    'source_disk',
    'target_disk',
    'status',
    'dry_run',
    'total_candidates',
    'processed_count',
    'migrated_count',
    'failed_count',
    'total_bytes',
    'started_at',
    'completed_at',
    'requested_by_user_id',
    'failure_summary',
    'metadata',
])]
final class MediaStorageMigration extends Model
{
    use HasAuditLog, HasUlids;

    public const string STATUS_PENDING = 'pending';

    public const string STATUS_RUNNING = 'running';

    public const string STATUS_COMPLETED = 'completed';

    public const string STATUS_FAILED = 'failed';

    public const string STATUS_CANCELLED = 'cancelled';

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
     * @return BelongsTo<User, $this>
     */
    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'dry_run' => 'boolean',
            'metadata' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }
}
