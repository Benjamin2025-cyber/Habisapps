<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'otp_challenge_id',
    'channel',
    'destination_hash',
    'destination_masked',
    'status',
    'provider_reference',
    'error_summary',
    'sent_at',
    'failed_at',
])]
final class OtpDelivery extends Model
{
    public const string CHANNEL_SMS = 'sms';

    public const string CHANNEL_EMAIL = 'email';

    public const string STATUS_SENT = 'sent';

    public const string STATUS_FAILED = 'failed';

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<OtpChallenge, $this> */
    public function challenge(): BelongsTo
    {
        return $this->belongsTo(OtpChallenge::class, 'otp_challenge_id');
    }
}
