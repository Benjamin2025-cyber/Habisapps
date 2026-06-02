<?php

declare(strict_types=1);

namespace App\Application\Loans;

use App\Models\Loan;
use App\Models\LoanProduct;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * FBI2-030 — single source of truth for loan setup-charge / insurance-premium
 * readiness.
 *
 * Both the read endpoint (GET /loans/{loan}/setup-charges and the
 * include_setup_charges projection on GET /loans/{loan}) and
 * DisburseLoan::ensureSetupSatisfied() resolve readiness through this service,
 * so UI readiness and backend disbursement enforcement cannot drift.
 */
final class LoanSetupState
{
    public const string STATUS_NO_SETUP_REQUIRED = 'no_setup_required';

    public const string STATUS_NOT_ASSESSED = 'not_assessed';

    public const string STATUS_COLLECTION_PENDING = 'collection_pending';

    public const string STATUS_READY = 'ready';

    /** @var array<int, string> */
    private const array CHARGE_TYPES = ['dossier_fee', 'dossier_fee_tax', 'guarantee_deposit'];

    /** @var array<int, string> */
    private const array COLLECTED_STATUSES = ['paid', 'collected', 'posted'];

    /**
     * Serialize the full setup-charge / insurance-premium state for the UI.
     *
     * @return array<string, mixed>
     */
    public function forLoan(Loan $loan): array
    {
        $loan->loadMissing('loanProduct');
        $product = $loan->loanProduct instanceof LoanProduct ? $loan->loanProduct : null;
        $evaluation = $this->evaluate($loan, $product);

        return [
            'loan_public_id' => $loan->public_id,
            'loan_status' => $loan->status,
            'currency' => $loan->currency,
            'readiness_status' => $evaluation['readiness_status'],
            'ready_for_disbursement' => $evaluation['ready_for_disbursement'],
            'setup_required' => $evaluation['setup_required'],
            'required_next_actions' => $evaluation['next_actions'],
            'setup_charges' => $evaluation['charges'],
            'insurance_premiums' => $evaluation['premiums'],
        ];
    }

    /**
     * Enforce the same readiness rules used by the read model before a loan is
     * disbursed. Throws with the canonical messages relied on by callers.
     */
    public function assertReadyForDisbursement(Loan $loan, LoanProduct $product): void
    {
        $evaluation = $this->evaluate($loan, $product);

        if (! $evaluation['setup_required']) {
            return;
        }

        if ($evaluation['missing_assessment']) {
            throw new InvalidArgumentException('Setup charges must be assessed before disbursement.');
        }

        if ($evaluation['blocking_charge_types'] !== []) {
            throw new InvalidArgumentException(
                'Setup charges must be collected before disbursement: '.implode(', ', $evaluation['blocking_charge_types']).'.'
            );
        }

        if ($evaluation['blocking_premium_count'] > 0) {
            throw new InvalidArgumentException('Loan insurance premium must be collected before disbursement.');
        }
    }

    public function requiresSetup(LoanProduct $product): bool
    {
        $rules = is_array($product->getAttribute('rules')) ? $product->getAttribute('rules') : [];

        return $product->fee_amount_minor !== null
            || $product->tax_rate !== null
            || $product->insurance_rate !== null
            || $product->guarantee_deposit_value !== null
            || is_array($rules['setup_charges'] ?? null);
    }

