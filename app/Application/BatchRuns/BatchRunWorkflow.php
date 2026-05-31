<?php

declare(strict_types=1);

namespace App\Application\BatchRuns;

use App\Http\Controllers\BaseController;
use App\Http\Resources\BatchRunCollection;
use App\Http\Resources\BatchRunResource;
use App\Models\Agency;
use App\Models\BatchProcedure;
use App\Models\BatchRun;
use App\Models\User;
use App\Support\Finance\FormulaPolicyNotApproved;
use App\Support\Security\SecurityAudit;
use App\Support\Staff\StaffAgencyScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use JsonException;

final class BatchRunWorkflow extends BaseController
{
    public function __construct(
        private readonly SecurityAudit $securityAudit,
        private readonly StaffAgencyScope $staffAgencyScope,
        private readonly UpdateBatchRunStatus $updateBatchRunStatus,
        private readonly ExecuteRegisteredBatchRun $executeRegisteredBatchRun,
    ) {}

    public function index(Request $request): BatchRunCollection
    {
        $this->authorize('viewAny', BatchRun::class);

        $perPage = min(max($request->integer('per_page', 25), 1), 100);
        $query = BatchRun::query()->with(['batchProcedure', 'agency', 'operator'])->latest();
        $actor = $request->user();

        if (! $actor instanceof User) {
            return new BatchRunCollection($query->where('id', -1)->paginate($perPage));
        }

        if (! $actor->can('batch.runs.manage')) {
            $agencyId = $this->staffAgencyScope->currentAgencyId($actor);
            if ($agencyId === null) {
                return new BatchRunCollection($query->where('id', -1)->paginate($perPage));
            }

            $query->where('agency_id', $agencyId);
        }

        $batchProcedurePublicId = $request->query('batch_procedure_public_id');
        if (is_string($batchProcedurePublicId) && $batchProcedurePublicId !== '') {
            $procedure = BatchProcedure::query()->where('public_id', $batchProcedurePublicId)->first();
            if ($procedure !== null) {
                $query->where('batch_procedure_id', $procedure->id);
            }
        }

        $status = $request->query('status');
        if (is_string($status) && $status !== '') {
            if (in_array($status, [
                BatchRun::STATUS_PENDING,
                BatchRun::STATUS_RUNNING,
                BatchRun::STATUS_SUCCEEDED,
                BatchRun::STATUS_FAILED,
                BatchRun::STATUS_CANCELLED,
            ], true)) {
                $query->where('status', $status);
            }
        }

        $businessDate = $request->query('business_date');
        if (is_string($businessDate) && $businessDate !== '') {
            $query->where('business_date', $businessDate);
        }

        $businessDateFrom = $request->query('business_date_from');
        if (is_string($businessDateFrom) && $businessDateFrom !== '') {
            $query->where('business_date', '>=', $businessDateFrom);
        }

        $businessDateTo = $request->query('business_date_to');
        if (is_string($businessDateTo) && $businessDateTo !== '') {
            $query->where('business_date', '<=', $businessDateTo);
        }

        $agencyCode = $request->query('agency_code');
        if (is_string($agencyCode) && $agencyCode !== '' && $actor->can('batch.runs.manage')) {
            $agency = Agency::query()->where('code', $agencyCode)->first();
            if ($agency !== null) {
                $query->where('agency_id', $agency->id);
            }
        }

        $search = $request->query('search');
        if (is_string($search) && trim($search) !== '') {
            $term = trim($search);
            $query->where(function (Builder $builder) use ($term): void {
                $builder
                    ->where('public_id', 'ilike', '%'.$term.'%')
                    ->orWhere('status', 'ilike', '%'.$term.'%')
                    ->orWhereHas('batchProcedure', function (Builder $procedureQuery) use ($term): void {
                        $procedureQuery
                            ->where('code', 'ilike', '%'.$term.'%')
                            ->orWhere('name', 'ilike', '%'.$term.'%');
                    })
                    ->orWhereHas('agency', function (Builder $agencyQuery) use ($term): void {
                        $agencyQuery
                            ->where('code', 'ilike', '%'.$term.'%')
                            ->orWhere('name', 'ilike', '%'.$term.'%');
                    });
            });
        }

        return new BatchRunCollection($query->paginate($perPage));
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', BatchRun::class);

        $validated = Validator::make($request->all(), [
            'batch_procedure_public_id' => ['required', 'string', 'exists:batch_procedures,public_id'],
            'business_date' => ['required', 'date'],
            'agency_code' => ['nullable', 'string', 'exists:agencies,code'],
            'idempotency_key' => ['nullable', 'string', 'max:128'],
            'summary_payload' => ['nullable', 'array'],
        ])->validate();

        $procedure = BatchProcedure::query()->where('public_id', $validated['batch_procedure_public_id'])->firstOrFail();
        $agency = null;
        if (isset($validated['agency_code'])) {
            $agency = Agency::query()->where('code', $validated['agency_code'])->first();
        }

        $actorContext = $this->actorContext($request);
        $scopeHash = $this->scopeHash($request, $validated['idempotency_key'] ?? null, $actorContext);
        $fingerprint = $this->fingerprint($request, $validated);

        $existing = null;
        if (isset($validated['idempotency_key'])) {
            $existing = BatchRun::query()
                ->with(['batchProcedure', 'agency', 'operator'])
                ->where('scope_hash', $scopeHash)
                ->first();

            if ($existing !== null) {
                if ($existing->request_fingerprint === null) {
                    return $this->respondError('Idempotency-Key has already been used for a different request.', null, 409);
                }

                if (! hash_equals($existing->request_fingerprint, $fingerprint)) {
                    return $this->respondError('Idempotency-Key has already been used for a different request.', null, 409);
                }

                return $this->respondSuccess(
                    BatchRunResource::make($existing),
                    'Batch run already exists'
                );
            }

            $legacyExisting = DB::table('batch_runs')
                ->where('idempotency_key', $validated['idempotency_key'])
                ->whereNull('scope_hash')
                ->first();

            if ($legacyExisting !== null) {
                return $this->respondError('Idempotency-Key has already been used for a different request.', null, 409);
            }
        }

        $existingRun = BatchRun::query()
            ->with(['batchProcedure', 'agency', 'operator'])
            ->where('batch_procedure_id', $procedure->id)
            ->where('agency_id', $agency?->id)
            ->where('business_date', $validated['business_date'])
            ->first();

        if ($existingRun !== null) {
            return $this->respondSuccess(
                BatchRunResource::make($existingRun),
                'Batch run already exists'
            );
        }

        $actor = $request->user();
        if (! $actor instanceof User) {
            return $this->respondForbidden();
        }

        $run = BatchRun::query()->create([
            'public_id' => (string) Str::ulid(),
            'batch_procedure_id' => $procedure->id,
            'agency_id' => $agency?->id,
            'business_date' => $validated['business_date'],
            'status' => BatchRun::STATUS_PENDING,
            'operator_user_id' => $actor->id,
            'actor_context' => $actorContext,
            'scope_hash' => isset($validated['idempotency_key']) ? $scopeHash : null,
            'idempotency_key' => $validated['idempotency_key'] ?? null,
            'request_fingerprint' => isset($validated['idempotency_key']) ? $fingerprint : null,
            'summary_payload' => $validated['summary_payload'] ?? null,
        ])->load(['batchProcedure', 'agency', 'operator']);

        $this->securityAudit->record('batch.run.created', actor: $actor, subject: $run, request: $request);

        return $this->respondCreated(BatchRunResource::make($run), 'Batch run created successfully');
    }

