<?php

declare(strict_types=1);

namespace App\Application\Notifications;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

final class NotificationDeliveryRetryManager
{
    private const array TABLES = [
        'notification_deliveries' => 'failure_reason',
        'otp_deliveries' => 'error_summary',
    ];

    public function recordFailure(string $table, int $deliveryId, string $failureReason): object
    {
        $failureColumn = $this->failureColumn($table);

        return DB::transaction(function () use ($deliveryId, $failureColumn, $failureReason, $table): object {
            $delivery = DB::table($table)->where('id', $deliveryId)->lockForUpdate()->first();
            if (! is_object($delivery)) {
                throw new RuntimeException('Delivery row was not found.');
            }

            $retryCount = $this->rowInt($delivery, 'retry_count') + 1;
            $maxAttempts = max(1, $this->rowInt($delivery, 'max_attempts', 3));
            $permanent = $retryCount >= $maxAttempts;

            DB::table($table)
                ->where('id', $deliveryId)
                ->update([
                    'status' => $permanent ? 'permanently_failed' : 'failed',
                    'retry_count' => $retryCount,
                    'last_attempt_at' => now(),
                    'next_attempt_at' => $permanent ? null : now()->addMinutes($this->backoffMinutes($retryCount)),
                    $failureColumn => $this->sanitizeFailureReason($failureReason),
                    'updated_at' => now(),
                ]);

            $updated = DB::table($table)->where('id', $deliveryId)->first();
            if (! is_object($updated)) {
                throw new RuntimeException('Updated delivery row was not found.');
            }

            return $updated;
        });
    }

    public function recordSuccess(string $table, int $deliveryId, ?string $providerReference = null): object
    {
        $this->failureColumn($table);

        return DB::transaction(function () use ($deliveryId, $providerReference, $table): object {
            $delivery = DB::table($table)->where('id', $deliveryId)->lockForUpdate()->first();
            if (! is_object($delivery)) {
                throw new RuntimeException('Delivery row was not found.');
            }

            $updates = [
                'status' => $table === 'otp_deliveries' ? 'sent' : 'sent',
                'last_attempt_at' => now(),
                'next_attempt_at' => null,
                'sent_at' => now(),
                'updated_at' => now(),
            ];

            if ($providerReference !== null && $providerReference !== '') {
                $updates['provider_reference'] = $providerReference;
            }

            DB::table($table)->where('id', $deliveryId)->update($updates);

            $updated = DB::table($table)->where('id', $deliveryId)->first();
            if (! is_object($updated)) {
                throw new RuntimeException('Updated delivery row was not found.');
            }

            return $updated;
        });
    }

    /**
     * @return array<int, object>
     */
    public function due(string $table, int $limit = 100): array
    {
        $this->failureColumn($table);

        return DB::table($table)
            ->whereIn('status', ['pending', 'failed'])
            ->where(function ($query): void {
                $query->whereNull('next_attempt_at')
                    ->orWhere('next_attempt_at', '<=', now());
            })
            ->orderBy('id')
            ->limit(max(1, min($limit, 500)))
            ->get()
            ->all();
    }

    public function sanitizeFailureReason(string $reason): string
    {
        $sanitized = preg_replace('/\b\d{6}\b/', '[redacted-code]', $reason) ?? $reason;
        $sanitized = preg_replace('/(\b(?:otp|token|password|secret)\b\s*[:=]\s*)\S+/i', '$1[redacted]', $sanitized) ?? $sanitized;
        $sanitized = preg_replace('/\+?\d[\d\s().-]{7,}\d/', '[redacted-phone]', $sanitized) ?? $sanitized;

        return mb_substr($sanitized, 0, 500);
    }

    private function failureColumn(string $table): string
    {
        $column = self::TABLES[$table] ?? null;
        if (! is_string($column)) {
            throw new InvalidArgumentException('Unsupported delivery retry table.');
        }

        return $column;
    }

    private function backoffMinutes(int $retryCount): int
    {
        return min(60, 2 ** max(0, $retryCount - 1));
    }

    private function rowInt(object $row, string $key, int $default = 0): int
    {
        $value = ((array) $row)[$key] ?? $default;

        return is_numeric($value) ? (int) $value : $default;
    }
}
