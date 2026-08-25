<?php

declare(strict_types=1);

namespace App\Application\Loans;

use App\Models\Loan;
use App\Models\LoanProduct;
use App\Models\LoanRepaymentAllocation;
use App\Models\LoanScheduleLine;
use App\Models\LoanScheduleSnapshot;
use App\Support\Finance\FormulaPolicyKey;
use App\Support\Finance\FormulaPolicyRegistry;
use App\Support\Finance\LoanPenaltyTermsResolver;
use App\Support\Finance\ResolvedPenaltyTerms;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class AssessLoanArrearsAndPenalties
{
    public function __construct(
        private readonly FormulaPolicyRegistry $formulaPolicyRegistry,
        private readonly LoanPenaltyTermsResolver $penaltyTermsResolver,
    ) {}

    /**
     * @return array{loan: Loan, assessed_penalty_minor:int, arrears: array<int, array<string, mixed>>}
     */
    public function handle(Loan $loan, string $asOfDate): array
    {
        $this->formulaPolicyRegistry->requireApproved(FormulaPolicyKey::PenaltiesAndArrears);

        $asOf = CarbonImmutable::parse($asOfDate)->startOfDay();

        return DB::transaction(function () use ($asOf, $loan): array {
            DB::table('loans')->where('id', $loan->id)->lockForUpdate()->first();

            $lockedLoan = Loan::query()
                ->with(['loanProduct'])
                ->whereKey($loan->id)
                ->firstOrFail();

            if (! in_array($lockedLoan->status, [Loan::STATUS_DISBURSED, Loan::STATUS_ACTIVE, Loan::STATUS_RESCHEDULED], true)) {
                throw new InvalidArgumentException('Only disbursed, active, or rescheduled loans can be assessed for arrears.');
            }

            $snapshot = LoanScheduleSnapshot::query()
                ->where('loan_id', $lockedLoan->id)
                ->where('status', LoanScheduleSnapshot::STATUS_ACTIVE)
                ->first();
            if (! $snapshot instanceof LoanScheduleSnapshot) {
                throw new InvalidArgumentException('An active repayment schedule is required before arrears assessment.');
            }

            $assessedPenaltyMinor = 0;
            $arrears = [];
            $snapshotProductTerms = $this->snapshotProductTerms($lockedLoan);
            $graceDays = $this->graceDays($lockedLoan, $snapshotProductTerms);
            $penaltyTerms = $this->penaltyTermsResolver->resolve();

            $lines = LoanScheduleLine::query()
                ->where('loan_schedule_snapshot_id', $snapshot->id)
                ->get()
                ->sortBy([
                    ['due_date', 'asc'],
                    ['installment_number', 'asc'],
                ])
                ->values();

            // The fixed part is a flat recovery fee, charged once per loan per
            // monthly assessment — not once per overdue installment. It pays for
            // the month's recovery action (letter, call, agent visit), of which
            // a delinquent loan generates one regardless of how many
            // installments are behind. Multiplying it by the arrears count would
            // bill a borrower six months late 30 000 FCFA of fixed fees in a
            // single run, which is neither what « une partie fixe 5.000 FCFA »
            // says nor something a COBAC-supervised EMF would defend.
            $fixedPartStillDue = ! $this->fixedPartChargedThisMonth($lockedLoan, $asOf);

            foreach ($lines as $line) {
                if (! $this->isPastGrace($line, $asOf, $graceDays)) {
                    continue;
                }

                $originalDue = $this->scheduledDueExcludingPenalties($line);
                if ($originalDue <= 0) {
                    continue;
                }

                $paid = $this->scheduledPaidExcludingPenalties($line);
                $unpaid = max(0, $originalDue - $paid);
                $penaltyBase = $unpaid >= $this->minimumUnpaidAmountMinor() ? $unpaid : null;
                $arrearsRow = $this->storeArrears($lockedLoan, $line, $originalDue, $paid, $unpaid, $penaltyBase);

                if ($unpaid === 0 || $penaltyBase === null || $this->alreadyPenalized($arrearsRow, $asOf)) {
                    $arrears[] = $this->arrearsPayload($arrearsRow);

                    continue;
                }

                $fixedPart = $fixedPartStillDue ? $penaltyTerms->fixedAmountMinor : 0;
                $penalty = $this->capPenalty(
                    $fixedPart + $this->variablePenaltyForLine($penaltyTerms, $line, $originalDue, $unpaid),
                    $unpaid,
                    $line->penalty_minor,
                );

                if ($penalty === 0) {
                    $arrears[] = $this->arrearsPayload($arrearsRow);

                    continue;
                }

                $fixedPartStillDue = $fixedPartStillDue && $fixedPart === 0;

                $line->forceFill([
                    'penalty_minor' => $line->penalty_minor + $penalty,
                    'total_installment_minor' => $this->lineTotal($line) + $penalty,
                ])->save();

                DB::table('loan_arrears')
                    ->where('id', $this->rowInt($arrearsRow, 'id'))
                    ->update([
                        // High-water mark, so a backdated re-run cannot reset it
                        // and re-open the loan to a second charge.
                        'last_penalized_at' => $this->laterOf($this->lastPenalizedAt($arrearsRow), $asOf)->toDateTimeString(),
                        'updated_at' => now(),
                    ]);

                $assessedPenaltyMinor += $penalty;
                $freshArrearsRow = DB::table('loan_arrears')->where('id', $this->rowInt($arrearsRow, 'id'))->first();
                if (is_object($freshArrearsRow)) {
                    $arrears[] = $this->arrearsPayload($freshArrearsRow);
                }
            }

            return [
                'loan' => $lockedLoan->refresh(),
                'assessed_penalty_minor' => $assessedPenaltyMinor,
                'arrears' => $arrears,
            ];
        });
    }

    private function isPastGrace(LoanScheduleLine $line, CarbonImmutable $asOf, int $graceDays): bool
    {
        $due = CarbonImmutable::parse($line->due_date)->startOfDay();

        return $asOf->greaterThanOrEqualTo($due->addDays($graceDays));
    }

    private function scheduledDueExcludingPenalties(LoanScheduleLine $line): int
    {
        return $line->principal_minor
            + $line->interest_minor
            + $line->fees_minor
            + $line->insurance_minor
            + $line->tax_minor;
    }

    private function scheduledPaidExcludingPenalties(LoanScheduleLine $line): int
    {
        $value = DB::table('loan_repayment_allocations')
            ->where('loan_schedule_line_id', $line->id)
            ->whereIn('component', [
                LoanRepaymentAllocation::COMPONENT_PRINCIPAL,
                LoanRepaymentAllocation::COMPONENT_INTEREST,
                LoanRepaymentAllocation::COMPONENT_FEES,
                LoanRepaymentAllocation::COMPONENT_INSURANCE,
                LoanRepaymentAllocation::COMPONENT_TAX,
            ])
            ->sum('amount_minor');

        return is_int($value) ? $value : (int) $value;
    }

    private function storeArrears(Loan $loan, LoanScheduleLine $line, int $originalDue, int $paid, int $unpaid, ?int $penaltyBase): object
    {
        $existing = DB::table('loan_arrears')
            ->where('loan_schedule_line_id', $line->id)
            ->first();

        $values = [
            'original_due_minor' => $originalDue,
            'paid_minor' => $paid,
            'unpaid_minor' => $unpaid,
            'penalty_base_minor' => $penaltyBase,
            'status' => $unpaid > 0 ? 'open' : 'closed',
            'updated_at' => now(),
        ];

        if (is_object($existing)) {
            DB::table('loan_arrears')->where('id', $this->rowInt($existing, 'id'))->update($values);

            $updated = DB::table('loan_arrears')->where('id', $this->rowInt($existing, 'id'))->first();
            if (is_object($updated)) {
                return $updated;
            }
        }

        $id = DB::table('loan_arrears')->insertGetId(array_merge($values, [
            'public_id' => (string) Str::ulid(),
            'loan_id' => $loan->id,
            'loan_schedule_line_id' => $line->id,
            'due_on' => $this->dateString($line->due_date),
            'currency' => $loan->currency,
            'created_at' => now(),
        ]));

        $created = DB::table('loan_arrears')->where('id', $id)->first();
        if (! is_object($created)) {
            throw new InvalidArgumentException('Loan arrears assessment could not be loaded.');
        }

        return $created;
    }

    /**
     * The variable half of the hybrid rule. Because it is a flat percentage,
     * charging it per overdue installment and charging it once on the summed
     * arrears give the same total, so it is applied per line.
     */
    private function variablePenaltyForLine(ResolvedPenaltyTerms $terms, LoanScheduleLine $line, int $originalDue, int $unpaid): int
    {
        $baseAmount = $this->penaltyBaseAmount($terms->base, $line, $originalDue, $unpaid);

        return $this->percentOf($baseAmount, $terms->ratePercent);
    }

    /**
     * A penalty may not exceed the debt it punishes.
     *
     * The 5 000 FCFA fixed part is five times the 1 000 FCFA arrears floor, so
     * without a bound a 1 000 FCFA residue attracts 5 020 FCFA — 502% of the
     * amount owed. Capping the *cumulative* penalty on an installment at that
     * installment's unpaid scheduled due keeps the accounting team's formula
     * untouched wherever it is proportionate (any residue above ~5 100 FCFA
     * never reaches the cap) and stops the two disproportionate cases: a flat
     * fee dwarfing a small residue, and penalties accruing without end on a
     * loan that COBAC provisioning rules say should be written down rather than
     * charged further.
     */
    private function capPenalty(int $penalty, int $unpaid, int $penaltyAlreadyOnLine): int
    {
        return max(0, min($penalty, $unpaid - $penaltyAlreadyOnLine));
    }

    private function penaltyBaseAmount(string $base, LoanScheduleLine $line, int $originalDue, int $unpaid): int
    {
        return match ($base) {
            'overdue_amount' => $originalDue,
            'principal' => $line->principal_minor,
            'outstanding_principal' => $line->remaining_principal_minor > 0
                ? $line->remaining_principal_minor
                : $line->principal_minor,
            // 'unpaid_scheduled_due' and any unknown base default to the
            // still-unpaid scheduled due, matching the global-config behavior.
            default => $unpaid,
        };
    }

    /**
     * The rounding mode has to be on `dividedBy`, not on a following `toScale`.
     * Without a scale, `dividedBy` inherits the operand's and rounds with
     * UNNECESSARY, throwing before the HALF_UP is ever consulted. The penalty
     * rate is the scale-0 string '2', so every unpaid base that is not a
     * multiple of 50 raised RoundingNecessaryException — a RuntimeException,
     * which aborts the whole monthly arrears batch rather than one loan.
     */
    private function percentOf(int $baseMinor, string $rate): int
    {
        return BigDecimal::of((string) $baseMinor)
            ->multipliedBy(BigDecimal::of($rate))
            ->dividedBy('100', 0, RoundingMode::HALF_UP)
            ->toInt();
    }

    private function lineTotal(LoanScheduleLine $line): int
    {
        return $line->principal_minor
            + $line->interest_minor
            + $line->fees_minor
            + $line->insurance_minor
            + $line->tax_minor
            + $line->penalty_minor
            + $line->capitalized_interest_minor;
    }

    /**
     * "At or after the start of the as-of month", not "in the same month".
     * Month equality lets a backdated run slip through: assess June, then run
     * again with an as-of date in May, and May is a different month so the
     * installment is penalized a second time. `last_penalized_at` is also
     * stored as a high-water mark for the same reason.
     */
    private function alreadyPenalized(object $arrearsRow, CarbonImmutable $asOf): bool
    {
        $lastPenalizedAt = $this->lastPenalizedAt($arrearsRow);

        return $lastPenalizedAt instanceof CarbonImmutable
            && $lastPenalizedAt->greaterThanOrEqualTo($asOf->startOfMonth());
    }

    private function lastPenalizedAt(object $arrearsRow): ?CarbonImmutable
    {
        $data = (array) $arrearsRow;
        $value = $data['last_penalized_at'] ?? null;

        return is_string($value) && $value !== '' ? CarbonImmutable::parse($value) : null;
    }

    private function laterOf(?CarbonImmutable $existing, CarbonImmutable $candidate): CarbonImmutable
    {
        return $existing instanceof CarbonImmutable && $existing->greaterThan($candidate)
            ? $existing
            : $candidate;
    }

    /**
     * Whether this loan has already been charged its flat monthly recovery fee
     * within the as-of month, on any of its overdue installments.
     */
    private function fixedPartChargedThisMonth(Loan $loan, CarbonImmutable $asOf): bool
    {
        return DB::table('loan_arrears')
            ->where('loan_id', $loan->id)
            ->where('last_penalized_at', '>=', $asOf->startOfMonth()->toDateTimeString())
            ->exists();
    }

    /**
     * @return array<string, mixed>
     */
    private function arrearsPayload(object $arrearsRow): array
    {
        return [
            'public_id' => $this->rowString($arrearsRow, 'public_id'),
            'loan_schedule_line_id' => $this->rowInt($arrearsRow, 'loan_schedule_line_id'),
            'due_on' => $this->rowString($arrearsRow, 'due_on'),
            'original_due_minor' => $this->rowInt($arrearsRow, 'original_due_minor'),
            'paid_minor' => $this->rowInt($arrearsRow, 'paid_minor'),
            'unpaid_minor' => $this->rowInt($arrearsRow, 'unpaid_minor'),
            'penalty_base_minor' => $this->rowNullableInt($arrearsRow, 'penalty_base_minor'),
            'status' => $this->rowString($arrearsRow, 'status'),
            'last_penalized_at' => $this->rowNullableString($arrearsRow, 'last_penalized_at'),
        ];
    }

    /**
     * Grace days prefer the loan snapshot (so later product edits do not change
     * historical loans), then the current product, then the default.
     *
     * @param  array<array-key, mixed>|null  $snapshotProductTerms
     */
    private function graceDays(Loan $loan, ?array $snapshotProductTerms): int
    {
        $snapshotGrace = $snapshotProductTerms['penalty_grace_days'] ?? null;
        if (is_int($snapshotGrace)) {
            return $snapshotGrace;
        }
        if (is_numeric($snapshotGrace)) {
            return (int) $snapshotGrace;
        }

        $product = $loan->loanProduct;
        if ($product instanceof LoanProduct && is_int($product->penalty_grace_days)) {
            return $product->penalty_grace_days;
        }

        return 5;
    }

    /**
     * @return array<array-key, mixed>|null
     */
    private function snapshotProductTerms(Loan $loan): ?array
    {
        $snapshot = $loan->getAttribute('formula_policy_snapshot');
        if (! is_array($snapshot)) {
            return null;
        }

        $terms = $snapshot['product_terms'] ?? null;

        return is_array($terms) ? $terms : null;
    }

    /** 1 000 XAF at the account scale — see the config entry for the source. */
    private const int DEFAULT_MINIMUM_UNPAID_AMOUNT_MINOR = 100000;

    private function minimumUnpaidAmountMinor(): int
    {
        $value = config(
            'formulas.policies.penalties_and_arrears.rules.monthly_arrears_penalty.minimum_unpaid_amount_minor',
            self::DEFAULT_MINIMUM_UNPAID_AMOUNT_MINOR,
        );

        return is_int($value) ? $value : self::DEFAULT_MINIMUM_UNPAID_AMOUNT_MINOR;
    }

    private function dateString(mixed $value): string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        if (is_string($value)) {
            return substr($value, 0, 10);
        }

        if (is_int($value) || is_float($value)) {
            return substr((string) $value, 0, 10);
        }

        return '';
    }

    private function rowString(object $row, string $key): string
    {
        $data = (array) $row;
        $value = $data[$key] ?? '';

        return is_string($value) ? $value : (string) $value;
    }

    private function rowNullableString(object $row, string $key): ?string
    {
        $data = (array) $row;
        $value = $data[$key] ?? null;

        return $value === null ? null : (is_string($value) ? $value : (string) $value);
    }

    private function rowInt(object $row, string $key): int
    {
        $data = (array) $row;
        $value = $data[$key] ?? 0;

        return is_int($value) ? $value : (int) $value;
    }

    private function rowNullableInt(object $row, string $key): ?int
    {
        $data = (array) $row;
        $value = $data[$key] ?? null;

        return $value === null ? null : (is_int($value) ? $value : (int) $value);
    }
}
