<?php

declare(strict_types=1);

namespace App\Application\MediaStorage;

use App\Http\Controllers\BaseController;
use App\Http\Resources\MediaStorageMigrationResource;
use App\Models\MediaStorageMigration;
use App\Models\User;
use App\Support\Media\MediaMigrationService;
use App\Support\Media\MediaStorageDiskResolver;
use App\Support\Security\SecurityAudit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Media-storage diagnostics and admin-triggered migration orchestration.
 *
 * Never exposes secrets, bucket names, endpoints, credentials, or raw object
 * keys/paths — only disk names, counts, safe statuses, and public ids.
 */
final class MediaStorageWorkflow extends BaseController
{
    public function __construct(
        private readonly SecurityAudit $audit,
        private readonly MediaMigrationService $migrations,
    ) {}

    /**
     * Media-storage readiness for platform/admin diagnostics.
     */
    public function status(Request $request): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || $actor->cannot('system.media-storage.view')) {
            return $this->respondForbidden();
        }

        $resolver = MediaStorageDiskResolver::fromConfig();
        $decision = $resolver->resolveForUpload();

        $r2Enabled = $resolver->isR2Enabled();
        $r2Configured = $resolver->isR2FullyConfigured();
        // Healthy only when a live probe actually succeeded this request.
        $r2Healthy = $r2Configured && $decision['outcome'] === MediaStorageDiskResolver::OUTCOME_R2;

        $lastHealthCheck = $r2Configured ? now()->toIso8601String() : null;
        $localFallbackAvailable = is_array(config('filesystems.disks.local'));

        $this->audit->record('media.storage.health_checked', actor: $actor, properties: [
            'active_disk' => $decision['disk'],
            'r2_enabled' => $r2Enabled,
            'r2_configured' => $r2Configured,
            'r2_healthy' => $r2Healthy,
        ]);

        return $this->respondSuccess([
            'active_disk' => $decision['disk'],
            'r2_enabled' => $r2Enabled,
            'r2_configured' => $r2Configured,
            'r2_healthy' => $r2Healthy,
            // True when R2 is enabled but its config is incomplete/malformed.
            'r2_partial_config' => $r2Enabled && ! $r2Configured,
            'fallback_mode' => $resolver->fallbackMode(),
            'last_health_check' => $lastHealthCheck,
            'failure_reason' => $r2Healthy ? null : $decision['reason'],
            'local_fallback_available' => $localFallbackAvailable,
        ], 'Media storage status');
    }

    /**
     * List migration operations (most recent first).
     */
    public function migrations(Request $request): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || $actor->cannot('system.media-storage.view')) {
            return $this->respondForbidden();
        }

        $perPage = min(max($request->integer('per_page', 25), 1), 100);
        $paginator = MediaStorageMigration::query()->latest('id')->paginate($perPage);

        return $this->respondSuccess([
            'migrations' => MediaStorageMigrationResource::collection($paginator->items()),
        ], 'Media storage migrations', [
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    /**
     * Show a single migration operation with bounded failure detail.
     */
    public function showMigration(Request $request, MediaStorageMigration $migration): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || $actor->cannot('system.media-storage.view')) {
            return $this->respondForbidden();
        }

        return $this->respondSuccess([
            'migration' => MediaStorageMigrationResource::make($migration),
        ], 'Media storage migration');
    }

    /**
     * Request (and synchronously run) a local-to-R2 migration. Supports
     * dry-run. A real run requires R2 to be fully configured.
     */
    public function requestMigration(Request $request): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || $actor->cannot('system.media-storage.migrate')) {
            return $this->respondForbidden();
        }

        $dryRun = $request->boolean('dry_run');

        if (! $dryRun && ! MediaStorageDiskResolver::fromConfig()->isR2FullyConfigured()) {
            return $this->respondError(
                'R2 is not fully configured; a real migration cannot run.',
                ['code' => 'r2_not_configured'],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $sourceDisks = implode(',', $this->migrations->allowedSourceDisks());

        $operation = MediaStorageMigration::query()->create([
            'public_id' => (string) Str::ulid(),
            'source_disk' => $sourceDisks,
            'target_disk' => MediaStorageDiskResolver::DISK_R2,
            'status' => MediaStorageMigration::STATUS_PENDING,
            'dry_run' => $dryRun,
            'requested_by_user_id' => $actor->id,
        ]);

        $this->audit->record('media.migration.requested', actor: $actor, subject: $operation, properties: [
            'migration_public_id' => $operation->public_id,
            'dry_run' => $dryRun,
            'source_disk' => $sourceDisks,
            'target_disk' => MediaStorageDiskResolver::DISK_R2,
        ]);

        $operation = $this->migrations->execute($operation);

        return $this->respondCreated([
            'migration' => MediaStorageMigrationResource::make($operation),
        ], 'Media storage migration requested');
    }
}
