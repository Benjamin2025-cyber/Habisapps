<?php

declare(strict_types=1);

namespace App\Application\Loans;

use App\Http\Controllers\BaseController;
use App\Http\Resources\LoanResource;
use App\Models\Loan;
use App\Models\LoanApproval;
use App\Models\LoanStatusTransition;
use App\Models\User;
use App\Support\Security\SecurityAudit;
use App\Support\Staff\StaffAgencyScope;
use DateTimeInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

final class LoanApprovalWorkflow extends BaseController
{
    public function __construct(
        private readonly SecurityAudit $securityAudit,
        private readonly StaffAgencyScope $staffAgencyScope,
        private readonly AdvanceLoanApproval $advanceLoanApproval,
        private readonly TransitionLoanStatus $transitionLoanStatus,
    ) {}

    public function listApprovals(Request $request, Loan $loan): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || $actor->cannot('view', $loan)) {
            return $this->respondForbidden();
        }

        if (! $this->canAccessLoanAgency($actor, $loan)) {
            return $this->respondForbidden('Loan is outside your agency scope.');
        }

        $approvals = LoanApproval::query()
            ->where('loan_id', $loan->id)
            ->with('actedBy')
            ->oldest('id')
            ->get();

        return $this->respondSuccess([
            'approvals' => $approvals
                ->map(fn (LoanApproval $approval): array => $this->approvalPayload($approval))
                ->values()
                ->all(),
        ]);
    }

    public function decideApproval(Request $request, Loan $loan, string $step): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || $actor->cannot('update', $loan)) {
            return $this->respondForbidden();
        }

        if (! $actor->hasRole('platform-admin') && ! $actor->can('loans.approvals.'.$step)) {
            return $this->respondForbidden('Loan approval step is outside your permission set.');
        }

        if (! $this->canAccessLoanAgency($actor, $loan)) {
            return $this->respondForbidden('Loan is outside your agency scope.');
        }

        $validated = Validator::make($request->all(), [
            'decision' => ['required', Rule::in([LoanApproval::DECISION_APPROVED, LoanApproval::DECISION_REJECTED, LoanApproval::DECISION_RETURNED])],
            'comments' => ['nullable', 'string', 'max:1000'],
        ])->validate();

        try {
            $result = $this->advanceLoanApproval->handle(
                $loan,
                $actor,
                $step,
                (string) $validated['decision'],
                is_string($validated['comments'] ?? null) ? $validated['comments'] : null,
            );
        } catch (InvalidArgumentException $exception) {
            return $this->respondUnprocessable(errors: ['approval' => [$exception->getMessage()]]);
        }

        $this->securityAudit->record('loan.approval.decided', actor: $actor, subject: $loan, properties: [
            'step' => $step,
            'decision' => $validated['decision'],
        ], request: $request);

        return $this->respondSuccess([
            'loan' => LoanResource::make($result['loan']->loadMissing($this->relations())),
            'approval' => $this->approvalPayload($result['approval']->loadMissing('actedBy')),
        ], 'Loan approval decision recorded successfully');
    }

    public function transitionStatus(Request $request, Loan $loan): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || $actor->cannot('update', $loan)) {
            return $this->respondForbidden();
        }

        if (! $actor->hasRole('platform-admin') && ! $actor->can('loans.status.transition')) {
            return $this->respondForbidden('Loan status transition is outside your permission set.');
        }

        if (! $this->canAccessLoanAgency($actor, $loan)) {
            return $this->respondForbidden('Loan is outside your agency scope.');
        }

        $validated = Validator::make($request->all(), [
            'to_status' => ['required', Rule::in(array_keys(TransitionLoanStatus::allowedTransitions()))],
            'reason' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ])->validate();

        try {
            $transition = $this->transitionLoanStatus->handle(
                $loan,
                $actor,
                (string) $validated['to_status'],
                is_string($validated['reason'] ?? null) ? $validated['reason'] : null,
                is_string($validated['notes'] ?? null) ? $validated['notes'] : null,
            );
        } catch (InvalidArgumentException $exception) {
            return $this->respondUnprocessable(errors: ['status' => [$exception->getMessage()]]);
        }

        $this->securityAudit->record('loan.status.transitioned', actor: $actor, subject: $loan, properties: [
            'from_status' => $transition->from_status,
            'to_status' => $transition->to_status,
        ], request: $request);

        return $this->respondSuccess([
            'loan' => LoanResource::make($loan->refresh()->loadMissing($this->relations())),
            'transition' => $this->transitionPayload($transition),
        ], 'Loan status transitioned successfully');
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
    private function approvalPayload(LoanApproval $approval): array
    {
        return [
            'public_id' => $approval->public_id,
            'step' => $approval->step,
            'decision' => $approval->decision,
            'acted_by_user_public_id' => $approval->actedBy?->public_id,
            'acted_at' => $this->formatDate($approval->acted_at),
            'comments' => $approval->comments,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function transitionPayload(LoanStatusTransition $transition): array
    {
        return [
            'public_id' => $transition->public_id,
            'from_status' => $transition->from_status,
            'to_status' => $transition->to_status,
            'decision' => $transition->decision,
            'reason' => $transition->reason,
            'notes' => $transition->notes,
            'transitioned_at' => $this->formatDate($transition->transitioned_at),
        ];
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

    private function formatDate(mixed $value): ?string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format(DateTimeInterface::ATOM);
        }

        return is_string($value) && $value !== '' ? $value : null;
    }
}
