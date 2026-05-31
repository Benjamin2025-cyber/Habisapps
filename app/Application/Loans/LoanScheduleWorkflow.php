<?php

declare(strict_types=1);

namespace App\Application\Loans;

use App\Http\Controllers\BaseController;
use App\Http\Resources\LoanResource;
use App\Models\Loan;
use App\Models\LoanScheduleSnapshot;
use App\Models\User;
use App\Support\Finance\FormulaPolicyNotApproved;
use App\Support\Security\SecurityAudit;
use App\Support\Staff\StaffAgencyScope;
use DateTimeInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;

final class LoanScheduleWorkflow extends BaseController
{
    public function __construct(
        private readonly SecurityAudit $securityAudit,
        private readonly StaffAgencyScope $staffAgencyScope,
        private readonly GenerateLoanSchedule $generateLoanSchedule,
        private readonly RescheduleLoan $rescheduleLoan,
    ) {}

    public function showActiveSchedule(Request $request, Loan $loan): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || $actor->cannot('view', $loan)) {
            return $this->respondForbidden();
        }

        if (! $this->canAccessLoanAgency($actor, $loan)) {
            return $this->respondForbidden('Loan is outside your agency scope.');
        }

        $snapshot = LoanScheduleSnapshot::query()
            ->where('loan_id', $loan->id)
            ->where('status', LoanScheduleSnapshot::STATUS_ACTIVE)
            ->with('lines')
            ->latest('id')
            ->first();

        if (! $snapshot instanceof LoanScheduleSnapshot) {
            return $this->respondNotFound('Loan has no active schedule.');
        }

        return $this->respondSuccess([
            'snapshot' => $this->schedulePayload($snapshot),
        ]);
    }

    public function generateSchedule(Request $request, Loan $loan): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || $actor->cannot('update', $loan)) {
            return $this->respondForbidden();
        }

        if (! $actor->hasRole('platform-admin') && ! $actor->can('loans.schedules.generate')) {
            return $this->respondForbidden('Loan schedule generation is outside your permission set.');
        }

        if (! $this->canAccessLoanAgency($actor, $loan)) {
            return $this->respondForbidden('Loan is outside your agency scope.');
        }

        try {
            $snapshot = $this->generateLoanSchedule->handle($loan, $actor);
        } catch (FormulaPolicyNotApproved $exception) {
            return $this->respondUnprocessable(errors: ['formula_policy' => [$exception->getMessage()]]);
        } catch (InvalidArgumentException $exception) {
            return $this->respondUnprocessable(errors: ['schedule' => [$exception->getMessage()]]);
        }

        $this->securityAudit->record('loan.schedule.generated', actor: $actor, subject: $loan, properties: [
            'schedule_snapshot_public_id' => $snapshot->public_id,
            'policy_snapshot_hash' => $snapshot->policy_snapshot_hash,
        ], request: $request);

        return $this->respondSuccess([
            'snapshot' => $this->schedulePayload($snapshot),
        ], 'Loan schedule generated successfully');
    }

    public function reschedule(Request $request, Loan $loan): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || $actor->cannot('update', $loan)) {
            return $this->respondForbidden();
        }

        if (! $actor->hasRole('platform-admin') && ! $actor->can('loans.schedules.reschedule')) {
            return $this->respondForbidden('Loan rescheduling is outside your permission set.');
        }

        if (! $this->canAccessLoanAgency($actor, $loan)) {
            return $this->respondForbidden('Loan is outside your agency scope.');
        }

        $validated = Validator::make($request->all(), [
            'first_installment_date' => ['required', 'date'],
            'number_of_installments' => ['required', 'integer', 'min:1'],
            'grace_period_duration' => ['nullable', 'integer', 'min:0'],
            'total_loan_duration' => ['nullable', 'integer', 'min:1'],
            'capitalized_interest_minor' => ['nullable', 'integer', 'min:0'],
            'capitalized_penalties_minor' => ['nullable', 'integer', 'min:0'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ])->validate();

        try {
            $snapshot = $this->rescheduleLoan->handle($loan, $actor, $validated);
        } catch (FormulaPolicyNotApproved $exception) {
            return $this->respondUnprocessable(errors: ['formula_policy' => [$exception->getMessage()]]);
        } catch (InvalidArgumentException $exception) {
            return $this->respondUnprocessable(errors: ['reschedule' => [$exception->getMessage()]]);
        }

        $this->securityAudit->record('loan.schedule.rescheduled', actor: $actor, subject: $loan, properties: [
            'schedule_snapshot_public_id' => $snapshot->public_id,
            'policy_snapshot_hash' => $snapshot->policy_snapshot_hash,
        ], request: $request);

        return $this->respondSuccess([
            'loan' => LoanResource::make($loan->refresh()->loadMissing($this->relations())),
            'snapshot' => $this->schedulePayload($snapshot),
        ], 'Loan schedule rescheduled successfully');
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
    private function schedulePayload(LoanScheduleSnapshot $snapshot): array
    {
        $snapshot->loadMissing(['loan', 'lines']);

        $lines = [];
        foreach ($snapshot->lines->sortBy('installment_number')->values() as $line) {
            $lines[] = [
                'installment_number' => $line->installment_number,
                'due_date' => $this->formatDateOnly($line->due_date),
                'principal_minor' => $line->principal_minor,
                'interest_minor' => $line->interest_minor,
                'fees_minor' => $line->fees_minor,
                'insurance_minor' => $line->insurance_minor,
                'tax_minor' => $line->tax_minor,
                'penalty_minor' => $line->penalty_minor,
                'capitalized_interest_minor' => $line->capitalized_interest_minor,
                'remaining_principal_minor' => $line->remaining_principal_minor,
                'total_installment_minor' => $line->total_installment_minor,
                'currency' => $line->currency,
                'status' => $line->status,
            ];
        }

        return [
            'public_id' => $snapshot->public_id,
            'loan_public_id' => $snapshot->loan?->public_id,
            'formula_engine_key' => $snapshot->formula_engine_key,
            'formula_engine_version' => $snapshot->formula_engine_version,
            'policy_snapshot_hash' => $snapshot->policy_snapshot_hash,
            'generated_at' => $this->formatDate($snapshot->generated_at),
            'status' => $snapshot->status,
            'lines' => $lines,
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

    private function formatDateOnly(mixed $value): ?string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        return is_string($value) && $value !== '' ? substr($value, 0, 10) : null;
    }
}
