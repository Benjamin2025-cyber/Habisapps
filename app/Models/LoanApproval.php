<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'public_id',
    'loan_id',
    'agency_id',
    'step',
    'decision',
    'acted_by_user_id',
    'acted_at',
    'comments',
])]
final class LoanApproval extends Model
{
    use HasUlids;

    public const string STEP_MONTAGE = 'montage';

    public const string STEP_COMPTABILITE = 'comptabilite';

    public const string STEP_CONTROLE = 'controle';

    public const string STEP_DIRECTION = 'direction';

    public const string DECISION_PENDING = 'pending';

    public const string DECISION_APPROVED = 'approved';

    public const string DECISION_REJECTED = 'rejected';

    public const string DECISION_RETURNED = 'returned';

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
            'acted_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Loan, $this> */
    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }

    /** @return BelongsTo<User, $this> */
    public function actedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acted_by_user_id');
    }
}
