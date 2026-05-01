<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\BaseController;
use App\Http\Resources\AuditEventResource;
use App\Http\Resources\AuditEventCollection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\Activitylog\Models\Activity;

final class AuditEventController extends BaseController
{
    /**
     * List audit events
     *
     * @authenticated
     * @response AuditEventCollection
     */
    public function index(Request $request): AuditEventCollection|JsonResponse
    {
        if ($request->user()?->can('audit.view') !== true) {
            return $this->respondForbidden();
        }

        $perPage = min(max($request->integer('per_page', 25), 1), 100);

        return new AuditEventCollection(
            Activity::query()
                ->when($request->filled('log_name'), fn ($query) => $query->where('log_name', $request->string('log_name')->toString()))
                ->when($request->filled('event'), fn ($query) => $query->where('event', $request->string('event')->toString()))
                ->latest()
                ->paginate($perPage)
        );
    }
}
