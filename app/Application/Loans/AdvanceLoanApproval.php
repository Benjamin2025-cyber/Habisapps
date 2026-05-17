<?php

declare(strict_types=1);

namespace App\Application\Loans;

use App\Models\Loan;
use App\Models\LoanApproval;
use App\Models\LoanStatusTransition;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class AdvanceLoanApproval
{
    /**
     * @return array<int, string>
     */
    public static function steps(): array
    {
        return [
            LoanApproval::STEP_MONTAGE,
            LoanApproval::STEP_COMPTABILITE,
            LoanApproval::STEP_CONTROLE,
            LoanApproval::STEP_DIRECTION,
        ];
    }

    /**
     * @return array{loan: Loan, approval: LoanApproval}
     */
    public function handle(Loan $loan, User $actor, string $step, string $decision, ?string $comments = null): array
    {
        if (! in_array($step, self::steps(), true)) {
            throw new InvalidArgumentException('Invalid loan approval step.');
        }

        if (! in_array($decision, [LoanApproval::DECISION_APPROVED, LoanApproval::DECISION_REJECTED, LoanApproval::DECISION_RETURNED], true)) {
            throw new InvalidArgumentException('Invalid loan approval decision.');
        }

        return DB::transaction(function () use ($loan, $actor, $step, $decision, $comments): array {
            DB::table('loans')->where('id', $loan->id)->lockForUpdate()->first();
            $lockedLoan = Loan::query()->whereKey($loan->id)->firstOrFail();
            if (! in_array($lockedLoan->status, [Loan::STATUS_APPLICATION, Loan::STATUS_IN_REVIEW], true)) {
                throw new InvalidArgumentException('Only application or in-review loans can move through approval steps.');
            }

            $this->ensurePreviousStepsApproved($lockedLoan, $step);
            $this->ensureStepNotFinal($lockedLoan, $step);
            if ($decision === LoanApproval::DECISION_APPROVED) {
                $this->ensureActorNotAlreadyApprovedAnotherStep($lockedLoan, $actor, $step);
            }

            $approval = LoanApproval::query()->updateOrCreate(
                ['loan_id' => $lockedLoan->id, 'step' => $step],
                [
                    'public_id' => (string) Str::ulid(),
                    'agency_id' => $lockedLoan->agency_id,
                    'decision' => $decision,
                    'acted_by_user_id' => $actor->id,
                    'acted_at' => now(),
                    'comments' => $comments,
                ]
            );

            $fromStatus = $lockedLoan->status;
            $toStatus = $this->targetStatus($lockedLoan, $step, $decision);
            if ($fromStatus !== $toStatus) {
                $snapshotPayload = [
                    'status' => $toStatus,
                    'approved_on' => $toStatus === Loan::STATUS_APPROVED ? now()->toDateString() : $lockedLoan->approved_on,
                ];
                if ($toStatus === Loan::STATUS_APPROVED && $lockedLoan->applied_interest_rate === null) {
                    $product = $lockedLoan->loadMissing('loanProduct')->loanProduct;
                    if ($product !== null) {
                        $snapshotPayload['applied_interest_rate'] = $product->interest_rate;
                        $snapshotPayload['applied_tax_rate'] = $product->tax_rate ?? null;
                    }
                }
                $lockedLoan->forceFill($snapshotPayload)->save();

                LoanStatusTransition::query()->create([
                    'public_id' => (string) Str::ulid(),
                    'loan_id' => $lockedLoan->id,
                    'agency_id' => $lockedLoan->agency_id,
                    'from_status' => $fromStatus,
                    'to_status' => $toStatus,
                    'actor_user_id' => $actor->id,
                    'decision' => $decision,
                    'reason' => 'approval_'.$step,
                    'notes' => $comments,
                    'transitioned_at' => now(),
                ]);
            }

            return [
                'loan' => $lockedLoan->refresh(),
                'approval' => $approval->refresh(),
            ];
        });
    }

    private function ensurePreviousStepsApproved(Loan $loan, string $step): void
    {
        $stepIndex = array_search($step, self::steps(), true);
        if (! is_int($stepIndex)) {
            throw new InvalidArgumentException('Invalid loan approval step.');
        }

        $requiredPrevious = array_slice(self::steps(), 0, $stepIndex);
        if ($requiredPrevious === []) {
            return;
        }

        $approved = DB::table('loan_approvals')
            ->where('loan_id', $loan->id)
            ->whereIn('step', $requiredPrevious)
            ->where('decision', LoanApproval::DECISION_APPROVED)
            ->pluck('step')
            ->all();

        foreach ($requiredPrevious as $previousStep) {
            if (! in_array($previousStep, $approved, true)) {
                throw new InvalidArgumentException('Previous approval steps must be approved before this step.');
            }
        }
    }

    private function ensureStepNotFinal(Loan $loan, string $step): void
    {
        $existing = LoanApproval::query()
            ->where('loan_id', $loan->id)
            ->where('step', $step)
            ->first(['decision']);

        if ($existing instanceof LoanApproval && in_array($existing->decision, [LoanApproval::DECISION_APPROVED, LoanApproval::DECISION_REJECTED], true)) {
            throw new InvalidArgumentException('This approval step is already final.');
        }
    }

    private function ensureActorNotAlreadyApprovedAnotherStep(Loan $loan, User $actor, string $step): void
    {
        $hasPriorApproval = DB::table('loan_approvals')
            ->where('loan_id', $loan->id)
            ->where('decision', LoanApproval::DECISION_APPROVED)
            ->where('acted_by_user_id', $actor->id)
            ->where('step', '!=', $step)
            ->exists();

        if ($hasPriorApproval) {
            throw new InvalidArgumentException('Separation of duties requires a different approver for each approval stage.');
        }
    }

    private function targetStatus(Loan $loan, string $step, string $decision): string
    {
        return match ($decision) {
            LoanApproval::DECISION_REJECTED => Loan::STATUS_REJECTED,
            LoanApproval::DECISION_RETURNED => Loan::STATUS_APPLICATION,
            LoanApproval::DECISION_APPROVED => $step === LoanApproval::STEP_DIRECTION ? Loan::STATUS_APPROVED : Loan::STATUS_IN_REVIEW,
            default => $loan->status,
        };
    }
}
