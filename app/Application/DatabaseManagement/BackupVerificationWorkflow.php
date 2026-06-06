<?php

declare(strict_types=1);

namespace App\Application\DatabaseManagement;

use App\Http\Controllers\BaseController;
use App\Http\Resources\DatabaseBackupResource;
use App\Models\DatabaseBackup;
use App\Models\User;
use App\Support\DatabaseManagement\BackupArtifactStore;
use App\Support\DatabaseManagement\DatabaseManagementConfig;
use App\Support\Security\SecurityAudit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;

/**
 * Backup integrity verification: recomputes the checksum and confirms the
 * artifact exists, updating verification state and recording audit events
 * (ADM-DB-005). Verification failure never deletes the backup automatically.
 */
final class BackupVerificationWorkflow extends BaseController
{
    public function __construct(
        private readonly DatabaseManagementConfig $config,
        private readonly BackupArtifactStore $store,
        private readonly SecurityAudit $audit,
    ) {}

    public function verify(Request $request, DatabaseBackup $databaseBackup): JsonResponse
    {
        $actor = $request->user();
        // Verification mutates verification state (and can promote a completed
        // artifact to verified), so it requires a maintenance permission rather
        // than the read-only view permission.
        if (! $actor instanceof User || $actor->cannot('system.database.maintenance.manage')) {
            return $this->respondForbidden();
        }

        if (! $this->config->isEnabled()) {
            return $this->respondError('Database management is disabled in this environment.', [
                'code' => 'database_management_disabled',
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        if ($databaseBackup->isDeleted()) {
            return $this->respondUnprocessable('A deleted backup cannot be verified.', [
                'code' => 'backup_deleted',
            ]);
        }

        if (! in_array($databaseBackup->status, DatabaseBackup::DOWNLOADABLE_STATUSES, true)) {
            return $this->respondUnprocessable('Only a completed or verified backup can be verified.', [
                'code' => 'backup_not_verifiable',
                'status' => $databaseBackup->status,
            ]);
        }

        $fileExists = $this->store->exists($databaseBackup);
        $recomputed = $fileExists ? $this->store->checksum($databaseBackup) : null;
        $checksumMatches = $fileExists
            && $recomputed !== null
            && $databaseBackup->checksum_sha256 !== null
            && hash_equals($databaseBackup->checksum_sha256, $recomputed);

        $passed = $fileExists && $checksumMatches;

        $update = [
            'verification_status' => $passed ? DatabaseBackup::VERIFICATION_PASSED : DatabaseBackup::VERIFICATION_FAILED,
            'verified_at' => Carbon::now(),
        ];

        // A passing verification promotes a completed artifact to verified.
        if ($passed && $databaseBackup->status === DatabaseBackup::STATUS_COMPLETED) {
            $update['status'] = DatabaseBackup::STATUS_VERIFIED;
        }
        if (! $passed && $databaseBackup->status === DatabaseBackup::STATUS_VERIFIED) {
            $update['status'] = DatabaseBackup::STATUS_COMPLETED;
        }

        $databaseBackup->forceFill($update)->save();

        if ($passed) {
            $this->audit->record('database.backup.verified', actor: $actor, subject: $databaseBackup, properties: [
                'backup_public_id' => $databaseBackup->public_id,
                'checksum_sha256' => $databaseBackup->checksum_sha256,
            ], request: $request);
        } else {
            $this->audit->record('database.backup.verification_failed', actor: $actor, subject: $databaseBackup, properties: [
                'backup_public_id' => $databaseBackup->public_id,
                'file_exists' => $fileExists,
                'checksum_matches' => $checksumMatches,
            ], request: $request);
        }

        return $this->respondSuccess([
            'backup' => DatabaseBackupResource::make($databaseBackup),
            'verification' => [
                'passed' => $passed,
                'file_exists' => $fileExists,
                'checksum_matches' => $checksumMatches,
            ],
        ], $passed ? 'Backup verified.' : 'Backup verification failed.');
    }
}
