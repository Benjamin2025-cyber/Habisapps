<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Traits\HasAuditLog;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['public_id', 'code', 'name', 'status'])]
/**
 * @property int $id
 * @property string $public_id
 * @property string $code
 * @property string $name
 * @property string $status
 */
final class Sector extends Model
{
    use HasAuditLog;
    use HasUlids;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

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

    /** @return HasMany<SubSector, $this> */
    public function subSectors(): HasMany
    {
        return $this->hasMany(SubSector::class);
    }
}
