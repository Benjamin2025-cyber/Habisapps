<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Traits\HasAuditLog;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'public_id',
    'agency_id',
    'client_id',
    'loan_id',
    'collateral_type',
    'description',
    'owner_full_name',
    'status',
    'valuation_date',
    'declared_value_minor',
    'currency',
    'document_id',
])]
final class Collateral extends Model
{
    use HasAuditLog, HasUlids;

    public const string TYPE_REAL_ESTATE = 'real_estate';

    public const string TYPE_MOVABLE = 'movable';

    public const string TYPE_PERSONAL_GUARANTEE = 'personal_guarantee';

    public const string STATUS_ACTIVE = 'active';

    public const string STATUS_RELEASED = 'released';

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

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'valuation_date' => 'date',
            'declared_value_minor' => 'integer',
        ];
    }

    /** @return BelongsTo<Client, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /** @return BelongsTo<Loan, $this> */
    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }

    /** @return BelongsTo<Agency, $this> */
    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }

    /** @return BelongsTo<Document, $this> */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /** @return HasMany<CollateralItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(CollateralItem::class);
    }
}
