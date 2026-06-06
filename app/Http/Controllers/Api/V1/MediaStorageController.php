<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Application\MediaStorage\MediaStorageWorkflow;
use App\Http\Controllers\BaseController;
use App\Models\MediaStorageMigration;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Transport adapter for media-storage diagnostics and migrations. All policy,
 * orchestration, and response shaping live in {@see MediaStorageWorkflow}.
 */
final class MediaStorageController extends BaseController
{
    public function __construct(private readonly MediaStorageWorkflow $workflow) {}

    /**
     * Media storage status.
     *
     * Reports the active media disk and R2 readiness for platform/admin
     * diagnostics. Requires `system.media-storage.view`. Never returns
     * secrets, bucket names, endpoints, credentials, or raw object keys.
     *
     * @authenticated
     */
    public function status(Request $request): JsonResponse
    {
        return $this->workflow->status($request);
    }

    /**
     * List media storage migration operations.
     *
     * Requires `system.media-storage.view`.
     *
     * @authenticated
     */
    public function migrations(Request $request): JsonResponse
    {
        return $this->workflow->migrations($request);
    }

    /**
     * Show a media storage migration operation.
     *
     * Requires `system.media-storage.view`.
     *
     * @authenticated
     */
    public function showMigration(Request $request, MediaStorageMigration $migration): JsonResponse
    {
        return $this->workflow->showMigration($request, $migration);
    }

    /**
     * Request a local-to-R2 migration (supports dry-run).
     *
     * Requires `system.media-storage.migrate`.
     *
     * @authenticated
     */
    public function requestMigration(Request $request): JsonResponse
    {
        return $this->workflow->requestMigration($request);
    }
}
