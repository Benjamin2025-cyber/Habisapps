<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Application\Loans\RecoverLoanFromAccounts;
use App\Http\Controllers\BaseController;
use App\Http\Resources\LoanRecoveryAttemptResource;
use App\Http\Resources\LoanResource;
use App\Models\Loan;
use App\Models\LoanRecoveryAttempt;
use App\Models\User;
use App\Support\Finance\FormulaPolicyNotApproved;
use App\Support\Security\SecurityAudit;
use App\Support\Staff\StaffAgencyScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;

final class LoanRecoveryController extends BaseController
{
    public function __construct(
        private readonly RecoverLoanFromAccounts $recoverLoanFromAccounts,
        private readonly SecurityAudit $securityAudit,
        private readonly StaffAgencyScope $staffAgencyScope,
    ) {}

    public function index(Request $request, Loan $loan): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || ! $this->canUseRecoveries($actor) || ! $this->canAccessLoanAgency($actor, $loan)) {
            return $this->respondForbidden();
        }

        $attemptRows = DB::table('loan_recovery_attempts')
            ->select('id')
            ->where('loan_id', $loan->id)
            ->orderByDesc('attempted_at')
            ->orderByDesc('id')
            ->paginate(min(max($request->integer('per_page', 25), 1), 100));
        $ids = collect($attemptRows->items())
            ->map(function (mixed $row): mixed {
                $data = is_object($row) ? (array) $row : [];

                return $data['id'] ?? null;
            })
            ->filter(fn (mixed $id): bool => is_int($id))
            ->values();
        $models = LoanRecoveryAttempt::query()
            ->with(['loan', 'recoveryAccount', 'customerAccount', 'journalEntry'])
            ->findMany($ids->all())
            ->keyBy('id');
        $attempts = $ids
            ->map(fn (int $id): mixed => $models->get($id))
            ->filter(fn (mixed $attempt): bool => $attempt instanceof LoanRecoveryAttempt)
            ->values();

        return $this->respondSuccess([
            'recovery_attempts' => LoanRecoveryAttemptResource::collection($attempts),
        ], meta: [
            'pagination' => [
                'current_page' => $attemptRows->currentPage(),
                'per_page' => $attemptRows->perPage(),
                'total' => $attemptRows->total(),
                'last_page' => $attemptRows->lastPage(),
            ],
        ]);
    }

    public function store(Request $request, Loan $loan): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || ! $this->canUseRecoveries($actor) || ! $this->canAccessLoanAgency($actor, $loan)) {
            return $this->respondForbidden();
        }

        $validated = Validator::make($request->all(), [
            'requested_amount_minor' => ['required', 'integer', 'min:1'],
            'recovered_on' => ['sometimes', 'nullable', 'date'],
        ])->validate();

        try {
            $result = $this->recoverLoanFromAccounts->handle(
                $loan,
                $actor,
                $this->intValue($validated['requested_amount_minor'] ?? 0),
                is_string($validated['recovered_on'] ?? null) ? $validated['recovered_on'] : null,
            );
        } catch (FormulaPolicyNotApproved $exception) {
            return $this->respondUnprocessable(errors: ['repayment_allocation_order' => [$exception->getMessage()]]);
        } catch (InvalidArgumentException $exception) {
            return $this->respondUnprocessable(errors: ['recovery' => [$exception->getMessage()]]);
        }

        $this->securityAudit->record('loan.recovery.attempted', actor: $actor, subject: $loan, properties: [
            'requested_amount_minor' => $result['requested_amount_minor'],
            'recovered_amount_minor' => $result['recovered_amount_minor'],
            'remaining_amount_minor' => $result['remaining_amount_minor'],
        ], request: $request);

        return $this->respondSuccess([
            'loan' => LoanResource::make($result['loan']->loadMissing(['client', 'agency', 'loanProduct', 'creditAgent', 'recoveryAccount'])),
            'requested_amount_minor' => $result['requested_amount_minor'],
            'recovered_amount_minor' => $result['recovered_amount_minor'],
            'remaining_amount_minor' => $result['remaining_amount_minor'],
            'attempts' => LoanRecoveryAttemptResource::collection(collect($result['attempts'])),
        ], 'Loan recovery attempted successfully');
    }

    private function canUseRecoveries(User $actor): bool
    {
        return $actor->hasRole('platform-admin') || $actor->hasPermissionTo('loans.recoveries.manage');
    }

    private function canAccessLoanAgency(User $actor, Loan $loan): bool
    {
        return $actor->hasRole('platform-admin')
            || $actor->can('crm.scope.institution.read')
            || $this->staffAgencyScope->currentAgencyId($actor) === $loan->agency_id;
    }

    private function intValue(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }

        return is_string($value) && is_numeric($value) ? (int) $value : 0;
    }
}
