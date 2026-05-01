<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Traits\HasAuditLog;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'public_id',
    'agency_id',
    'client_id',
    'proxy_full_name',
    'proxy_phone_number',
    'proxy_email',
    'proxy_id_document_type',
    'proxy_id_document_number',
    'mandate_type',
    'starts_on',
    'ends_on',
    'status',
    'verification_status',
    'submitted_at',
    'verified_at',
    'verified_by_user_id',
    'rejected_at',
    'rejection_reason',
    'document_id',
    'created_by_user_id',
    'archived_at',
])]
final class ClientProxy extends Model
{
    use HasAuditLog, HasUlids;

    public const string STATUS_ACTIVE = 'active';

    public const string STATUS_INACTIVE = 'inactive';

    public const string STATUS_ARCHIVED = 'archived';

    public const string STATUS_EXPIRED = 'expired';

    public const string VERIFICATION_PENDING = 'pending';

    public const string VERIFICATION_PENDING_REVIEW = 'pending_review';

    public const string VERIFICATION_VERIFIED = 'verified';

    public const string VERIFICATION_REJECTED = 'rejected';

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
            'starts_on' => 'date',
            'ends_on' => 'date',
            'submitted_at' => 'datetime',
            'verified_at' => 'datetime',
            'rejected_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Client, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /** @return BelongsTo<Document, $this> */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /** @return BelongsTo<User, $this> */
    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
