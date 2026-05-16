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
    'code',
    'name',
    'account_class',
    'parent_emf_regulatory_account_id',
    'status',
    'metadata',
])]
final class EmfRegulatoryAccount extends Model
{
    use HasAuditLog, HasUlids;

    public const string STATUS_ACTIVE = 'active';

    public const string STATUS_INACTIVE = 'inactive';

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
            'metadata' => 'array',
        ];
    }

    /** @return BelongsTo<EmfRegulatoryAccount, $this> */
    public function parentAccount(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_emf_regulatory_account_id');
    }

    /** @return HasMany<EmfRegulatoryAccount, $this> */
    public function childAccounts(): HasMany
    {
        return $this->hasMany(self::class, 'parent_emf_regulatory_account_id');
    }

    /** @return HasMany<EmfLedgerAccountMapping, $this> */
    public function ledgerMappings(): HasMany
    {
        return $this->hasMany(EmfLedgerAccountMapping::class);
    }
}
