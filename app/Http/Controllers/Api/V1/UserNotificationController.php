<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Application\Notifications\UserNotificationFeed;
use App\Http\Controllers\BaseController;
use App\Http\Resources\UserNotificationResource;
use App\Models\User;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class UserNotificationController extends BaseController
{
    /** @var array<int, string> */
    private const array ALLOWED_FILTERS = [
        'read',
        'type',
        'category',
        'created_from',
        'created_to',
    ];

    public function __construct(
        private readonly UserNotificationFeed $feed,
    ) {}

    #[QueryParameter('filter[read]', 'Use true for read notifications or false for unread notifications.', type: 'boolean')]
    #[QueryParameter('filter[type]', 'Notification type: info, success, warning, or error.', type: 'string')]
    #[QueryParameter('filter[category]', 'Notification category, for example cash_transaction_posted.', type: 'string')]
    #[QueryParameter('filter[created_from]', 'Limit to notifications created at or after this timestamp/date.', type: 'string')]
    #[QueryParameter('filter[created_to]', 'Limit to notifications created at or before this timestamp/date.', type: 'string')]
    #[QueryParameter('search', 'Search title, message, category, and source public ID.', type: 'string')]
    #[QueryParameter('per_page', 'Results per page. Capped at 100.', type: 'integer')]
    #[Response(status: 200, type: 'array{success: bool, message: string, data: array{notifications: array<int, \App\Http\Resources\UserNotificationResource>}, errors: null, meta: array{pagination: array{current_page: int, per_page: int, total: int, last_page: int}}}')]
    public function index(Request $request): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User) {
            return $this->respondForbidden();
        }

        $query = $this->feed->visibleQuery($actor);
        $filterError = $this->applyFilters($query, $request);
        if ($filterError instanceof JsonResponse) {
            return $filterError;
        }
        $this->applySearch($query, $request);

        $items = $query
            ->orderByDesc('user_notifications.created_at')
            ->orderByDesc('user_notifications.id')
            ->paginate(min(max($request->integer('per_page', 25), 1), 100));

        return $this->respondSuccess([
            'notifications' => UserNotificationResource::collection($items->getCollection()),
        ], meta: [
            'pagination' => [
                'current_page' => $items->currentPage(),
                'per_page' => $items->perPage(),
                'total' => $items->total(),
                'last_page' => $items->lastPage(),
            ],
        ]);
    }

    #[Response(status: 200, type: 'array{success: bool, message: string, data: array{notification: \App\Http\Resources\UserNotificationResource}, errors: null, meta: null}')]
    public function read(Request $request, string $notification): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User) {
            return $this->respondForbidden();
        }

        $row = $this->feed->markRead($actor, $notification);
        if (! is_object($row)) {
            return $this->respondNotFound('Notification not found.');
        }

        return $this->respondSuccess([
            'notification' => UserNotificationResource::make($row),
        ], 'Notification marked as read');
    }

    #[Response(status: 200, type: 'array{success: bool, message: string, data: array{marked_read_count: int}, errors: null, meta: null}')]
    public function readAll(Request $request): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User) {
            return $this->respondForbidden();
        }

        return $this->respondSuccess([
            'marked_read_count' => $this->feed->markAllRead($actor),
        ], 'Notifications marked as read');
    }

    private function applyFilters(Builder $query, Request $request): ?JsonResponse
    {
        $filter = $request->query('filter');
        if (! is_array($filter)) {
            return null;
        }

        $unknown = array_diff(array_keys($filter), self::ALLOWED_FILTERS);
        if ($unknown !== []) {
            return $this->respondUnprocessable(
                message: 'Unsupported filter parameters.',
                errors: ['filter' => ['The following filter keys are not supported: '.implode(', ', $unknown)]]
            );
        }

        $read = $filter['read'] ?? null;
        if ($read !== null && $read !== '') {
            $readBool = filter_var($read, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($readBool === null) {
                return $this->respondUnprocessable(errors: ['filter.read' => ['Read filter must be true or false.']]);
            }

            $readBool
                ? $query->whereNotNull('user_notification_reads.read_at')
                : $query->whereNull('user_notification_reads.read_at');
        }

        foreach (['type', 'category'] as $key) {
            $value = $filter[$key] ?? null;
            if (is_string($value) && $value !== '') {
                $query->where('user_notifications.'.$key, $value);
            }
        }

        $from = $filter['created_from'] ?? null;
        if (is_string($from) && $from !== '') {
            $query->where('user_notifications.created_at', '>=', $from);
        }

        $to = $filter['created_to'] ?? null;
        if (is_string($to) && $to !== '') {
            $query->where('user_notifications.created_at', '<=', $to);
        }

        return null;
    }

    private function applySearch(Builder $query, Request $request): void
    {
        $search = $request->query('search');
        if (! is_string($search) || trim($search) === '') {
            return;
        }

        $term = trim($search);
        $query->where(function (Builder $builder) use ($term): void {
            $builder->where('user_notifications.title', 'ilike', '%'.$term.'%')
                ->orWhere('user_notifications.message', 'ilike', '%'.$term.'%')
                ->orWhere('user_notifications.category', 'ilike', '%'.$term.'%')
                ->orWhere('user_notifications.source_public_id', 'ilike', '%'.$term.'%');
        });
    }
}