    public function show(Request $request, BatchRun $batchRun): JsonResponse
    {
        $this->authorize('view', $batchRun);

        return $this->respondSuccess(
            BatchRunResource::make($batchRun->loadMissing(['batchProcedure', 'agency', 'operator']))
        );
    }

    public function updateStatus(Request $request, BatchRun $batchRun): JsonResponse
    {
        $this->authorize('updateStatus', $batchRun);

        $validated = Validator::make($request->all(), [
            'status' => ['required', 'string', Rule::in([
                BatchRun::STATUS_PENDING,
                BatchRun::STATUS_RUNNING,
                BatchRun::STATUS_SUCCEEDED,
                BatchRun::STATUS_FAILED,
                BatchRun::STATUS_CANCELLED,
            ])],
            'summary_payload' => ['sometimes', 'nullable', 'array'],
            'failure_reason' => ['sometimes', 'nullable', 'string'],
        ])->validate();

        $requestedStatus = $validated['status'];
        if ($batchRun->status !== $requestedStatus && $this->isTerminalStatus($batchRun->status)) {
            return $this->respondUnprocessable('Completed batch runs cannot be changed.');
        }

        $allowedTransitions = [
            BatchRun::STATUS_PENDING => [BatchRun::STATUS_RUNNING, BatchRun::STATUS_FAILED],
            BatchRun::STATUS_RUNNING => [BatchRun::STATUS_SUCCEEDED, BatchRun::STATUS_FAILED],
            BatchRun::STATUS_FAILED => [BatchRun::STATUS_PENDING, BatchRun::STATUS_RUNNING],
            BatchRun::STATUS_CANCELLED => [BatchRun::STATUS_PENDING],
            BatchRun::STATUS_SUCCEEDED => [],
        ];

        if ($batchRun->status !== $requestedStatus && ! in_array($requestedStatus, $allowedTransitions[$batchRun->status] ?? [], true)) {
            return $this->respondUnprocessable('Invalid batch run status transition.');
        }

        $updates = [
            'status' => $requestedStatus,
            'summary_payload' => $validated['summary_payload'] ?? $batchRun->summary_payload,
            'failure_reason' => $validated['failure_reason'] ?? $batchRun->failure_reason,
        ];

        if ($requestedStatus === BatchRun::STATUS_RUNNING && $batchRun->started_at === null) {
            $updates['started_at'] = now();
        }

        if (in_array($requestedStatus, [BatchRun::STATUS_SUCCEEDED, BatchRun::STATUS_FAILED], true) && $batchRun->finished_at === null) {
            $updates['finished_at'] = now();
        }

        if ($requestedStatus === BatchRun::STATUS_FAILED && ! array_key_exists('failure_reason', $validated)) {
            throw ValidationException::withMessages(['failure_reason' => ['A failure reason is required for failed runs.']]);
        }

        $run = $this->updateBatchRunStatus->execute($batchRun, $updates);

        $this->securityAudit->record('batch.run.status_changed', actor: $request->user(), subject: $run, properties: [
            'status' => $run->status,
        ], request: $request);

        return $this->respondSuccess(BatchRunResource::make($run), 'Batch run status updated successfully');
    }

