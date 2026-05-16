<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\BaseController;
use App\Http\Resources\LoanResource;
use App\Http\Resources\LoanTransferResource;
use App\Models\Loan;
use App\Models\LoanTransfer;
use App\Models\User;
use App\Support\Security\SecurityAudit;
use App\Support\Staff\StaffAgencyScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

final class LoanTransferController extends BaseController
{
    public function __construct(
        private readonly SecurityAudit $securityAudit,
        private readonly StaffAgencyScope $staffAgencyScope,
    ) {}

    public function index(Request $request, Loan $loan): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || ! $this->canUseTransfers($actor) || ! $this->canAccessLoanAgency($actor, $loan)) {
            return $this->respondForbidden();
        }

        $transferRows = DB::table('loan_transfers')
            ->select('id')
            ->where('loan_id', $loan->id)
            ->orderByDesc('transfer_date')
            ->orderByDesc('id')
            ->paginate(min(max($request->integer('per_page', 25), 1), 100));
        $ids = collect($transferRows->items())
            ->map(function (mixed $row): mixed {
                $data = is_object($row) ? (array) $row : [];

                return $data['id'] ?? null;
            })
            ->filter(fn (mixed $id): bool => is_int($id))
            ->values();
        $models = LoanTransfer::query()
            ->with(['agency', 'loan', 'initialManager', 'newManager', 'approvedBy'])
            ->findMany($ids->all())
            ->keyBy('id');
        $transfers = $ids
            ->map(fn (int $id): mixed => $models->get($id))
            ->filter(fn (mixed $transfer): bool => $transfer instanceof LoanTransfer)
            ->values();

        return $this->respondSuccess([
            'loan_transfers' => LoanTransferResource::collection($transfers),
        ], meta: [
            'pagination' => [
                'current_page' => $transferRows->currentPage(),
                'per_page' => $transferRows->perPage(),
                'total' => $transferRows->total(),
                'last_page' => $transferRows->lastPage(),
            ],
        ]);
    }

    public function store(Request $request, Loan $loan): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || ! $this->canUseTransfers($actor) || ! $this->canAccessLoanAgency($actor, $loan)) {
            return $this->respondForbidden();
        }

        if (in_array($loan->status, [Loan::STATUS_REJECTED, Loan::STATUS_CLOSED, Loan::STATUS_WRITTEN_OFF], true)) {
            return $this->respondUnprocessable(errors: ['loan' => ['Closed, rejected, or written-off loans cannot be transferred.']]);
        }

        if ($loan->credit_agent_id === null) {
            return $this->respondUnprocessable(errors: ['loan' => ['Loan must have a current manager before transfer.']]);
        }

        $validated = Validator::make($request->all(), [
            'new_manager_public_id' => ['required', 'string', 'exists:users,public_id'],
            'transfer_reason' => ['required', 'string', 'max:255'],
            'transfer_date' => ['sometimes', 'nullable', 'date'],
        ])->validate();

        $newManager = User::query()
            ->where('public_id', $validated['new_manager_public_id'])
            ->first();
        if (! $newManager instanceof User
            || $newManager->status !== User::STATUS_ACTIVE
            || ! in_array($newManager->id, $this->staffAgencyScope->currentAgencyStaffIdList($loan->agency_id), true)) {
            return $this->respondUnprocessable(errors: ['new_manager_public_id' => ['New manager must be active and assigned to the loan agency.']]);
        }

        if ($newManager->id === $loan->credit_agent_id) {
            return $this->respondUnprocessable(errors: ['new_manager_public_id' => ['New manager must be different from the current manager.']]);
        }

        $transfer = DB::transaction(function () use ($actor, $loan, $newManager, $validated): LoanTransfer {
            DB::table('loans')->where('id', $loan->id)->lockForUpdate()->first();
            $lockedLoan = Loan::query()->whereKey($loan->id)->firstOrFail();
            if ($lockedLoan->credit_agent_id === null) {
                throw new \InvalidArgumentException('Loan must have a current manager before transfer.');
            }

            $transfer = LoanTransfer::query()->create([
                'public_id' => (string) Str::ulid(),
                'agency_id' => $lockedLoan->agency_id,
                'loan_id' => $lockedLoan->id,
                'initial_manager_id' => $lockedLoan->credit_agent_id,
                'new_manager_id' => $newManager->id,
                'transfer_reason' => $validated['transfer_reason'],
                'transfer_date' => is_string($validated['transfer_date'] ?? null) ? $validated['transfer_date'] : now()->toDateString(),
                'approved_by_user_id' => $actor->id,
            ]);

            $lockedLoan->forceFill(['credit_agent_id' => $newManager->id])->save();

            return $transfer;
        });

        $this->securityAudit->record('loan.transfer.created', actor: $actor, subject: $transfer, request: $request);

        return $this->respondCreated([
            'loan' => LoanResource::make($loan->refresh()->loadMissing(['client', 'agency', 'loanProduct', 'creditAgent'])),
            'transfer' => LoanTransferResource::make($transfer->loadMissing(['agency', 'loan', 'initialManager', 'newManager', 'approvedBy'])),
        ], 'Loan transfer created successfully');
    }

    private function canUseTransfers(User $actor): bool
    {
        return $actor->hasRole('platform-admin') || $actor->hasPermissionTo('loans.transfers.manage');
    }

    private function canAccessLoanAgency(User $actor, Loan $loan): bool
    {
        return $actor->hasRole('platform-admin')
            || $actor->can('crm.scope.institution.read')
            || $this->staffAgencyScope->currentAgencyId($actor) === $loan->agency_id;
    }
}
