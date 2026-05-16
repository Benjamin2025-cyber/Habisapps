<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Traits\HasAuditLog;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $public_id
 * @property string $code
 * @property string $name
 * @property string|null $region
 * @property string|null $city
 * @property string|null $branch_name
 * @property string|null $branch_type
 * @property string|null $phone_number
 * @property string|null $fax_number
 * @property string|null $email
 * @property string|null $address_line_1
 * @property string|null $address_line_2
 * @property string|null $po_box
 * @property string|null $geographic_description
 * @property string|null $creation_date
 * @property string $status
 */
#[Fillable([
    'public_id',
    'code',
    'name',
    'region',
    'city',
    'branch_name',
    'branch_type',
    'phone_number',
    'fax_number',
    'email',
    'address_line_1',
    'address_line_2',
    'po_box',
    'geographic_description',
    'creation_date',
    'status',
    'manager_id',
])]
final class Agency extends Model
{
    use HasAuditLog, HasUlids;

    public const string STATUS_ACTIVE = 'active';

    public const string STATUS_INACTIVE = 'inactive';

    public const string STATUS_SUSPENDED = 'suspended';

    public const string STATUS_ARCHIVED = 'archived';

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

    /** @return BelongsTo<User, $this> */
    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_id');
    }

    /** @return HasMany<User, $this> */
    public function staff(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