    public function execute(Request $request, BatchRun $batchRun): JsonResponse
    {
        $this->authorize('execute', $batchRun);

        try {
            $run = $this->executeRegisteredBatchRun->execute($batchRun);
        } catch (FormulaPolicyNotApproved $exception) {
            return $this->respondUnprocessable($exception->getMessage());
        } catch (InvalidArgumentException $exception) {
            return $this->respondUnprocessable($exception->getMessage());
        } catch (QueryException $exception) {
            if ($this->isUniqueConstraintViolation($exception)) {
                return $this->respondError('A batch run is already executing for this procedure, agency, and business date.', null, 409);
            }

            throw $exception;
        }

        $this->securityAudit->record('batch.run.executed', actor: $request->user(), subject: $run, properties: [
            'status' => $run->status,
            'summary_payload' => $run->summary_payload,
        ], request: $request);

        return $this->respondSuccess(BatchRunResource::make($run), 'Batch run executed successfully');
    }

    public function retry(Request $request, BatchRun $batchRun): JsonResponse
    {
        $this->authorize('retry', $batchRun);

        if (! in_array($batchRun->status, [BatchRun::STATUS_FAILED, BatchRun::STATUS_CANCELLED], true)) {
            return $this->respondUnprocessable('Only failed or cancelled batch runs can be retried.');
        }

        $run = $this->updateBatchRunStatus->execute($batchRun, [
            'status' => BatchRun::STATUS_PENDING,
            'started_at' => null,
            'finished_at' => null,
            'failure_reason' => null,
        ]);

        $this->securityAudit->record('batch.run.retry_requested', actor: $request->user(), subject: $run, request: $request);

        return $this->respondSuccess(BatchRunResource::make($run), 'Batch run retry requested successfully');
    }

    public function cancel(Request $request, BatchRun $batchRun): JsonResponse
    {
        $this->authorize('cancel', $batchRun);

        if ($batchRun->status !== BatchRun::STATUS_PENDING || $batchRun->started_at !== null) {
            return $this->respondUnprocessable('Only pending batch runs that have not started can be cancelled.');
        }

        $run = $this->updateBatchRunStatus->execute($batchRun, [
            'status' => BatchRun::STATUS_CANCELLED,
            'failure_reason' => 'Cancelled by operator before execution started.',
            'finished_at' => now(),
        ]);

        $this->securityAudit->record('batch.run.cancelled', actor: $request->user(), subject: $run, request: $request);

        return $this->respondSuccess(BatchRunResource::make($run), 'Batch run cancelled successfully');
    }

    private function isTerminalStatus(string $status): bool
    {
        return in_array($status, [BatchRun::STATUS_SUCCEEDED, BatchRun::STATUS_FAILED, BatchRun::STATUS_CANCELLED], true);
    }

    private function actorContext(Request $request): string
    {
        $user = $request->user();
        $identifier = $user?->getAuthIdentifier();

        if (is_string($identifier) || is_int($identifier)) {
            return 'user:'.$identifier;
        }

        return 'system';
    }

    private function scopeHash(Request $request, ?string $key, string $actorContext): ?string
    {
        if (! is_string($key) || $key === '') {
            return null;
        }

        return hash('sha256', implode('|', [
            $request->method(),
            $request->path(),
            $actorContext,
            $key,
        ]));
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function fingerprint(Request $request, array $validated): string
    {
        $payload = [
            'actor' => $this->actorContext($request),
            'batch_procedure_public_id' => $validated['batch_procedure_public_id'],
            'business_date' => $validated['business_date'],
            'agency_code' => $validated['agency_code'] ?? null,
            'summary_payload' => $this->normalizeForFingerprint($validated['summary_payload'] ?? null),
        ];

        try {
            return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
        } catch (JsonException) {
            return hash('sha256', implode('|', [
                $this->scalarToString($payload['actor']),
                $this->scalarToString($payload['batch_procedure_public_id']),
                $this->scalarToString($payload['business_date']),
                $this->scalarToString($payload['agency_code']),
            ]));
        }
    }

    private function normalizeForFingerprint(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        ksort($value);

        foreach ($value as $key => $item) {
            $value[$key] = $this->normalizeForFingerprint($item);
        }

        return $value;
    }

    private function scalarToString(mixed $value): string
    {
        if (is_string($value) || is_int($value) || is_float($value) || is_bool($value)) {
            return (string) $value;
        }

        return '';
    }

    private function isUniqueConstraintViolation(QueryException $exception): bool
    {
        $sqlState = $exception->errorInfo[0] ?? null;

        return $sqlState === '23505';
    }
}
