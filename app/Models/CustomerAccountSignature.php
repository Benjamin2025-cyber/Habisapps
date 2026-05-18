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
 * @property int $customer_account_id
 * @property int $client_id
 * @property int $document_id
 * @property int|null $client_proxy_id
 * @property string $signature_type
 * @property string|null $signer_name
 * @property string|null $signer_role
 * @property string $status
 * @property Carbon|null $captured_on
 * @property Carbon|null $verified_at
 * @property int|null $verified_by_user_id
 * @property Carbon|null $revoked_at
 * @property int|null $revoked_by_user_id
 * @property string|null $revocation_reason
 * @property array<string, mixed>|null $metadata
 */
#[Fillable([
    'public_id',
    'agency_id',
    'customer_account_id',
    'client_id',
    'document_id',
    'client_proxy_id',
    'signature_type',
    'signer_name',
    'signer_role',
    'status',
    'captured_on',
    'verified_at',
    'verified_by_user_id',
    'revoked_at',
    'revoked_by_user_id',
    'revocation_reason',
    'metadata',
])]
final class CustomerAccountSignature extends Model
{
    use HasAuditLog;
    use HasUlids;

    public const string TYPE_PRIMARY_HOLDER = 'primary_holder';

    public const string TYPE_JOINT_HOLDER = 'joint_holder';

    public const string TYPE_PROXY = 'proxy';

    public const string TYPE_MANDATE = 'mandate';

    public const string TYPE_THUMBPRINT = 'thumbprint';

    public const string STATUS_ACTIVE = 'active';

    public const string STATUS_SUPERSEDED = 'superseded';

    public const string STATUS_REVOKED = 'revoked';

    public const string STATUS_ARCHIVED = 'archived';

    /** @return array<int, string> */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'captured_on' => 'date',
            'verified_at' => 'datetime',
            'revoked_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    /** @return BelongsTo<Agency, $this> */
    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }

    /** @return BelongsTo<CustomerAccount, $this> */
    public function customerAccount(): BelongsTo
    {
        return $this->belongsTo(CustomerAccount::class);
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

    /** @return BelongsTo<ClientProxy, $this> */
    public function clientProxy(): BelongsTo
    {
        return $this->belongsTo(ClientProxy::class);
    }

    /** @return BelongsTo<User, $this> */
    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function revokedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by_user_id');
    }
}
