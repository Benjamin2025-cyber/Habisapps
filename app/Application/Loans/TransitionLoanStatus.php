<?php

declare(strict_types=1);

namespace App\Application\Loans;

use App\Models\Loan;
use App\Models\LoanStatusTransition;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class TransitionLoanStatus
{
    /**
     * @return array<string, array<int, string>>
     */
    public static function allowedTransitions(): array
    {
        return [
            Loan::STATUS_APPLICATION => [Loan::STATUS_IN_REVIEW, Loan::STATUS_REJECTED],
            Loan::STATUS_IN_REVIEW => [Loan::STATUS_APPLICATION, Loan::STATUS_REJECTED],
            Loan::STATUS_APPROVED => [Loan::STATUS_DISBURSED, Loan::STATUS_REJECTED],
            Loan::STATUS_DISBURSED => [Loan::STATUS_ACTIVE],
            Loan::STATUS_ACTIVE => [Loan::STATUS_RESCHEDULED, Loan::STATUS_CLOSED, Loan::STATUS_WRITTEN_OFF],
            Loan::STATUS_RESCHEDULED => [Loan::STATUS_ACTIVE, Loan::STATUS_CLOSED, Loan::STATUS_WRITTEN_OFF],
            Loan::STATUS_CLOSED => [],
            Loan::STATUS_WRITTEN_OFF => [],
            Loan::STATUS_REJECTED => [],
        ];
    }

    public function handle(Loan $loan, User $actor, string $toStatus, ?string $reason = null, ?string $notes = null): LoanStatusTransition
    {
        return DB::transaction(function () use ($loan, $actor, $toStatus, $reason, $notes): LoanStatusTransition {
            DB::table('loans')->where('id', $loan->id)->lockForUpdate()->first();
            $lockedLoan = Loan::query()->whereKey($loan->id)->firstOrFail();
            $fromStatus = $lockedLoan->status;
            $allowed = self::allowedTransitions()[$fromStatus] ?? [];

            if (! in_array($toStatus, $allowed, true)) {
                throw new InvalidArgumentException(__('loans.status_invalid_transition', ['from' => $fromStatus, 'to' => $toStatus]));
            }

            $lockedLoan->forceFill([
                'status' => $toStatus,
                'disbursed_on' => $toStatus === Loan::STATUS_DISBURSED ? now()->toDateString() : $lockedLoan->disbursed_on,
                'closed_on' => in_array($toStatus, [Loan::STATUS_CLOSED, Loan::STATUS_WRITTEN_OFF], true) ? now()->toDateString() : $lockedLoan->closed_on,
            ])->save();

            return LoanStatusTransition::query()->create([
                'public_id' => (string) Str::ulid(),
                'loan_id' => $lockedLoan->id,
                'agency_id' => $lockedLoan->agency_id,
                'from_status' => $fromStatus,
                'to_status' => $toStatus,
                'actor_user_id' => $actor->id,
                'decision' => 'transitioned',
                'reason' => $reason,
                'notes' => $notes,
                'transitioned_at' => now(),
            ]);
        });
    }
}
