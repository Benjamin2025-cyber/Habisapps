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
    'loan_id',
    'client_guarantor_id',
    'obligation_type',
    'obligation_amount_minor',
    'obligation_percentage',
    'currency',
    'status',
    'starts_on',
    'ends_on',
    'release_condition',
    'released_at',
    'released_by_user_id',
    'document_id',
    'guarantor_identity_snapshot',
])]
final class LoanGuaranteeObligation extends Model
{
    use HasAuditLog, HasUlids;

    public const string STATUS_ACTIVE = 'active';

    public const string STATUS_RELEASED = 'released';

    public const string STATUS_CANCELLED = 'cancelled';

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
            'obligation_amount_minor' => 'integer',
            'obligation_percentage' => 'decimal:6',
            'starts_on' => 'date',
            'ends_on' => 'date',
            'released_at' => 'datetime',
            'guarantor_identity_snapshot' => 'array',
        ];
    }

    /** @return BelongsTo<Agency, $this> */
    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }

    /** @return BelongsTo<ClientGuarantor, $this> */
    public function clientGuarantor(): BelongsTo
    {
        return $this->belongsTo(ClientGuarantor::class);
    }

    /** @return BelongsTo<Loan, $this> */
    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }

    /** @return BelongsTo<Document, $this> */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /** @return BelongsTo<User, $this> */
    public function releasedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'released_by_user_id');
    }
}
