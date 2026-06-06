<?php

declare(strict_types=1);

namespace App\Support\DatabaseManagement;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * A cache-backed, self-expiring write lock engaged while a destructive restore
 * runs. The cache TTL is the failsafe expiry: a crashed runner can never wedge
 * the API indefinitely (ADM-DB-010).
 */
final class DatabaseMaintenanceLock
{
    private const string KEY = 'database_management:maintenance_lock';

    public function __construct(private readonly DatabaseManagementConfig $config) {}

    /**
     * @return array{owner_public_id: ?string, owner_name: ?string, reason: string, restore_public_id: ?string, expires_at: ?string}
     */
    public function engage(?string $ownerPublicId, ?string $ownerName, string $reason, ?string $restorePublicId): array
    {
        $ttlMinutes = $this->config->lockTtlMinutes();
        $expiresAt = Carbon::now()->addMinutes($ttlMinutes);

        $payload = [
            'owner_public_id' => $ownerPublicId,
            'owner_name' => $ownerName,
            'reason' => $reason,
            'restore_public_id' => $restorePublicId,
            'expires_at' => $expiresAt->toIso8601String(),
        ];

        Cache::put(self::KEY, $payload, $expiresAt);

        return $payload;
    }

    public function release(): void
    {
        Cache::forget(self::KEY);
    }

    public function isActive(): bool
    {
        return $this->current() !== null;
    }

    /**
     * @return array{owner_public_id: ?string, owner_name: ?string, reason: string, restore_public_id: ?string, expires_at: ?string}|null
     */
    public function current(): ?array
    {
        $payload = Cache::get(self::KEY);

        return is_array($payload) ? $this->normalize($payload) : null;
    }

    /**
     * @param  array<array-key, mixed>  $payload
     * @return array{owner_public_id: ?string, owner_name: ?string, reason: string, restore_public_id: ?string, expires_at: ?string}
     */
    private function normalize(array $payload): array
    {
        return [
            'owner_public_id' => is_string($payload['owner_public_id'] ?? null) ? $payload['owner_public_id'] : null,
            'owner_name' => is_string($payload['owner_name'] ?? null) ? $payload['owner_name'] : null,
            'reason' => is_string($payload['reason'] ?? null) ? $payload['reason'] : 'Database restore in progress',
            'restore_public_id' => is_string($payload['restore_public_id'] ?? null) ? $payload['restore_public_id'] : null,
            'expires_at' => is_string($payload['expires_at'] ?? null) ? $payload['expires_at'] : null,
        ];
    }
}
