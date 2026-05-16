<?php

declare(strict_types=1);

namespace App\Application\Loans;

use App\Models\Loan;
use App\Models\LoanScheduleSnapshot;
use App\Models\LoanStatusTransition;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class RescheduleLoan
{
    public function __construct(
        private readonly GenerateLoanSchedule $generateLoanSchedule,
    ) {}

    /**
     * @param  array<string, mixed>  $validated
     */
    public function handle(Loan $loan, User $actor, array $validated): LoanScheduleSnapshot
    {
        $capitalizedInterest = $this->integerValue($validated['capitalized_interest_minor'] ?? 0, 'capitalized_interest_minor');
        $capitalizedPenalties = $this->integerValue($validated['capitalized_penalties_minor'] ?? 0, 'capitalized_penalties_minor');
        if ($capitalizedInterest > 0 || $capitalizedPenalties > 0) {
            throw new InvalidArgumentException('Capitalizing interest or penalties requires a dedicated approved accounting workflow.');
        }

        return DB::transaction(function () use ($loan, $actor, $validated): LoanScheduleSnapshot {
            DB::table('loans')->where('id', $loan->id)->lockForUpdate()->first();

            $lockedLoan = Loan::query()->whereKey($loan->id)->firstOrFail();
            if (! in_array($lockedLoan->status, [Loan::STATUS_ACTIVE, Loan::STATUS_RESCHEDULED], true)) {
                throw new InvalidArgumentException('Only active or already rescheduled loans can be rescheduled.');
            }

            $fromStatus = $lockedLoan->status;
            $lockedLoan->forceFill([
                'status' => Loan::STATUS_RESCHEDULED,
                'first_installment_date' => $validated['first_installment_date'] ?? $lockedLoan->first_installment_date,
                'number_of_installments' => $validated['number_of_installments'] ?? $lockedLoan->number_of_installments,
                'grace_period_duration' => $validated['grace_period_duration'] ?? $lockedLoan->grace_period_duration,
                'total_loan_duration' => $validated['total_loan_duration'] ?? $lockedLoan->total_loan_duration,
            ])->save();

            if ($fromStatus !== Loan::STATUS_RESCHEDULED) {
                LoanStatusTransition::query()->create([
                    'public_id' => (string) Str::ulid(),
                    'loan_id' => $lockedLoan->id,
                    'agency_id' => $lockedLoan->agency_id,
                    'from_status' => $fromStatus,
                    'to_status' => Loan::STATUS_RESCHEDULED,
                    'actor_user_id' => $actor->id,
                    'decision' => 'transitioned',
                    'reason' => 'schedule_rescheduled',
                    'notes' => is_string($validated['reason'] ?? null) ? $validated['reason'] : null,
                    'transitioned_at' => now(),
                ]);
            }

            return $this->generateLoanSchedule->handle($lockedLoan->refresh(), $actor, replaceActive: true);
        });
    }

    private function integerValue(mixed $value, string $field): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) {
            return (int) $value;
        }

        throw new InvalidArgumentException($field.' must be an integer amount.');
    }
}
