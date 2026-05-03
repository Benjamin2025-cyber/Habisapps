<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Staff\StaffAgencyScope;
use App\Support\Traits\HasAuditLog;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

/**
 * @property int $id
 * @property string $public_id
 * @property string $phone_number
 * @property string|null $email
 * @property string|null $password
 * @property string $status
 * @property int|null $agency_id
 * @property Carbon|null $phone_verified_at
 * @property Carbon|null $activated_at
 * @property Carbon|null $last_login_at
 */
#[Fillable([
    'name',
    'phone_number',
    'email',
    'password',
    'status',
    'matricule',
    'job_title',
    'agency_id',
    'agency_code',
    'agency_name',
    'invited_by_user_id',
    'phone_verified_at',
    'activated_at',
    'last_login_at',
])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasAuditLog, HasFactory, HasRoles, HasUlids, Notifiable;

    public const string STATUS_PENDING_VERIFICATION = 'pending_verification';

    public const string STATUS_ACTIVE = 'active';

    public const string STATUS_SUSPENDED = 'suspended';

    public const string STATUS_DEACTIVATED = 'deactivated';

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
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'phone_verified_at' => 'datetime',
            'activated_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * @return Attribute<string, string>
     */
    protected function name(): Attribute
    {
        return Attribute::make(
            set: static fn (string $value): string => self::sanitizeName($value),
        );
    }

    private static function sanitizeName(string $value): string
    {
        $withoutExecutableBlocks = preg_replace('/<(script|style)\b[^>]*>.*?<\/\1>/is', '', $value);

        return trim(strip_tags($withoutExecutableBlocks ?? $value));
    }

    /**
     * @return Attribute<string, string>
     */
    protected function phoneNumber(): Attribute
    {
        return Attribute::make(
            set: static fn (string $value): string => preg_replace('/\s+/', '', $value) ?? $value,
        );
    }

    /**
     * @return BelongsTo<Agency, $this>
     */
    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }

    /**
     * @return HasMany<StaffAgencyAssignment, $this>
     */
    public function agencyAssignments(): HasMany
    {
        return $this->hasMany(StaffAgencyAssignment::class);
    }

    public function currentAgencyId(): ?int
    {
        return app(StaffAgencyScope::class)->currentAgencyId($this);
    }
}
