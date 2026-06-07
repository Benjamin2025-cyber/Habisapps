<?php

declare(strict_types=1);

namespace App\Application\Notifications;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class NotificationOutbox
{
    public const string STATUS_PENDING = 'pending';

    public const string STATUS_SENT = 'sent';

    public const string STATUS_FAILED = 'failed';

    public const string STATUS_CANCELLED = 'cancelled';

    public const string STATUS_PERMANENTLY_FAILED = 'permanently_failed';

    public function __construct(
        private readonly NotificationTemplateManager $templates,
    ) {}

    /**
     * @param  array<string, scalar|null>  $variables
     * @param  array<string, mixed>  $metadata
     */
    public function enqueue(
        string $templateCode,
        string $category,
        string $channel,
        string $destination,
        string $idempotencyKey,
        array $variables,
        ?string $recipientType = null,
        ?int $recipientId = null,
        string $language = 'fr',
        array $metadata = [],
        ?int $maxAttempts = null,
    ): object {
        if ($idempotencyKey === '') {
            throw new InvalidArgumentException('Idempotency key is required for outbox enqueue.');
        }
        if ($destination === '') {
            throw new InvalidArgumentException('Destination is required for outbox enqueue.');
        }

        return DB::transaction(function () use (
            $templateCode,
            $category,
            $channel,
            $destination,
            $idempotencyKey,
            $variables,
            $recipientType,
            $recipientId,
            $language,
            $metadata,
            $maxAttempts,
        ): object {
            $existing = DB::table('notification_deliveries')
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();
            if (is_object($existing)) {
                return $existing;
            }

            $template = $this->templates->resolveActive($templateCode, $category, $language);
            if (! is_object($template)) {
                throw new InvalidArgumentException(__('notifications.outbox_no_active_template', ['template_code' => $templateCode, 'language' => $language]));
            }

            $body = $this->templates->render($template, $variables);

            DB::table('notification_deliveries')->insertOrIgnore([
                'public_id' => (string) Str::ulid(),
                'notification_template_id' => (int) (((array) $template)['id'] ?? 0),
                'recipient_type' => $recipientType,
                'recipient_id' => $recipientId,
                'channel' => $channel,
                'category' => $category,
                'idempotency_key' => $idempotencyKey,
                'destination' => $destination,
                'subject' => $this->rowNullableString($template, 'subject'),
                'body' => $body,
                'status' => self::STATUS_PENDING,
                'retry_count' => 0,
                'max_attempts' => $maxAttempts !== null ? max(1, $maxAttempts) : 3,
                'scheduled_at' => now(),
                'metadata' => $metadata === [] ? null : json_encode($metadata, JSON_THROW_ON_ERROR),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $row = DB::table('notification_deliveries')
                ->where('idempotency_key', $idempotencyKey)
                ->first();
            if (! is_object($row)) {
                throw new InvalidArgumentException('Enqueued delivery could not be reloaded.');
            }

            return $row;
        });
    }

    private function rowNullableString(object $row, string $key): ?string
    {
        $value = ((array) $row)[$key] ?? null;

        return $value === null ? null : (string) $value;
    }
}
