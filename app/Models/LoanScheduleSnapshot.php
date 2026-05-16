<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'public_id',
    'loan_id',
    'formula_engine_key',
    'formula_engine_version',
    'policy_snapshot_hash',
    'generated_by_user_id',
    'generated_at',
    'status',
])]
final class LoanScheduleSnapshot extends Model
{
    use HasUlids;

    public const string STATUS_ACTIVE = 'active';

    public const string STATUS_SUPERSEDED = 'superseded';

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
            'generated_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Loan, $this> */
    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }

    /** @return HasMany<LoanScheduleLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(LoanScheduleLine::class);
    }
}
