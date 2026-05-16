<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'public_id',
    'collateral_id',
    'quantity',
    'description',
    'reference',
    'chassis_number',
    'registration_number',
    'amount_minor',
    'currency',
    'metadata',
])]
final class CollateralItem extends Model
{
    use HasUlids;

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
            'quantity' => 'integer',
            'amount_minor' => 'integer',
            'metadata' => 'array',
        ];
    }

    /** @return BelongsTo<Collateral, $this> */
    public function collateral(): BelongsTo
    {
        return $this->belongsTo(Collateral::class);
    }
}