    /**
     * @return array{
     *     setup_required: bool,
     *     missing_assessment: bool,
     *     blocking_charge_types: array<int, string>,
     *     blocking_premium_count: int,
     *     readiness_status: string,
     *     ready_for_disbursement: bool,
     *     next_actions: array<int, array<string, mixed>>,
     *     charges: array<int, array<string, mixed>>,
     *     premiums: array<int, array<string, mixed>>,
     * }
     */
    private function evaluate(Loan $loan, ?LoanProduct $product): array
    {
        $setupRequired = $product instanceof LoanProduct && $this->requiresSetup($product);

        $chargeRows = $this->chargeRows($loan);
        $premiumRows = $this->premiumRows($loan);
        $paymentsByAssessment = $this->paymentsByAssessment($premiumRows);

        $charges = [];
        $blockingChargeTypes = [];
        foreach ($chargeRows as $row) {
            $assessedAmount = $this->intValue($this->field($row, 'assessed_amount_minor'));
            $status = $this->stringValue($this->field($row, 'status'));
            $paidAt = $this->nullableString($this->field($row, 'paid_at'));
            $collected = $this->chargeCollected($status, $paidAt);
            $blocking = $assessedAmount > 0 && ! $collected;
            if ($blocking) {
                $blockingChargeTypes[] = $this->stringValue($this->field($row, 'charge_type'));
            }

            $charges[] = [
                'public_id' => $this->stringValue($this->field($row, 'public_id')),
                'charge_type' => $this->stringValue($this->field($row, 'charge_type')),
                'base_amount_minor' => $this->nullableInt($this->field($row, 'base_amount_minor')),
                'rate' => $this->nullableString($this->field($row, 'rate')),
                'assessed_amount_minor' => $assessedAmount,
                'currency' => $this->stringValue($this->field($row, 'currency')),
                'status' => $status,
                'paid_at' => $paidAt,
                'journal_entry_public_id' => $this->nullableString($this->field($row, 'journal_entry_public_id')),
                'waiver_decision' => $this->waiverDecision($this->field($row, 'metadata')),
                'collectable' => $status === 'assessed' && $assessedAmount > 0,
                'blocking_disbursement' => $blocking,
                'metadata' => $this->metadata($this->field($row, 'metadata')),
            ];
        }

        $premiums = [];
        $blockingPremiumCount = 0;
        foreach ($premiumRows as $row) {
            $premiumAmount = $this->intValue($this->field($row, 'premium_amount_minor'));
            $status = $this->stringValue($this->field($row, 'status'));
            $assessmentId = $this->intValue($this->field($row, 'id'));
            /** @var array<int, \stdClass> $payments */
            $payments = $paymentsByAssessment->get($assessmentId, new Collection)->all();
            $collected = $this->premiumCollected($status, $payments);
            $blocking = $premiumAmount > 0 && ! $collected;
            if ($blocking) {
                $blockingPremiumCount++;
            }

            $premiums[] = [
                'public_id' => $this->stringValue($this->field($row, 'public_id')),
                'base_amount_minor' => $this->nullableInt($this->field($row, 'base_amount_minor')),
                'rate' => $this->nullableString($this->field($row, 'rate')),
                'premium_amount_minor' => $premiumAmount,
                'currency' => $this->stringValue($this->field($row, 'currency')),
                'due_on' => $this->nullableString($this->field($row, 'due_on')),
                'status' => $status,
                'blocking_disbursement' => $blocking,
                'payments' => array_map(fn (object $payment): array => $this->paymentPayload($payment), $payments),
                'metadata' => $this->metadata($this->field($row, 'metadata')),
            ];
        }

        $hasAssessedCharges = $chargeRows->contains(fn (object $row): bool => $this->intValue($this->field($row, 'assessed_amount_minor')) > 0);
        $hasAssessedPremiums = $premiumRows->contains(fn (object $row): bool => $this->intValue($this->field($row, 'premium_amount_minor')) > 0);
        $missingAssessment = $setupRequired && ! $hasAssessedCharges && ! $hasAssessedPremiums;

        [$status, $ready] = $this->resolveStatus($setupRequired, $missingAssessment, $blockingChargeTypes, $blockingPremiumCount);

        return [
            'setup_required' => $setupRequired,
            'missing_assessment' => $missingAssessment,
            'blocking_charge_types' => $blockingChargeTypes,
            'blocking_premium_count' => $blockingPremiumCount,
            'readiness_status' => $status,
            'ready_for_disbursement' => $ready,
            'next_actions' => $this->nextActions($status, $charges, $premiums),
            'charges' => $charges,
            'premiums' => $premiums,
        ];
    }

    /**
     * @param  array<int, string>  $blockingChargeTypes
     * @return array{0: string, 1: bool}
     */
    private function resolveStatus(bool $setupRequired, bool $missingAssessment, array $blockingChargeTypes, int $blockingPremiumCount): array
    {
        if (! $setupRequired) {
            return [self::STATUS_NO_SETUP_REQUIRED, true];
        }

        if ($missingAssessment) {
            return [self::STATUS_NOT_ASSESSED, false];
        }

        if ($blockingChargeTypes !== [] || $blockingPremiumCount > 0) {
            return [self::STATUS_COLLECTION_PENDING, false];
        }

        return [self::STATUS_READY, true];
    }

    /**
     * @param  array<int, array<string, mixed>>  $charges
     * @param  array<int, array<string, mixed>>  $premiums
     * @return array<int, array<string, mixed>>
     */
    private function nextActions(string $status, array $charges, array $premiums): array
    {
        if ($status === self::STATUS_NO_SETUP_REQUIRED) {
            return [];
        }

        if ($status === self::STATUS_NOT_ASSESSED) {
            return [[
                'action' => 'assess_setup_charges',
                'description' => 'Assess loan setup charges and insurance premiums before disbursement.',
            ]];
        }

        if ($status === self::STATUS_READY) {
            return [[
                'action' => 'disburse_loan',
                'description' => 'All setup charges and insurance premiums are collected or waived; the loan can be disbursed.',
            ]];
        }

        $actions = [];
        foreach ($charges as $charge) {
            if (($charge['blocking_disbursement'] ?? false) === true) {
                $actions[] = [
                    'action' => 'collect_setup_charge',
                    'charge_public_id' => $charge['public_id'] ?? null,
                    'charge_type' => $charge['charge_type'] ?? null,
                    'description' => 'Collect or waive the assessed setup charge before disbursement.',
                ];
            }
        }
        foreach ($premiums as $premium) {
            if (($premium['blocking_disbursement'] ?? false) === true) {
                $actions[] = [
                    'action' => 'collect_insurance_premium',
                    'premium_public_id' => $premium['public_id'] ?? null,
                    'description' => 'Collect the assessed insurance premium before disbursement.',
                ];
            }
        }

        return $actions;
    }

