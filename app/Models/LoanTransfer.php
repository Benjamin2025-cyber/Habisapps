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
    'initial_manager_id',
    'new_manager_id',
    'transfer_reason',
    'transfer_date',
    'approved_by_user_id',
])]
final class LoanTransfer extends Model
{
    use HasAuditLog, HasUlids;

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

    /** @return BelongsTo<Agency, $this> */
    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }

    /** @return BelongsTo<Loan, $this> */
    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }

    /** @return BelongsTo<User, $this> */
    public function initialManager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initial_manager_id');
    }

    /** @return BelongsTo<User, $this> */
    public function newManager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'new_manager_id');
    }

    /** @return BelongsTo<User, $this> */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    protected function casts(): array
    {
        return [
            'transfer_date' => 'date',
        ];
    }
}
