<?php

declare(strict_types=1);

namespace App\Application\DatabaseManagement;

use App\Http\Controllers\BaseController;
use App\Http\Requests\Api\V1\DatabaseManagement\ExecuteDatabaseRestoreRequest;
use App\Http\Requests\Api\V1\DatabaseManagement\PlanDatabaseRestoreRequest;
use App\Http\Resources\DatabaseRestoreOperationCollection;
use App\Http\Resources\DatabaseRestoreOperationResource;
use App\Jobs\DatabaseManagement\RunDatabaseRestore;
use App\Models\DatabaseBackup;
use App\Models\DatabaseRestoreOperation;
use App\Models\User;
use App\Support\DatabaseManagement\BackupArtifactStore;
use App\Support\DatabaseManagement\DatabaseManagementConfig;
use App\Support\DatabaseManagement\DatabaseManagementError;
use App\Support\Security\SecurityAudit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Two-step restore: plan (validate + produce a destructive plan, no mutation)
 * then execute (step-up confirmation, guards, async runner). Plus restore
 * history listing, detail, and cancellation (ADM-DB-006/007/008).
 */
final class RestoreWorkflow extends BaseController
{
    public function __construct(
        private readonly DatabaseManagementConfig $config,
        private readonly BackupArtifactStore $store,
        private readonly SecurityAudit $audit,
    ) {}

