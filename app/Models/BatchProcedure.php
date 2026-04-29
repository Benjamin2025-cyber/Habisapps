<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Traits\HasAuditLog;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $public_id
 * @property string $code
 * @property string $name
 * @property string|null $description
 * @property string|null $schedule_type
 * @property array<string, mixed>|null $schedule_metadata
 * @property string $status
 */
#[Fillable([
    'public_id',
    'code',
    'name',
    'description',
    'schedule_type',
    'schedule_metadata',
    'status',
])]
final class BatchProcedure extends Model
{
    use HasAuditLog, HasUlids;

    public const string STATUS_ACTIVE = 'active';

    public const string STATUS_INACTIVE = 'inactive';

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

    /** @return HasMany<BatchRun, $this> */
    public function runs(): HasMany
    {
        return $this->hasMany(BatchRun::class);
    }

    protected function casts(): array
    {
        return [
            'schedule_metadata' => 'array',
        ];
    }
}
