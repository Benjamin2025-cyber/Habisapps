<?php

declare(strict_types=1);

namespace App\Application\Loans;

use App\Http\Controllers\BaseController;
use App\Http\Resources\JournalEntryResource;
use App\Http\Resources\LoanResource;
use App\Models\JournalEntry;
use App\Models\Loan;
use App\Models\LoanDisbursement;
use App\Models\LoanRepayment;
use App\Models\User;
use App\Support\Finance\FormulaPolicyNotApproved;
use App\Support\Security\SecurityAudit;
use App\Support\Staff\StaffAgencyScope;
use DateTimeInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

final class LoanRepaymentWorkflow extends BaseController
{
    public function __construct(
        private readonly SecurityAudit $securityAudit,
        private readonly StaffAgencyScope $staffAgencyScope,
        private readonly DisburseLoan $disburseLoan,
        private readonly RecordLoanRepayment $recordLoanRepayment,
        private readonly AssessLoanArrearsAndPenalties $assessLoanArrearsAndPenalties,
        private readonly EarlyRepayLoan $earlyRepayLoan,
    ) {}

    public function disburse(Request $request, Loan $loan): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || $actor->cannot('update', $loan)) {
            return $this->respondForbidden();
        }

        if (! $actor->hasRole('platform-admin') && ! $actor->can('loans.disburse')) {
            return $this->respondForbidden('Loan disbursement is outside your permission set.');
        }

        if (! $this->canAccessLoanAgency($actor, $loan)) {
            return $this->respondForbidden('Loan is outside your agency scope.');
        }

        $validated = Validator::make($request->all(), [
            'disbursement_channel' => ['sometimes', Rule::in([LoanDisbursement::CHANNEL_TRANSFER_ACCOUNT, LoanDisbursement::CHANNEL_CASH])],
            'transfer_account_public_id' => ['sometimes', 'nullable', 'string', 'exists:customer_accounts,public_id'],
            'teller_session_public_id' => ['sometimes', 'nullable', 'string', 'exists:teller_sessions,public_id'],
            'business_date' => ['sometimes', 'nullable', 'date'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ])->validate();

        try {
            $result = $this->disburseLoan->handle(
                $loan,
                $actor,
                $this->stringValue($validated['disbursement_channel'] ?? LoanDisbursement::CHANNEL_TRANSFER_ACCOUNT, LoanDisbursement::CHANNEL_TRANSFER_ACCOUNT),
                is_string($validated['transfer_account_public_id'] ?? null) ? $validated['transfer_account_public_id'] : null,
                is_string($validated['business_date'] ?? null) ? $validated['business_date'] : null,
                is_string($validated['notes'] ?? null) ? $validated['notes'] : null,
                is_string($validated['teller_session_public_id'] ?? null) ? $validated['teller_session_public_id'] : null,
            );
        } catch (InvalidArgumentException $exception) {
            return $this->respondUnprocessable(errors: ['disbursement' => [$exception->getMessage()]]);
        }

        $this->securityAudit->record('loan.disbursement.posted', actor: $actor, subject: $loan, properties: [
            'disbursement_public_id' => $result['disbursement']->public_id,
            'journal_entry_public_id' => $result['journal_entry']->public_id,
        ], request: $request);

        return $this->respondSuccess([
            'loan' => LoanResource::make($result['loan']->loadMissing($this->relations())),
            'disbursement' => $this->disbursementPayload($result['disbursement']->loadMissing(['transferAccount', 'postedBy'])),
            'journal_entry' => JournalEntryResource::make($result['journal_entry']->loadMissing(['agency', 'lines.ledgerAccount', 'lines.customerAccount'])),
        ], 'Loan disbursement posted successfully');
    }

    public function repay(Request $request, Loan $loan): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || $actor->cannot('update', $loan)) {
            return $this->respondForbidden();
        }

        if (! $actor->hasRole('platform-admin') && ! $actor->can('loans.repayments.create')) {
            return $this->respondForbidden('Loan repayment is outside your permission set.');
        }

        if (! $this->canAccessLoanAgency($actor, $loan)) {
            return $this->respondForbidden('Loan is outside your agency scope.');
        }

        $validated = Validator::make($request->all(), [
            'customer_account_public_id' => ['required', 'string', 'exists:customer_accounts,public_id'],
            'amount_minor' => ['required', 'integer', 'min:1'],
            'paid_on' => ['sometimes', 'nullable', 'date'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ])->validate();

        try {
            $result = $this->recordLoanRepayment->handle(
                $loan,
                $actor,
                $this->intValue($validated['amount_minor'] ?? 0),
                $this->stringValue($validated['customer_account_public_id'] ?? null, ''),
                is_string($validated['paid_on'] ?? null) ? $validated['paid_on'] : null,
                is_string($validated['notes'] ?? null) ? $validated['notes'] : null,
            );
        } catch (FormulaPolicyNotApproved $exception) {
            return $this->respondUnprocessable(errors: ['repayment_allocation_order' => [$exception->getMessage()]]);
        } catch (InvalidArgumentException $exception) {
            return $this->respondUnprocessable(errors: ['repayment' => [$exception->getMessage()]]);
        }

        $this->securityAudit->record('loan.repayment.posted', actor: $actor, subject: $loan, properties: [
            'repayment_public_id' => $result['repayment']->public_id,
            'journal_entry_public_id' => $result['journal_entry']->public_id,
        ], request: $request);

        return $this->respondSuccess([
            'loan' => LoanResource::make($result['loan']->loadMissing($this->relations())),
            'repayment' => $this->repaymentPayload($result['repayment']),
            'journal_entry' => JournalEntryResource::make($result['journal_entry']->loadMissing(['agency', 'lines.ledgerAccount', 'lines.customerAccount'])),
        ], 'Loan repayment posted successfully');
    }

    public function assessArrears(Request $request, Loan $loan): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || $actor->cannot('update', $loan)) {
            return $this->respondForbidden();
        }

        if (! $actor->hasRole('platform-admin') && ! $actor->can('loans.arrears.assess')) {
            return $this->respondForbidden('Loan arrears assessment is outside your permission set.');
        }

        if (! $this->canAccessLoanAgency($actor, $loan)) {
            return $this->respondForbidden('Loan is outside your agency scope.');
        }

        $validated = Validator::make($request->all(), [
            'as_of_date' => ['sometimes', 'nullable', 'date'],
        ])->validate();

        try {
            $result = $this->assessLoanArrearsAndPenalties->handle(
                $loan,
                is_string($validated['as_of_date'] ?? null) ? $validated['as_of_date'] : now()->toDateString(),
            );
        } catch (FormulaPolicyNotApproved $exception) {
            return $this->respondUnprocessable(errors: ['penalties_and_arrears' => [$exception->getMessage()]]);
        } catch (InvalidArgumentException $exception) {
            return $this->respondUnprocessable(errors: ['arrears' => [$exception->getMessage()]]);
        }

        $this->securityAudit->record('loan.arrears.assessed', actor: $actor, subject: $loan, properties: [
            'assessed_penalty_minor' => $result['assessed_penalty_minor'],
            'arrears_count' => count($result['arrears']),
        ], request: $request);

        return $this->respondSuccess([
            'loan' => LoanResource::make($result['loan']->loadMissing($this->relations())),
            'assessed_penalty_minor' => $result['assessed_penalty_minor'],
            'arrears' => $result['arrears'],
        ], 'Loan arrears assessed successfully');
    }

    public function earlyRepay(Request $request, Loan $loan): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || $actor->cannot('update', $loan)) {
            return $this->respondForbidden();
        }

        if (! $actor->hasRole('platform-admin') && ! $actor->can('loans.early-repayments.create')) {
            return $this->respondForbidden('Loan early repayment is outside your permission set.');
        }

        if (! $this->canAccessLoanAgency($actor, $loan)) {
            return $this->respondForbidden('Loan is outside your agency scope.');
        }

        $validated = Validator::make($request->all(), [
            'customer_account_public_id' => ['required', 'string', 'exists:customer_accounts,public_id'],
            'amount_minor' => ['required', 'integer', 'min:1'],
            'paid_on' => ['sometimes', 'nullable', 'date'],
            'direction_interest_waiver' => ['sometimes', 'boolean'],
            'direction_negotiated_total_interest_minor' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'idempotency_key' => ['sometimes', 'nullable', 'string', 'max:128'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ])->validate();

        $directionInterestWaiver = ($validated['direction_interest_waiver'] ?? false) === true;
        $directionNegotiatedTotalInterest = array_key_exists('direction_negotiated_total_interest_minor', $validated)
            ? $this->intValue($validated['direction_negotiated_total_interest_minor'])
            : null;
        if ($directionInterestWaiver && $directionNegotiatedTotalInterest !== null) {
            return $this->respondUnprocessable(errors: ['early_repayment' => [__('Use either a full future-interest waiver or a negotiated total interest amount, not both.')]]);
        }
        if (($directionInterestWaiver || $directionNegotiatedTotalInterest !== null) && ! $actor->hasRole('platform-admin') && ! $actor->can('loans.approvals.direction')) {
            return $this->respondForbidden('Direction approval is required to waive future interest.');
        }

        try {
            $result = $this->earlyRepayLoan->handle(
                $loan,
                $actor,
                $this->stringValue($validated['customer_account_public_id'] ?? null, ''),
                $this->intValue($validated['amount_minor'] ?? 0),
                is_string($validated['paid_on'] ?? null) ? $validated['paid_on'] : null,
                $directionInterestWaiver,
                $directionNegotiatedTotalInterest,
                is_string($validated['notes'] ?? null) ? $validated['notes'] : null,
                is_string($validated['idempotency_key'] ?? null) ? $validated['idempotency_key'] : null,
            );
        } catch (FormulaPolicyNotApproved $exception) {
            return $this->respondUnprocessable(errors: ['repayment_allocation_order' => [$exception->getMessage()]]);
        } catch (InvalidArgumentException $exception) {
            return $this->respondUnprocessable(errors: ['early_repayment' => [$exception->getMessage()]]);
        }

        $this->securityAudit->record('loan.early_repayment.posted', actor: $actor, subject: $loan, properties: [
            'repayment_public_id' => $this->repaymentResult($result)->public_id,
            'journal_entry_public_id' => $this->journalEntryResult($result)->public_id,
            'direction_interest_waiver' => $directionInterestWaiver,
        ], request: $request);

        return $this->respondSuccess([
            'loan' => LoanResource::make($this->loanResult($result)->loadMissing($this->relations())),
            'repayment' => $this->repaymentPayload($this->repaymentResult($result)),
            'journal_entry' => JournalEntryResource::make($this->journalEntryResult($result)->loadMissing(['agency', 'lines.ledgerAccount', 'lines.customerAccount'])),
            'payoff_amount_minor' => $result['payoff_amount_minor'],
            'direction_interest_waiver' => $result['direction_interest_waiver'],
            'direction_negotiated_total_interest_minor' => $result['direction_negotiated_total_interest_minor'],
            'interest_concession_minor' => $result['interest_concession_minor'],
            'early_repayment_fee_minor' => $result['early_repayment_fee_minor'],
            'insurance_refunded_minor' => $result['insurance_refunded_minor'],
            'released_guarantee_obligations_count' => $result['released_guarantee_obligations_count'],
        ], 'Loan early repayment posted successfully');
    }

    private function canAccessLoanAgency(User $actor, Loan $loan): bool
    {
        return $actor->hasRole('platform-admin')
            || $actor->can('crm.scope.institution.read')
            || $this->staffAgencyScope->currentAgencyId($actor) === $loan->agency_id;
    }

    /**
     * @return array<string, mixed>
     */
    private function disbursementPayload(LoanDisbursement $disbursement): array
    {
        return [
            'public_id' => $disbursement->public_id,
            'disbursement_channel' => $disbursement->disbursement_channel,
            'principal_amount_minor' => $disbursement->principal_amount_minor,
            'currency' => $disbursement->currency,
            'status' => $disbursement->status,
            'posted_at' => $this->formatDate($disbursement->posted_at),
            'posted_by_user_public_id' => $disbursement->postedBy?->public_id,
            'transfer_account_public_id' => $disbursement->transferAccount?->public_id,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function repaymentPayload(LoanRepayment $repayment): array
    {
        $repayment->loadMissing(['allocations.scheduleLine', 'customerAccount', 'postedBy']);

        return [
            'public_id' => $repayment->public_id,
            'customer_account_public_id' => $repayment->customerAccount?->public_id,
            'received_amount_minor' => $repayment->received_amount_minor,
            'allocated_amount_minor' => $repayment->allocated_amount_minor,
            'overpayment_retained_minor' => $repayment->overpayment_retained_minor,
            'currency' => $repayment->currency,
            'paid_on' => $this->formatDateOnly($repayment->paid_on),
            'status' => $repayment->status,
            'posted_at' => $this->formatDate($repayment->posted_at),
            'posted_by_user_public_id' => $repayment->postedBy?->public_id,
            'allocations' => $repayment->allocations->map(fn ($allocation): array => [
                'installment_number' => $allocation->scheduleLine?->installment_number,
                'component' => $allocation->component,
                'amount_minor' => $allocation->amount_minor,
                'currency' => $allocation->currency,
            ])->values()->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function loanResult(array $result): Loan
    {
        $loan = $result['loan'] ?? null;
        if (! $loan instanceof Loan) {
            throw new InvalidArgumentException('Loan result is missing.');
        }

        return $loan;
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function repaymentResult(array $result): LoanRepayment
    {
        $repayment = $result['repayment'] ?? null;
        if (! $repayment instanceof LoanRepayment) {
            throw new InvalidArgumentException('Repayment result is missing.');
        }

        return $repayment;
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function journalEntryResult(array $result): JournalEntry
    {
        $journalEntry = $result['journal_entry'] ?? null;
        if (! $journalEntry instanceof JournalEntry) {
            throw new InvalidArgumentException('Journal entry result is missing.');
        }

        return $journalEntry;
    }

    /**
     * @return array<int, string>
     */
    private function relations(): array
    {
        return [
            'client',
            'agency',
            'loanProduct',
            'creditAgent',
            'amortizationAccount',
            'unpaidAccount',
            'recoveryAccount',
            'transferAccount',
            'sector',
            'subSector',
        ];
    }

    private function intValue(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && is_numeric($value)) {
            return (int) $value;
        }

        return 0;
    }

    private function stringValue(mixed $value, string $default): string
    {
        return is_string($value) && $value !== '' ? $value : $default;
    }

    private function formatDate(mixed $value): ?string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format(DateTimeInterface::ATOM);
        }

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function formatDateOnly(mixed $value): ?string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        return is_string($value) && $value !== '' ? substr($value, 0, 10) : null;
    }
}
