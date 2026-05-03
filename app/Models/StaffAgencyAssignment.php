<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property int $agency_id
 * @property string $role_at_agency
 * @property Carbon $starts_on
 * @property Carbon|null $ends_on
 * @property bool $is_primary
 * @property string $status
 */
#[Fillable([
    'public_id',
    'user_id',
    'agency_id',
    'role_at_agency',
    'starts_on',
    'ends_on',
    'is_primary',
    'status',
])]
final class StaffAgencyAssignment extends Model
{
    use HasUlids;

    public const string STATUS_ACTIVE = 'active';

    public const string STATUS_ENDED = 'ended';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
            'is_primary' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Agency, $this>
     */
    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
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

    /**
     * @return Attribute<string, string>
     */
    protected function roleAtAgency(): Attribute
    {
        return Attribute::make(
            set: static fn (string $value): string => str($value)->lower()->kebab()->toString(),
        );
    }
}
