<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'loan_schedule_snapshot_id',
    'installment_number',
    'due_date',
    'principal_minor',
    'interest_minor',
    'fees_minor',
    'insurance_minor',
    'tax_minor',
    'penalty_minor',
    'capitalized_interest_minor',
    'remaining_principal_minor',
    'total_installment_minor',
    'currency',
    'status',
])]
final class LoanScheduleLine extends Model
{
    public const string STATUS_SCHEDULED = 'scheduled';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'due_date' => 'date',
            'principal_minor' => 'integer',
            'interest_minor' => 'integer',
            'fees_minor' => 'integer',
            'insurance_minor' => 'integer',
            'tax_minor' => 'integer',
            'penalty_minor' => 'integer',
            'capitalized_interest_minor' => 'integer',
            'remaining_principal_minor' => 'integer',
            'total_installment_minor' => 'integer',
        ];
    }

    /** @return BelongsTo<LoanScheduleSnapshot, $this> */
    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(LoanScheduleSnapshot::class, 'loan_schedule_snapshot_id');
    }
}
