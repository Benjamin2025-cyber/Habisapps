<?php

declare(strict_types=1);

namespace App\Application\Loans;

use App\Models\Loan;
use App\Models\LoanProduct;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * FBI2-030 — single source of truth for loan setup-charge readiness.
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
     * Serialize the full setup-charge state for the UI.
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
            'loan_assurance' => $evaluation['loan_assurance'],
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
                __('loans.setup_charges_must_be_collected', ['types' => implode(', ', $evaluation['blocking_charge_types'])])
            );
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
     *     readiness_status: string,
     *     ready_for_disbursement: bool,
     *     next_actions: array<int, array<string, mixed>>,
     *     charges: array<int, array<string, mixed>>,
     *     loan_assurance: array<string, mixed>,
     * }
     */
    private function evaluate(Loan $loan, ?LoanProduct $product): array
    {
        $setupRequired = $product instanceof LoanProduct && $this->requiresSetup($product);

        $chargeRows = $this->chargeRows($loan);

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

        $hasAssessedCharges = $chargeRows->contains(fn (object $row): bool => $this->intValue($this->field($row, 'assessed_amount_minor')) > 0);
        $loanAssuranceAmount = $loan->insurance_amount_minor ?? 0;
        $missingAssessment = $setupRequired && ! $hasAssessedCharges && $loanAssuranceAmount <= 0;

        [$status, $ready] = $this->resolveStatus($setupRequired, $missingAssessment, $blockingChargeTypes);

        return [
            'setup_required' => $setupRequired,
            'missing_assessment' => $missingAssessment,
            'blocking_charge_types' => $blockingChargeTypes,
            'readiness_status' => $status,
            'ready_for_disbursement' => $ready,
            'next_actions' => $this->nextActions($status, $charges),
            'charges' => $charges,
            'loan_assurance' => [
                'amount_minor' => $loanAssuranceAmount,
                'rate' => $product instanceof LoanProduct ? $this->nullableString($product->insurance_rate) : null,
                'currency' => $loan->currency,
                'blocking_disbursement' => false,
                'managed_as_premium' => false,
            ],
        ];
    }

    /**
     * @param  array<int, string>  $blockingChargeTypes
     * @return array{0: string, 1: bool}
     */
    private function resolveStatus(bool $setupRequired, bool $missingAssessment, array $blockingChargeTypes): array
    {
        if (! $setupRequired) {
            return [self::STATUS_NO_SETUP_REQUIRED, true];
        }

        if ($missingAssessment) {
            return [self::STATUS_NOT_ASSESSED, false];
        }

        if ($blockingChargeTypes !== []) {
            return [self::STATUS_COLLECTION_PENDING, false];
        }

        return [self::STATUS_READY, true];
    }

    /**
     * @param  array<int, array<string, mixed>>  $charges
     * @return array<int, array<string, mixed>>
     */
    private function nextActions(string $status, array $charges): array
    {
        if ($status === self::STATUS_NO_SETUP_REQUIRED) {
            return [];
        }

        if ($status === self::STATUS_NOT_ASSESSED) {
            return [[
                'action' => 'assess_setup_charges',
                'description' => 'Assess loan setup charges before disbursement.',
            ]];
        }

        if ($status === self::STATUS_READY) {
            return [[
                'action' => 'disburse_loan',
                'description' => 'All setup charges are collected or waived; the loan can be disbursed.',
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

    private function chargeCollected(string $status, ?string $paidAt): bool
    {
        return $status === 'waived_by_direction'
            || (in_array($status, self::COLLECTED_STATUSES, true) && $paidAt !== null);
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
