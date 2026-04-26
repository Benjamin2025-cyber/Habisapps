<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property string $purpose
 * @property string $phone_number
 * @property string $code_hash
 * @property int $attempts
 * @property int $max_attempts
 * @property Carbon $expires_at
 * @property Carbon|null $used_at
 * @property Carbon|null $last_sent_at
 */
#[Fillable([
    'user_id',
    'purpose',
    'phone_number',
    'code_hash',
    'expires_at',
    'used_at',
    'attempts',
    'max_attempts',
    'last_sent_at',
    'resend_count',
    'created_ip',
    'created_user_agent',
])]
final class OtpChallenge extends Model
{
    public const string PURPOSE_ACTIVATION = 'activation';

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
            'last_sent_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<OtpDelivery, $this> */
    public function deliveries(): HasMany
    {
        return $this->hasMany(OtpDelivery::class);
    }
}
