<?php

declare(strict_types=1);

namespace App\Application\Loans;

use App\Models\Loan;
use App\Models\LoanGuaranteeObligation;
use App\Models\LoanRepayment;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class EarlyRepayLoan
{
    public function __construct(
        private readonly RecordLoanRepayment $recordLoanRepayment,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(Loan $loan, User $actor, string $customerAccountPublicId, int $amountMinor, ?string $paidOn = null, bool $directionInterestWaiver = false, ?int $directionNegotiatedTotalInterestMinor = null, ?string $notes = null, ?string $idempotencyKey = null): array
    {
        $paidDate = $paidOn ?? now()->toDateString();
        $waiverDate = $directionInterestWaiver ? $paidDate : null;

        return DB::transaction(function () use ($actor, $amountMinor, $customerAccountPublicId, $directionInterestWaiver, $directionNegotiatedTotalInterestMinor, $idempotencyKey, $loan, $notes, $paidDate, $waiverDate): array {
            DB::table('loans')->where('id', $loan->id)->lockForUpdate()->first();

            $lockedLoan = Loan::query()
                ->with(['loanProduct'])
                ->whereKey($loan->id)
                ->firstOrFail();

            if ($idempotencyKey !== null && $idempotencyKey !== '') {
                $existing = LoanRepayment::query()
                    ->with(['allocations.scheduleLine', 'customerAccount', 'postedBy', 'journalEntry.lines.ledgerAccount', 'journalEntry.lines.customerAccount'])
                    ->where('loan_id', $lockedLoan->id)
                    ->where('metadata->early_repayment_idempotency_key', $idempotencyKey)
                    ->first();
                if ($existing instanceof LoanRepayment && $existing->journalEntry !== null) {
                    $existingMetadata = $existing->getAttribute('metadata');
                    $existingMetadata = is_array($existingMetadata) ? $existingMetadata : [];

                    return [
                        'loan' => $lockedLoan->refresh(),
                        'repayment' => $existing,
                        'journal_entry' => $existing->journalEntry,
                        'payoff_amount_minor' => $existing->allocated_amount_minor,
                        'direction_interest_waiver' => ($existingMetadata['direction_interest_waiver'] ?? false) === true,
                        'direction_negotiated_total_interest_minor' => $existingMetadata['direction_negotiated_total_interest_minor'] ?? null,
                        'interest_concession_minor' => is_int($existingMetadata['interest_concession_minor'] ?? null) ? $existingMetadata['interest_concession_minor'] : 0,
                        'insurance_refunded_minor' => 0,
                        'early_repayment_fee_minor' => 0,
                        'released_guarantee_obligations_count' => 0,
                    ];
                }
            }

            if (! in_array($lockedLoan->status, [Loan::STATUS_DISBURSED, Loan::STATUS_ACTIVE, Loan::STATUS_RESCHEDULED], true)) {
                throw new InvalidArgumentException('Only disbursed, active, or rescheduled loans can be closed early.');
            }

            $this->enforceMinimumAge($lockedLoan, $paidDate);

            $interestConcession = $directionInterestWaiver
                ? 0
                : $this->interestConcession($lockedLoan, $paidDate, $directionNegotiatedTotalInterestMinor);
            $payoffAmount = $this->recordLoanRepayment->outstandingAmount($lockedLoan, $waiverDate, $interestConcession, $paidDate);
            if ($payoffAmount <= 0) {
                throw new InvalidArgumentException('Loan has no outstanding scheduled amount to repay.');
            }

            if ($amountMinor < $payoffAmount) {
                throw new InvalidArgumentException('Early repayment amount must cover the full payoff amount.');
            }

            $result = $this->recordLoanRepayment->handle(
                $lockedLoan,
                $actor,
                $amountMinor,
                $customerAccountPublicId,
                $paidDate,
                $notes ?? 'Early loan repayment',
                $waiverDate,
                $interestConcession,
                $paidDate,
            );

            /** @var LoanRepayment $repayment */
            $repayment = $result['repayment'];
            $metadata = $repayment->getAttribute('metadata');
            $metadata = is_array($metadata) ? $metadata : [];
            $metadata['early_repayment'] = true;
            $metadata['early_repayment_idempotency_key'] = $idempotencyKey;
            $metadata['direction_interest_waiver'] = $directionInterestWaiver;
            $metadata['direction_negotiated_total_interest_minor'] = $directionNegotiatedTotalInterestMinor;
            $metadata['interest_concession_minor'] = $interestConcession;
            $repayment->forceFill(['metadata' => $metadata])->save();
            $releasedGuarantees = $this->releaseGuarantees($lockedLoan, $actor);

            $lockedLoan->forceFill([
                'status' => Loan::STATUS_CLOSED,
                'closed_on' => $paidDate,
                'global_outstanding_amount_minor' => 0,
            ])->save();

            return array_merge($result, [
                'payoff_amount_minor' => $payoffAmount,
                'direction_interest_waiver' => $directionInterestWaiver,
                'direction_negotiated_total_interest_minor' => $directionNegotiatedTotalInterestMinor,
                'interest_concession_minor' => $interestConcession,
                'insurance_refunded_minor' => 0,
                'early_repayment_fee_minor' => 0,
                'released_guarantee_obligations_count' => $releasedGuarantees,
                'repayment' => $repayment->refresh()->loadMissing(['allocations.scheduleLine', 'customerAccount', 'postedBy']),
                'loan' => $lockedLoan->refresh(),
            ]);
        });
    }

    private function interestConcession(Loan $loan, string $paidDate, ?int $negotiatedTotalInterestMinor): int
    {
        if ($negotiatedTotalInterestMinor === null) {
            return 0;
        }

        if ($negotiatedTotalInterestMinor < 0) {
            throw new InvalidArgumentException('Negotiated total interest must be positive or zero.');
        }

        $paidInterest = $this->recordLoanRepayment->paidInterestAmount($loan);
        $openInterest = $this->recordLoanRepayment->openInterestAmount($loan);
        $futureInterest = $this->recordLoanRepayment->openFutureInterestAmount($loan, $paidDate);
        $contractualTotalInterest = $paidInterest + $openInterest;
        if ($negotiatedTotalInterestMinor >= $contractualTotalInterest) {
            return 0;
        }

        $requestedConcession = $contractualTotalInterest - $negotiatedTotalInterestMinor;

        return min($requestedConcession, $futureInterest);
    }

    private function enforceMinimumAge(Loan $loan, string $paidDate): void
    {
        $rules = is_array($loan->loanProduct?->getAttribute('rules')) ? $loan->loanProduct->getAttribute('rules') : [];
        $earlyRules = is_array($rules['early_repayment'] ?? null) ? $rules['early_repayment'] : [];
        $months = $earlyRules['minimum_months_after_disbursement'] ?? null;
        if (! is_int($months) || $months <= 0) {
            return;
        }

        $disbursedOn = $loan->getAttribute('disbursed_on');
        if (! $disbursedOn instanceof \DateTimeInterface) {
            return;
        }

        $minimumDate = Carbon::instance($disbursedOn)->addMonthsNoOverflow($months)->toDateString();
        if ($paidDate < $minimumDate) {
            throw new InvalidArgumentException('Early repayment is not allowed before the configured minimum period after disbursement.');
        }
    }

    private function releaseGuarantees(Loan $loan, User $actor): int
    {
        return LoanGuaranteeObligation::query()
            ->where('loan_id', $loan->id)
            ->where('status', LoanGuaranteeObligation::STATUS_ACTIVE)
            ->update([
                'status' => LoanGuaranteeObligation::STATUS_RELEASED,
                'released_at' => now(),
                'released_by_user_id' => $actor->id,
                'updated_at' => now(),
            ]);
    }
}
