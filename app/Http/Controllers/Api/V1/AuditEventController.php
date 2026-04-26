<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\BaseController;
use App\Http\Resources\AuditEventResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\Activitylog\Models\Activity;

final class AuditEventController extends BaseController
{
    public function index(Request $request): JsonResponse
    {
        if ($request->user()?->can('audit.view') !== true) {
            return $this->respondForbidden();
        }

        $perPage = min(max($request->integer('per_page', 25), 1), 100);
        $events = Activity::query()
            ->when($request->filled('log_name'), fn ($query) => $query->where('log_name', $request->string('log_name')->toString()))
            ->when($request->filled('event'), fn ($query) => $query->where('event', $request->string('event')->toString()))
            ->latest()
            ->paginate($perPage);

        return $this->respondSuccess([
            'events' => AuditEventResource::collection($events->getCollection())->resolve(),
        ], meta: [
            'pagination' => [
                'current_page' => $events->currentPage(),
                'per_page' => $events->perPage(),
                'total' => $events->total(),
                'last_page' => $events->lastPage(),
            ],
        ]);
    }
}
