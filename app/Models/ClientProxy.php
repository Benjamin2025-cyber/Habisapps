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
 * @property int $agency_id
 * @property int $client_id
 * @property int|null $customer_account_id
 * @property string $proxy_full_name
 * @property string|null $proxy_phone_number
 * @property string|null $proxy_email
 * @property string|null $proxy_id_document_type
 * @property string|null $proxy_id_document_number
 * @property string $mandate_type
 * @property array<int, string>|null $operation_types
 * @property int|null $max_amount_minor
 * @property string|null $limit_currency
 * @property Carbon|null $starts_on
 * @property Carbon|null $ends_on
 * @property string $status
 * @property string $verification_status
 * @property Carbon|null $submitted_at
 * @property Carbon|null $verified_at
 * @property int|null $verified_by_user_id
 * @property Carbon|null $rejected_at
 * @property string|null $rejection_reason
 * @property int|null $document_id
 * @property int|null $back_document_id
 * @property int|null $created_by_user_id
 * @property Carbon|null $archived_at
 */
#[Fillable([
    'public_id',
    'agency_id',
    'client_id',
    'customer_account_id',
    'proxy_full_name',
    'proxy_phone_number',
    'proxy_email',
    'proxy_id_document_type',
    'proxy_id_document_number',
    'mandate_type',
    'operation_types',
    'max_amount_minor',
    'limit_currency',
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
    'back_document_id',
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
            'operation_types' => 'array',
            'max_amount_minor' => 'integer',
            'submitted_at' => 'datetime',
            'verified_at' => 'datetime',
            'rejected_at' => 'datetime',
            'archived_at' => 'datetime',
            'proxy_id_document_number' => 'encrypted',
        ];
    }

    /** @return BelongsTo<Client, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /** @return BelongsTo<CustomerAccount, $this> */
    public function customerAccount(): BelongsTo
    {
        return $this->belongsTo(CustomerAccount::class);
    }

    /** @return BelongsTo<Document, $this> */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /** @return BelongsTo<Document, $this> */
    public function backDocument(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'back_document_id');
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
