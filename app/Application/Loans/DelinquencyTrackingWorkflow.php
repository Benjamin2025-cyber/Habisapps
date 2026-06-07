<?php

declare(strict_types=1);

namespace App\Application\Loans;

use App\Http\Controllers\BaseController;
use App\Http\Resources\DelinquencyTrackingResource;
use App\Models\DelinquencyTracking;
use App\Models\Loan;
use App\Models\User;
use App\Support\Security\SecurityAudit;
use App\Support\Staff\StaffAgencyScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

final class DelinquencyTrackingWorkflow extends BaseController
{
    public function __construct(
        private readonly SecurityAudit $securityAudit,
        private readonly StaffAgencyScope $staffAgencyScope,
    ) {}

    public function index(Request $request, Loan $loan): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || ! $this->canUseDelinquencyTracking($actor) || ! $this->canAccessLoanAgency($actor, $loan)) {
            return $this->respondForbidden();
        }

        $query = DB::table('delinquency_trackings')
            ->select('id')
            ->where('loan_id', $loan->id);
        $search = $request->query('search');
        if (is_string($search) && trim($search) !== '') {
            $term = trim($search);
            $query->where(function ($builder) use ($term): void {
                $builder->where('reason_code', 'ilike', '%'.$term.'%')
                    ->orWhere('appointment_type', 'ilike', '%'.$term.'%')
                    ->orWhere('comments', 'ilike', '%'.$term.'%')
                    ->orWhere('currency', 'ilike', '%'.$term.'%')
                    ->orWhere('tracking_date', 'ilike', '%'.$term.'%');
            });
        }

        $trackingRows = $query
            ->orderByDesc('tracking_date')
            ->orderByDesc('id')
            ->paginate(min(max($request->integer('per_page', 25), 1), 100));
        $ids = collect($trackingRows->items())
            ->map(function (mixed $row): mixed {
                $data = is_object($row) ? (array) $row : [];

                return $data['id'] ?? null;
            })
            ->filter(fn (mixed $id): bool => is_int($id))
            ->values();
        $models = DelinquencyTracking::query()
            ->with(['client', 'loan', 'agency', 'createdBy'])
            ->findMany($ids->all())
            ->keyBy('id');
        $trackings = $ids
            ->map(fn (int $id): mixed => $models->get($id))
            ->filter(fn (mixed $tracking): bool => $tracking instanceof DelinquencyTracking)
            ->values();

        return $this->respondSuccess([
            'delinquency_trackings' => DelinquencyTrackingResource::collection($trackings),
        ], meta: [
            'pagination' => [
                'current_page' => $trackingRows->currentPage(),
                'per_page' => $trackingRows->perPage(),
                'total' => $trackingRows->total(),
                'last_page' => $trackingRows->lastPage(),
            ],
        ]);
    }

    public function store(Request $request, Loan $loan): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || ! $this->canUseDelinquencyTracking($actor) || ! $this->canAccessLoanAgency($actor, $loan)) {
            return $this->respondForbidden();
        }

        if (! in_array($loan->status, [Loan::STATUS_DISBURSED, Loan::STATUS_ACTIVE, Loan::STATUS_RESCHEDULED], true)) {
            return $this->respondUnprocessable(errors: ['loan' => [__('Delinquency tracking requires a disbursed, active, or rescheduled loan.')]]);
        }

        $validated = $this->validatedPayload($request);
        $tracking = DelinquencyTracking::query()->create([
            'public_id' => (string) Str::ulid(),
            'client_id' => $loan->client_id,
            'loan_id' => $loan->id,
            'agency_id' => $loan->agency_id,
            'tracking_date' => $validated['tracking_date'] ?? now()->toDateString(),
            'reason_code' => $validated['reason_code'] ?? null,
            'appointment_type' => $validated['appointment_type'] ?? null,
            'appointment_date' => $validated['appointment_date'] ?? null,
            'promised_amount_minor' => $validated['promised_amount_minor'] ?? null,
            'currency' => $validated['currency'] ?? $loan->currency,
            'comments' => $validated['comments'] ?? null,
            'created_by_user_id' => $actor->id,
        ]);

        $this->securityAudit->record('loan.delinquency_tracking.created', actor: $actor, subject: $tracking, request: $request);

        return $this->respondCreated(
            DelinquencyTrackingResource::make($tracking->loadMissing(['client', 'loan', 'agency', 'createdBy'])),
            'Delinquency tracking created successfully'
        );
    }

    public function update(Request $request, Loan $loan, DelinquencyTracking $delinquencyTracking): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User
            || ! $this->canUseDelinquencyTracking($actor)
            || ! $this->canAccessLoanAgency($actor, $loan)
            || $delinquencyTracking->loan_id !== $loan->id
            || $delinquencyTracking->agency_id !== $loan->agency_id) {
            return $this->respondForbidden();
        }

        $delinquencyTracking->fill($this->validatedPayload($request, updating: true))->save();

        $this->securityAudit->record('loan.delinquency_tracking.updated', actor: $actor, subject: $delinquencyTracking, request: $request);

        return $this->respondSuccess(
            DelinquencyTrackingResource::make($delinquencyTracking->refresh()->loadMissing(['client', 'loan', 'agency', 'createdBy'])),
            'Delinquency tracking updated successfully'
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedPayload(Request $request, bool $updating = false): array
    {
        $presence = $updating ? 'sometimes' : 'required';

        return Validator::make($request->all(), [
            'tracking_date' => [$presence, 'date'],
            'reason_code' => ['sometimes', 'nullable', 'string', 'max:64'],
            'appointment_type' => ['sometimes', 'nullable', 'string', 'max:64'],
            'appointment_date' => ['sometimes', 'nullable', 'date'],
            'promised_amount_minor' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'currency' => ['sometimes', 'nullable', 'string', 'size:3'],
            'comments' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ])->validate();
    }

    private function canUseDelinquencyTracking(User $actor): bool
    {
        return $actor->hasRole('platform-admin') || $actor->hasPermissionTo('loans.delinquency.manage');
    }

    private function canAccessLoanAgency(User $actor, Loan $loan): bool
    {
        return $actor->hasRole('platform-admin')
            || $actor->can('crm.scope.institution.read')
            || $this->staffAgencyScope->currentAgencyId($actor) === $loan->agency_id;
    }
}
