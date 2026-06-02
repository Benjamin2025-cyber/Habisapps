<?php

declare(strict_types=1);

namespace App\Application\Notifications;

use App\Models\User;
use App\Models\UserNotification;
use App\Support\Staff\StaffAgencyScope;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class UserNotificationFeed
{
    /** @var array<int, string> */
    private const array TYPES = [
        UserNotification::TYPE_INFO,
        UserNotification::TYPE_SUCCESS,
        UserNotification::TYPE_WARNING,
        UserNotification::TYPE_ERROR,
    ];

    public function __construct(
        private readonly StaffAgencyScope $staffAgencyScope,
    ) {}

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function notifyUser(
        User $user,
        string $type,
        string $category,
        string $title,
        string $message,
        string $sourceType,
        string $sourcePublicId,
        ?int $agencyId = null,
        ?string $actionUrl = null,
        array $metadata = [],
    ): UserNotification {
        return $this->create(
            recipientType: UserNotification::RECIPIENT_USER,
            recipientId: $user->id,
            type: $type,
            category: $category,
            title: $title,
            message: $message,
            sourceType: $sourceType,
            sourcePublicId: $sourcePublicId,
            agencyId: $agencyId,
            actionUrl: $actionUrl,
            metadata: $metadata,
        );
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function notifyAgency(
        int $agencyId,
        string $type,
        string $category,
        string $title,
        string $message,
        string $sourceType,
        string $sourcePublicId,
        ?string $actionUrl = null,
        array $metadata = [],
    ): UserNotification {
        return $this->create(
            recipientType: UserNotification::RECIPIENT_AGENCY,
            recipientId: $agencyId,
            type: $type,
            category: $category,
            title: $title,
            message: $message,
            sourceType: $sourceType,
            sourcePublicId: $sourcePublicId,
            agencyId: $agencyId,
            actionUrl: $actionUrl,
            metadata: $metadata,
        );
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function notifyPlatform(
        string $type,
        string $category,
        string $title,
        string $message,
        string $sourceType,
        string $sourcePublicId,
        ?string $actionUrl = null,
        array $metadata = [],
    ): UserNotification {
        return $this->create(
            recipientType: UserNotification::RECIPIENT_PLATFORM,
            recipientId: null,
            type: $type,
            category: $category,
            title: $title,
            message: $message,
            sourceType: $sourceType,
            sourcePublicId: $sourcePublicId,
            agencyId: null,
            actionUrl: $actionUrl,
            metadata: $metadata,
        );
    }

    public function visibleQuery(User $actor): Builder
    {
        $query = DB::table('user_notifications')
            ->leftJoin('user_notification_reads', function ($join) use ($actor): void {
                $join->on('user_notification_reads.user_notification_id', '=', 'user_notifications.id')
                    ->where('user_notification_reads.user_id', '=', $actor->id);
            })
            ->leftJoin('agencies', 'agencies.id', '=', 'user_notifications.agency_id')
            ->select([
                'user_notifications.*',
                'user_notification_reads.read_at as actor_read_at',
                'agencies.public_id as agency_public_id',
            ]);

        $agencyId = $this->staffAgencyScope->currentAgencyId($actor);
        $query->where(function (Builder $builder) use ($actor, $agencyId): void {
            $builder->where(function (Builder $userBuilder) use ($actor): void {
                $userBuilder
                    ->where('user_notifications.recipient_type', UserNotification::RECIPIENT_USER)
                    ->where('user_notifications.recipient_id', $actor->id);
            });

            if ($agencyId !== null && $actor->can('notifications.view')) {
                $builder->orWhere(function (Builder $agencyBuilder) use ($agencyId): void {
                    $agencyBuilder
                        ->where('user_notifications.recipient_type', UserNotification::RECIPIENT_AGENCY)
                        ->where('user_notifications.recipient_id', $agencyId);
                });
            }

            if ($actor->hasRole('platform-admin')) {
                $builder->orWhere('user_notifications.recipient_type', UserNotification::RECIPIENT_PLATFORM);
            }
        });

        return $query;
    }

    public function markRead(User $actor, string $notificationPublicId): ?object
    {
        $notification = $this->visibleQuery($actor)
            ->where('user_notifications.public_id', $notificationPublicId)
            ->first();
        if (! is_object($notification)) {
            return null;
        }

        DB::table('user_notification_reads')->upsert([
            [
                'user_notification_id' => (int) $notification->id,
                'user_id' => $actor->id,
                'read_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ], ['user_notification_id', 'user_id'], ['read_at', 'updated_at']);

        return $this->visibleQuery($actor)
            ->where('user_notifications.public_id', $notificationPublicId)
            ->first();
    }

    public function markAllRead(User $actor): int
    {
        $ids = $this->visibleQuery($actor)
            ->whereNull('user_notification_reads.read_at')
            ->pluck('user_notifications.id');

        $rows = [];
        foreach ($ids as $id) {
            if (is_numeric($id)) {
                $rows[] = [
                    'user_notification_id' => (int) $id,
                    'user_id' => $actor->id,
                    'read_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
        }

        if ($rows !== []) {
            DB::table('user_notification_reads')->upsert($rows, ['user_notification_id', 'user_id'], ['read_at', 'updated_at']);
        }

        return count($rows);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function create(
        string $recipientType,
        ?int $recipientId,
        string $type,
        string $category,
        string $title,
        string $message,
        string $sourceType,
        string $sourcePublicId,
        ?int $agencyId,
        ?string $actionUrl,
        array $metadata,
    ): UserNotification {
        if (! in_array($type, self::TYPES, true)) {
            throw new InvalidArgumentException('Unsupported notification type.');
        }
        if ($category === '' || $title === '' || $message === '' || $sourceType === '' || $sourcePublicId === '') {
            throw new InvalidArgumentException('Notification category, title, message, source type, and source public ID are required.');
        }

        DB::table('user_notifications')->insertOrIgnore([
            'public_id' => (string) Str::ulid(),
            'recipient_type' => $recipientType,
            'recipient_id' => $recipientId,
            'agency_id' => $agencyId,
            'type' => $type,
            'category' => $category,
            'title' => $title,
            'message' => $message,
            'action_url' => $actionUrl,
            'source_type' => $sourceType,
            'source_public_id' => $sourcePublicId,
            'metadata' => $metadata === [] ? null : json_encode($metadata, JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $query = UserNotification::query()
            ->where('recipient_type', $recipientType)
            ->where('source_type', $sourceType)
            ->where('source_public_id', $sourcePublicId)
            ->where('category', $category);
        if ($recipientId === null) {
            $query->getQuery()->whereNull('recipient_id');
        } else {
            $query->where('recipient_id', $recipientId);
        }

        return $query->firstOrFail();
    }
}