    /**
     * @return Collection<int, \stdClass>
     */
    private function chargeRows(Loan $loan): Collection
    {
        return DB::table('loan_charge_assessments as lca')
            ->leftJoin('journal_entries as je', 'je.id', '=', 'lca.journal_entry_id')
            ->where('lca.loan_id', $loan->id)
            ->whereIn('lca.charge_type', self::CHARGE_TYPES)
            ->orderBy('lca.id')
            ->get([
                'lca.public_id',
                'lca.charge_type',
                'lca.base_amount_minor',
                'lca.rate',
                'lca.assessed_amount_minor',
                'lca.currency',
                'lca.status',
                'lca.paid_at',
                'lca.metadata',
                'je.public_id as journal_entry_public_id',
            ]);
    }

    /**
     * @return Collection<int, \stdClass>
     */
    private function premiumRows(Loan $loan): Collection
    {
        return DB::table('insurance_premium_assessments')
            ->where('loan_id', $loan->id)
            ->orderBy('id')
            ->get([
                'id',
                'public_id',
                'base_amount_minor',
                'rate',
                'premium_amount_minor',
                'currency',
                'due_on',
                'status',
                'metadata',
            ]);
    }

    /**
     * @param  Collection<int, \stdClass>  $premiumRows
     * @return Collection<int|string, Collection<int, \stdClass>>
     */
    private function paymentsByAssessment(Collection $premiumRows): Collection
    {
        $assessmentIds = $premiumRows
            ->map(fn (object $row): int => $this->intValue($this->field($row, 'id')))
            ->filter(fn (int $id): bool => $id > 0)
            ->values()
            ->all();

        if ($assessmentIds === []) {
            /** @var Collection<int|string, Collection<int, \stdClass>> $empty */
            $empty = new Collection;

            return $empty;
        }

        return DB::table('insurance_premium_payments as ipp')
            ->leftJoin('journal_entries as je', 'je.id', '=', 'ipp.journal_entry_id')
            ->whereIn('ipp.insurance_premium_assessment_id', $assessmentIds)
            ->orderBy('ipp.id')
            ->get([
                'ipp.insurance_premium_assessment_id',
                'ipp.public_id',
                'ipp.amount_minor',
                'ipp.currency',
                'ipp.payment_method',
                'ipp.paid_at',
                'ipp.status',
                'je.public_id as journal_entry_public_id',
            ])
            ->groupBy(fn (object $payment): int => $this->intValue($this->field($payment, 'insurance_premium_assessment_id')));
    }

    private function chargeCollected(string $status, ?string $paidAt): bool
    {
        return $status === 'waived_by_direction'
            || (in_array($status, self::COLLECTED_STATUSES, true) && $paidAt !== null);
    }

    /**
     * @param  array<int, object>  $payments
     */
    private function premiumCollected(string $status, array $payments): bool
    {
        if (in_array($status, self::COLLECTED_STATUSES, true)) {
            return true;
        }

        foreach ($payments as $payment) {
            if (in_array($this->stringValue($this->field($payment, 'status')), self::COLLECTED_STATUSES, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    private function paymentPayload(object $payment): array
    {
        return [
            'public_id' => $this->stringValue($this->field($payment, 'public_id')),
            'amount_minor' => $this->intValue($this->field($payment, 'amount_minor')),
            'currency' => $this->stringValue($this->field($payment, 'currency')),
            'payment_method' => $this->nullableString($this->field($payment, 'payment_method')),
            'paid_at' => $this->nullableString($this->field($payment, 'paid_at')),
            'status' => $this->stringValue($this->field($payment, 'status')),
            'journal_entry_public_id' => $this->nullableString($this->field($payment, 'journal_entry_public_id')),
        ];
    }

    /**
     * @return array<array-key, mixed>|null
     */
    private function waiverDecision(mixed $metadata): ?array
    {
        $decoded = $this->metadata($metadata);
        $decision = $decoded['direction_exception_decision'] ?? null;

        return is_array($decision) ? $decision : null;
    }

    private function field(object $row, string $key): mixed
    {
        return ((array) $row)[$key] ?? null;
    }

    /**
     * @return array<string, mixed>
     */
    private function metadata(mixed $metadata): array
    {
        if (! is_string($metadata) || $metadata === '') {
            return [];
        }

        $decoded = json_decode($metadata, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function intValue(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private function nullableInt(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private function stringValue(mixed $value): string
    {
        return is_string($value) ? $value : (is_numeric($value) ? (string) $value : '');
    }

    private function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
