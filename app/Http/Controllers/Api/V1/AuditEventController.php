<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\BaseController;
use App\Http\Resources\AuditEventCollection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Spatie\Activitylog\Models\Activity;

final class AuditEventController extends BaseController
{
    /**
     * List audit events
     *
     * @authenticated
     *
     * @response AuditEventCollection
     */
    public function index(Request $request): AuditEventCollection
    {
        $this->authorize('viewAny', Activity::class);

        $perPage = min(max($request->integer('per_page', 25), 1), 100);

        return new AuditEventCollection(
            Activity::query()
                ->when($request->filled('log_name'), fn ($query) => $query->where('log_name', $request->string('log_name')->toString()))
                ->when($request->filled('event'), fn ($query) => $query->where('event', $request->string('event')->toString()))
                ->when(is_string($request->query('search')) && trim($request->query('search')) !== '', function ($query) use ($request): void {
                    $term = trim((string) $request->query('search'));
                    $query->where(static function (Builder $builder) use ($term): void {
                        $builder->where('description', 'ilike', '%'.$term.'%')
                            ->orWhere('subject_type', 'ilike', '%'.$term.'%')
                            ->orWhere('log_name', 'ilike', '%'.$term.'%')
                            ->orWhere('event', 'ilike', '%'.$term.'%');
                    });
                })
                ->latest()
                ->paginate($perPage)
        );
    }
}
