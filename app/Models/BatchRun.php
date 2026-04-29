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
 * @property int $batch_procedure_id
 * @property int|null $agency_id
 * @property string $business_date
 * @property string $status
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property int|null $operator_user_id
 * @property string|null $actor_context
 * @property string|null $scope_hash
 * @property string|null $idempotency_key
 * @property string|null $request_fingerprint
 * @property array<string, mixed>|null $summary_payload
 * @property string|null $failure_reason
 */
#[Fillable([
    'public_id',
    'batch_procedure_id',
    'agency_id',
    'business_date',
    'status',
    'started_at',
    'finished_at',
    'operator_user_id',
    'actor_context',
    'scope_hash',
    'idempotency_key',
    'request_fingerprint',
    'summary_payload',
    'failure_reason',
])]
final class BatchRun extends Model
{
    use HasAuditLog, HasUlids;

    public const string STATUS_PENDING = 'pending';

    public const string STATUS_RUNNING = 'running';

    public const string STATUS_SUCCEEDED = 'succeeded';

    public const string STATUS_FAILED = 'failed';

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

    /** @return BelongsTo<BatchProcedure, $this> */
    public function batchProcedure(): BelongsTo
    {
        return $this->belongsTo(BatchProcedure::class);
    }

    /** @return BelongsTo<Agency, $this> */
    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }

    /** @return BelongsTo<User, $this> */
    public function operator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'operator_user_id');
    }

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'summary_payload' => 'array',
        ];
    }
}
