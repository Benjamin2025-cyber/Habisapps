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

/**
 * @property int $id
 * @property string $public_id
 * @property int $agency_id
 * @property int|null $prospector_id
 * @property int|null $collection_agent_id
 * @property string $client_reference
 * @property string $first_name
 * @property string $last_name
 * @property string|null $middle_name
 * @property string|null $status
 * @property string $kyc_status
 * @property Carbon|null $kyc_submitted_at
 * @property Carbon|null $kyc_verified_at
 * @property Carbon|null $kyc_rejected_at
 * @property Carbon|null $kyc_suspended_at
 * @property Carbon|null $kyc_archived_at
 */
#[Fillable([
    'public_id',
    'agency_id',
    'prospector_id',
    'collection_agent_id',
    'client_reference',
    'first_name',
    'last_name',
    'middle_name',
    'date_of_birth',
    'place_of_birth',
    'gender',
    'phone_number',
    'email',
    'address_line_1',
    'address_line_2',
    'city',
    'region',
    'occupation',
    'employer_name',
    'collection_type',
    'collection_frequency',
    'collection_target_amount',
    'status',
    'kyc_status',
    'onboarded_on',
    'kyc_submitted_at',
    'kyc_verified_at',
    'kyc_verified_by_user_id',
    'kyc_rejected_at',
    'kyc_rejection_reason',
    'kyc_suspended_at',
    'kyc_archived_at',
])]
final class Client extends Model
{
    use HasAuditLog, HasUlids;

    public const string STATUS_ACTIVE = 'active';

    public const string STATUS_INACTIVE = 'inactive';

    public const string STATUS_SUSPENDED = 'suspended';

    public const string STATUS_ARCHIVED = 'archived';

    public const string KYC_STATUS_DRAFT = 'draft';

    public const string KYC_STATUS_PENDING_REVIEW = 'pending_review';

    public const string KYC_STATUS_VERIFIED = 'verified';

    public const string KYC_STATUS_REJECTED = 'rejected';

    public const string KYC_STATUS_SUSPENDED = 'suspended';

    public const string KYC_STATUS_ARCHIVED = 'archived';

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
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
            'onboarded_on' => 'date',
            'kyc_submitted_at' => 'datetime',
            'kyc_verified_at' => 'datetime',
            'kyc_rejected_at' => 'datetime',
            'kyc_suspended_at' => 'datetime',
            'kyc_archived_at' => 'datetime',
            'collection_target_amount' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<Agency, $this> */
    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }

    /** @return BelongsTo<User, $this> */
    public function prospector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'prospector_id');
    }

    /** @return BelongsTo<User, $this> */
    public function collectionAgent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'collection_agent_id');
    }

    /** @return BelongsTo<User, $this> */
    public function kycVerifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'kyc_verified_by_user_id');
    }

    /** @return HasMany<ClientIdentityDocument, $this> */
    public function identityDocuments(): HasMany
    {
        return $this->hasMany(ClientIdentityDocument::class);
    }

    /** @return HasMany<ClientGuarantor, $this> */
    public function guarantors(): HasMany
    {
        return $this->hasMany(ClientGuarantor::class);
    }

    /** @return HasMany<ClientProxy, $this> */
    public function proxies(): HasMany
    {
        return $this->hasMany(ClientProxy::class);
    }

    /** @return HasMany<ClientKycReview, $this> */
    public function kycReviews(): HasMany
    {
        return $this->hasMany(ClientKycReview::class);
    }
}
