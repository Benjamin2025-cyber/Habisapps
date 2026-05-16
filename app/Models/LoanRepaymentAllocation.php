<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'loan_repayment_id',
    'loan_schedule_line_id',
    'component',
    'amount_minor',
    'currency',
])]
final class LoanRepaymentAllocation extends Model
{
    public const string COMPONENT_PRINCIPAL = 'principal';

    public const string COMPONENT_INTEREST = 'interest';

    public const string COMPONENT_FEES = 'fees';

    public const string COMPONENT_INSURANCE = 'insurance';

    public const string COMPONENT_TAX = 'tax';

    public const string COMPONENT_PENALTY = 'penalty';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
        ];
    }

    /** @return BelongsTo<LoanRepayment, $this> */
    public function repayment(): BelongsTo
    {
        return $this->belongsTo(LoanRepayment::class, 'loan_repayment_id');
    }

    /** @return BelongsTo<LoanScheduleLine, $this> */
    public function scheduleLine(): BelongsTo
    {
        return $this->belongsTo(LoanScheduleLine::class, 'loan_schedule_line_id');
    }
}