    public function plan(PlanDatabaseRestoreRequest $request): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User) {
            return $this->respondForbidden();
        }

        $error = $this->config->validateRestore();
        if ($error instanceof DatabaseManagementError) {
            return $this->respondConfigError($error);
        }

        $validated = $request->validated();
        $backup = DatabaseBackup::query()->where('public_id', $validated['backup_public_id'])->first();
        if (! $backup instanceof DatabaseBackup) {
            return $this->respondUnprocessable('The selected backup could not be found.', [
                'code' => 'backup_not_found',
            ]);
        }

        $target = is_string($validated['target'] ?? null) ? $validated['target'] : '';
        $mode = is_string($validated['mode'] ?? null) ? $validated['mode'] : '';

        if ($target === DatabaseRestoreOperation::TARGET_SAME_DATABASE
            && $mode === DatabaseRestoreOperation::MODE_REPLACE
            && $this->config->isProduction()
            && ! $this->config->allowSameDatabaseInProduction()) {
            return $this->respondUnprocessable('Same-database replacement is disabled in production.', [
                'code' => 'same_database_restore_disabled',
            ]);
        }

        if ($mode === DatabaseRestoreOperation::MODE_REPLACE
            && $target !== DatabaseRestoreOperation::TARGET_SAME_DATABASE) {
            return $this->respondUnprocessable('Replacement restore is currently supported only for the same database target.', [
                'code' => 'restore_target_unsupported',
                'target' => $target,
                'mode' => $mode,
            ]);
        }

        $backupError = $this->assertBackupRestorable($backup, verifyArtifact: true);
        if ($backupError instanceof JsonResponse) {
            return $backupError;
        }

        if ($this->config->requireConfirmationPhrase()) {
            $phrase = $validated['confirmation_phrase'] ?? null;
            if (! is_string($phrase) || ! hash_equals($this->config->confirmationPhrase(), $phrase)) {
                return $this->respondUnprocessable('A valid confirmation phrase is required to plan this restore.', [
                    'confirmation_phrase' => ['The confirmation phrase is incorrect.'],
                ]);
            }
        }

        $destructive = $mode === DatabaseRestoreOperation::MODE_REPLACE;
        $expiresAt = Carbon::now()->addMinutes($this->config->planTtlMinutes());

        $operation = DatabaseRestoreOperation::query()->create([
            'public_id' => (string) Str::ulid(),
            'database_backup_id' => $backup->id,
            'status' => DatabaseRestoreOperation::STATUS_PLANNED,
            'target' => $target,
            'mode' => $mode,
            'planned_by_user_id' => $actor->id,
            'expires_at' => $expiresAt,
            'metadata' => [
                'destructive' => $destructive,
                'backup_checksum_sha256' => $backup->checksum_sha256,
            ],
        ]);

        $this->audit->record('database.restore.planned', actor: $actor, subject: $operation, properties: [
            'restore_public_id' => $operation->public_id,
            'backup_public_id' => $backup->public_id,
            'target' => $target,
            'mode' => $mode,
            'destructive' => $destructive,
        ], request: $request);

        $operation->setRelation('backup', $backup);

        return $this->respondSuccess([
            'restore_operation' => DatabaseRestoreOperationResource::make($operation),
            'plan' => [
                'target' => $target,
                'mode' => $mode,
                'backup_checksum_sha256' => $backup->checksum_sha256,
                'destructive' => $destructive,
                'expires_at' => $expiresAt->format(DATE_ATOM),
                // The operation public id is the execution token; execute also
                // requires step-up re-authentication.
                'execution_token' => $operation->public_id,
            ],
        ], 'Restore plan created.', [], Response::HTTP_CREATED);
    }

    public function execute(ExecuteDatabaseRestoreRequest $request, DatabaseRestoreOperation $restoreOperation): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User) {
            return $this->respondForbidden();
        }

        $error = $this->config->validateRestore();
        if ($error instanceof DatabaseManagementError) {
            return $this->respondConfigError($error);
        }

        if ($restoreOperation->status !== DatabaseRestoreOperation::STATUS_PLANNED) {
            return $this->respondUnprocessable('This restore operation has no valid plan to execute.', [
                'code' => 'restore_not_planned',
                'status' => $restoreOperation->status,
            ]);
        }

        if ($restoreOperation->isExpired()) {
            return $this->respondUnprocessable('The restore plan has expired. Create a new plan.', [
                'code' => 'restore_plan_expired',
            ]);
        }

        $backup = $restoreOperation->backup;
        if (! $backup instanceof DatabaseBackup) {
            return $this->respondUnprocessable('The planned backup could not be found.', [
                'code' => 'backup_not_found',
            ]);
        }

        $backupError = $this->assertBackupRestorable($backup, verifyArtifact: true);
        if ($backupError instanceof JsonResponse) {
            return $backupError;
        }

        // Defence in depth: re-assert the production same-database guard at
        // execute time in case the plan was created before config tightened.
        if ($restoreOperation->target === DatabaseRestoreOperation::TARGET_SAME_DATABASE
            && $restoreOperation->mode === DatabaseRestoreOperation::MODE_REPLACE
            && $this->config->isProduction()
            && ! $this->config->allowSameDatabaseInProduction()) {
            return $this->respondUnprocessable('Same-database replacement is disabled in production.', [
                'code' => 'same_database_restore_disabled',
            ]);
        }

        if ($restoreOperation->mode === DatabaseRestoreOperation::MODE_REPLACE
            && $restoreOperation->target !== DatabaseRestoreOperation::TARGET_SAME_DATABASE) {
            return $this->respondUnprocessable('Replacement restore is currently supported only for the same database target.', [
                'code' => 'restore_target_unsupported',
                'target' => $restoreOperation->target,
                'mode' => $restoreOperation->mode,
            ]);
        }

        // Step-up re-authentication: re-enter the operator password.
        $validated = $request->validated();
        $password = is_string($validated['password'] ?? null) ? $validated['password'] : '';
        if ($actor->password === null || ! Hash::check($password, $actor->password)) {
            $this->audit->record('database.restore.requested', actor: $actor, subject: $restoreOperation, properties: [
                'restore_public_id' => $restoreOperation->public_id,
                'step_up' => 'failed',
            ], request: $request);

            return $this->respondUnprocessable('Re-authentication failed.', [
                'password' => ['The password is incorrect.'],
            ]);
        }

        $lock = Cache::lock($this->operationScheduleLockKey(), 10);
        if ($lock->get() !== true) {
            return $this->respondOperationConflict();
        }

        try {
            if ($this->anotherOperationActive($restoreOperation)) {
                return $this->respondError('Another database backup or restore is currently active.', [
                    'code' => 'database_operation_active',
                ], Response::HTTP_CONFLICT);
            }

            $restoreOperation->forceFill([
                'status' => DatabaseRestoreOperation::STATUS_PENDING,
                'executed_by_user_id' => $actor->id,
                'confirmation_method' => 'password',
            ])->save();

            $this->audit->record('database.restore.requested', actor: $actor, subject: $restoreOperation, properties: [
                'restore_public_id' => $restoreOperation->public_id,
                'step_up' => 'passed',
                'mode' => $restoreOperation->mode,
                'target' => $restoreOperation->target,
            ], request: $request);
        } finally {
            $lock->release();
        }

        if ($this->config->runSynchronously()) {
            RunDatabaseRestore::dispatchSync($restoreOperation->id, $actor->id);
        } else {
            RunDatabaseRestore::dispatch($restoreOperation->id, $actor->id);
        }

        return $this->respondSuccess(
            ['restore_operation' => DatabaseRestoreOperationResource::make($restoreOperation->refresh()->loadMissing(['backup', 'preRestoreBackup']))],
            'Restore execution accepted.',
            [],
            Response::HTTP_ACCEPTED,
        );
    }

    public function index(Request $request): JsonResponse|DatabaseRestoreOperationCollection
    {
        $actor = $request->user();
        if (! $actor instanceof User || $actor->cannot('system.database.view')) {
            return $this->respondForbidden();
        }

        $query = DatabaseRestoreOperation::query()
            ->with(['backup', 'preRestoreBackup'])
            ->latest('created_at')
            ->latest('id');

        $status = $request->query('status');
        if (is_string($status) && $status !== '') {
            $query->where('status', $status);
        }

        $perPage = min(max($request->integer('per_page', 25), 1), 100);

        return new DatabaseRestoreOperationCollection($query->paginate($perPage));
    }

    public function show(Request $request, DatabaseRestoreOperation $restoreOperation): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || $actor->cannot('system.database.view')) {
            return $this->respondForbidden();
        }

        return $this->respondSuccess([
            'restore_operation' => DatabaseRestoreOperationResource::make(
                $restoreOperation->loadMissing(['backup', 'preRestoreBackup'])
            ),
        ]);
    }

    public function cancel(Request $request, DatabaseRestoreOperation $restoreOperation): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || $actor->cannot('system.database.restore.plan')) {
            return $this->respondForbidden();
        }

        // Cancellation is only allowed before the runner starts.
        if (! in_array($restoreOperation->status, [
            DatabaseRestoreOperation::STATUS_PLANNED,
            DatabaseRestoreOperation::STATUS_PENDING,
        ], true)) {
            return $this->respondUnprocessable('Only a planned or pending restore can be cancelled.', [
                'code' => 'restore_not_cancellable',
                'status' => $restoreOperation->status,
            ]);
        }

        $restoreOperation->forceFill([
            'status' => DatabaseRestoreOperation::STATUS_CANCELLED,
            'completed_at' => Carbon::now(),
        ])->save();

        $this->audit->record('database.restore.cancelled', actor: $actor, subject: $restoreOperation, properties: [
            'restore_public_id' => $restoreOperation->public_id,
        ], request: $request);

        return $this->respondSuccess([
            'restore_operation' => DatabaseRestoreOperationResource::make($restoreOperation->loadMissing(['backup', 'preRestoreBackup'])),
        ], 'Restore operation cancelled.');
    }

    private function assertBackupRestorable(DatabaseBackup $backup, bool $verifyArtifact): ?JsonResponse
    {
        if (! in_array($backup->status, DatabaseBackup::DOWNLOADABLE_STATUSES, true)) {
            return $this->respondUnprocessable('Only a completed or verified backup can be restored.', [
                'code' => 'backup_not_restorable',
                'status' => $backup->status,
            ]);
        }

        if (! $this->config->isDriverSupported($backup->database_driver)) {
            return $this->respondUnprocessable('The selected backup uses an unsupported database driver.', [
                'code' => 'backup_driver_unsupported',
                'database_driver' => $backup->database_driver,
            ]);
        }

        if ($backup->database_connection !== $this->config->connection()) {
            return $this->respondUnprocessable('The selected backup was created for a different database connection.', [
                'code' => 'backup_connection_mismatch',
            ]);
        }

        if ($backup->encrypted) {
            return $this->respondUnprocessable('Encrypted backup restore is not supported by this runner.', [
                'code' => 'backup_encryption_unsupported',
            ]);
        }

        if ($this->config->strictVerification()
            && $backup->verification_status !== DatabaseBackup::VERIFICATION_PASSED) {
            return $this->respondUnprocessable('The backup must pass verification before it can be restored.', [
                'code' => 'backup_verification_required',
            ]);
        }

        if ($verifyArtifact) {
            if (! $this->store->exists($backup)) {
                return $this->respondUnprocessable('The backup artifact could not be found on the configured disk.', [
                    'code' => 'backup_artifact_missing',
                ]);
            }

            $checksum = $this->store->checksum($backup);
            if ($checksum === null || $backup->checksum_sha256 === null || ! hash_equals($backup->checksum_sha256, $checksum)) {
                return $this->respondUnprocessable('The backup failed checksum verification and cannot be restored.', [
                    'code' => 'backup_checksum_mismatch',
                ]);
            }

            $size = $this->store->size($backup);
            $maxBytes = $this->config->maxArtifactBytes();
            if ($maxBytes > 0 && $size !== null && $size > $maxBytes) {
                return $this->respondUnprocessable('The backup artifact exceeds the maximum restorable size.', [
                    'code' => 'backup_too_large',
                ]);
            }
        }

        return null;
    }

    private function anotherOperationActive(DatabaseRestoreOperation $current): bool
    {
        $restoreActive = DatabaseRestoreOperation::query()->getQuery()
            ->where('id', '!=', $current->getKey())
            ->whereIn('status', [
                DatabaseRestoreOperation::STATUS_PENDING,
                DatabaseRestoreOperation::STATUS_RUNNING,
            ])
            ->exists();

        if ($restoreActive) {
            return true;
        }

        return DatabaseBackup::query()->getQuery()
            ->whereIn('status', [DatabaseBackup::STATUS_PENDING, DatabaseBackup::STATUS_RUNNING])
            ->exists();
    }

    private function respondConfigError(DatabaseManagementError $error): JsonResponse
    {
        return $this->respondError($error->message, ['code' => $error->code], $error->status);
    }

    private function operationScheduleLockKey(): string
    {
        return 'database_management:operation_schedule:'.$this->config->connection();
    }

    private function respondOperationConflict(): JsonResponse
    {
        return $this->respondError('Another database backup or restore is currently being scheduled.', [
            'code' => 'database_operation_scheduling_locked',
        ], Response::HTTP_CONFLICT);
    }
}
