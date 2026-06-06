<?php

declare(strict_types=1);

namespace App\Support\Media;

use App\Models\Document;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Throwable;

/**
 * Central resolver for sensitive-media storage disk selection.
 *
 * The resolver is fully configuration-driven (it never reads env() at runtime)
 * so it behaves identically under `config:cache` in production and can be
 * exercised in tests via `config([...])` + `Storage::fake()`.
 *
 * Two distinct concerns are separated:
 *
 *  - {@see resolve()} is a pure, cheap, network-free decision used wherever the
 *    default media disk is needed (e.g. {@see Document::registerMediaCollections()}).
 *    It only inspects configuration.
 *  - {@see resolveForUpload()} additionally performs a live R2 reachability
 *    probe so the documented fail_closed / fallback_local policy can be applied
 *    at the moment of an upload.
 */
final class MediaStorageDiskResolver
{
    public const string DISK_LOCAL = 'local';

    public const string DISK_R2 = 'r2';

    public const string FALLBACK_FAIL_CLOSED = 'fail_closed';

    public const string FALLBACK_LOCAL = 'fallback_local';

    /** Upload outcomes. */
    public const string OUTCOME_LOCAL = 'local';

    public const string OUTCOME_R2 = 'r2';

    public const string OUTCOME_FALLBACK_LOCAL = 'fallback_local';

    public const string OUTCOME_FAIL_CLOSED = 'fail_closed';

    /**
     * Disks that may ever hold sensitive/regulated media. The `public` disk is
     * deliberately excluded so KYC/financial evidence can never be served
     * unauthenticated.
     *
     * @var array<int, string>
     */
    private const array ALLOWED_SENSITIVE_DISKS = [self::DISK_LOCAL, self::DISK_R2];

    public function __construct(
        private readonly string $explicitDisk = self::DISK_LOCAL,
        private readonly bool $autoMode = true,
    ) {}

    /**
     * Build a resolver from configuration. Used by the Document model, the
     * status endpoint, and the upload path.
     */
    public static function fromConfig(): self
    {
        $explicitDisk = config('media-library.disk_name', self::DISK_LOCAL);
        if (! is_string($explicitDisk) || $explicitDisk === '') {
            $explicitDisk = self::DISK_LOCAL;
        }

        return new self($explicitDisk, (bool) config('security.documents.media.auto', true));
    }

    /**
     * Configuration-only disk decision. Never touches the network.
     *
     * @return array{disk: string, reason: string, r2_configured: bool}
     */
    public function resolve(): array
    {
        if (! $this->autoMode) {
            return $this->resolveExplicitDisk();
        }

        if (! $this->isR2Enabled()) {
            return [
                'disk' => self::DISK_LOCAL,
                'reason' => 'R2 is disabled; using the private local disk.',
                'r2_configured' => false,
            ];
        }

        if (! $this->isR2FullyConfigured()) {
            return [
                'disk' => self::DISK_LOCAL,
                'reason' => 'R2 is enabled but not fully configured; using the private local disk.',
                'r2_configured' => false,
            ];
        }

        return [
            'disk' => self::DISK_R2,
            'reason' => 'R2 is enabled and fully configured.',
            'r2_configured' => true,
        ];
    }

    /**
     * Resolve the disk for an actual upload, applying the live-health fallback
     * policy. Performs at most one lightweight R2 reachability probe.
     *
     * @return array{disk: string, outcome: string, reason: string, r2_attempted: bool}
     */
    public function resolveForUpload(): array
    {
        $resolution = $this->resolve();

        if ($resolution['disk'] !== self::DISK_R2) {
            return [
                'disk' => $resolution['disk'],
                'outcome' => self::OUTCOME_LOCAL,
                'reason' => $resolution['reason'],
                'r2_attempted' => false,
            ];
        }

        if ($this->checkR2Health()) {
            return [
                'disk' => self::DISK_R2,
                'outcome' => self::OUTCOME_R2,
                'reason' => 'R2 is enabled, configured, and reachable.',
                'r2_attempted' => true,
            ];
        }

        if ($this->fallbackMode() === self::FALLBACK_LOCAL) {
            return [
                'disk' => self::DISK_LOCAL,
                'outcome' => self::OUTCOME_FALLBACK_LOCAL,
                'reason' => 'R2 is unreachable; falling back to the private local disk.',
                'r2_attempted' => true,
            ];
        }

        return [
            'disk' => self::DISK_R2,
            'outcome' => self::OUTCOME_FAIL_CLOSED,
            'reason' => 'R2 is unreachable and fallback is disabled (fail_closed).',
            'r2_attempted' => true,
        ];
    }

    /**
     * Whether R2 is switched on by policy.
     */
    public function isR2Enabled(): bool
    {
        return (bool) config('security.documents.media.r2_enabled', false);
    }

    /**
     * Whether R2 is enabled AND has credentials and a bucket. Determined purely
     * from configuration, without attempting any upload.
     */
    public function isR2FullyConfigured(): bool
    {
        return $this->isR2Enabled()
            && $this->hasValidCredentials()
            && $this->hasValidBucket()
            && $this->hasValidEndpoint();
    }

    public function hasValidCredentials(): bool
    {
        $key = config('filesystems.disks.r2.key');
        $secret = config('filesystems.disks.r2.secret');

        return is_string($key) && $key !== ''
            && is_string($secret) && $secret !== '';
    }

    public function hasValidBucket(): bool
    {
        $bucket = config('filesystems.disks.r2.bucket');

        return is_string($bucket) && $bucket !== '';
    }

    public function hasValidEndpoint(): bool
    {
        $endpoint = $this->r2Endpoint();

        return $endpoint !== '';
    }

    /**
     * Live R2 reachability probe. Uses a non-mutating existence check on a
     * sentinel key: a missing object is *not* an error (returns false without
     * throwing) so a reachable bucket reports healthy, while authentication or
     * connectivity failures surface as exceptions (the r2 disk uses
     * `throw => true`) and report unhealthy.
     */
    public function checkR2Health(): bool
    {
        if (! $this->isR2FullyConfigured()) {
            return false;
        }

        try {
            Storage::disk(self::DISK_R2)->exists('.r2-health-probe');

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    public function fallbackMode(): string
    {
        $mode = config('security.documents.media.r2_fallback_mode', self::FALLBACK_FAIL_CLOSED);

        return $mode === self::FALLBACK_LOCAL ? self::FALLBACK_LOCAL : self::FALLBACK_FAIL_CLOSED;
    }

    /**
     * Cloudflare's documented S3 endpoint for the configured account, derived
     * from the account id when not explicitly set.
     */
    public function r2Endpoint(): string
    {
        $endpoint = config('filesystems.disks.r2.endpoint');

        return is_string($endpoint) ? $endpoint : '';
    }

    /**
     * @return array{disk: string, reason: string, r2_configured: bool}
     */
    private function resolveExplicitDisk(): array
    {
        $disk = $this->explicitDisk !== '' ? $this->explicitDisk : self::DISK_LOCAL;

        if (! in_array($disk, self::ALLOWED_SENSITIVE_DISKS, true)) {
            throw new InvalidArgumentException(
                "Disk '{$disk}' is not permitted for sensitive media storage. Allowed: ".implode(', ', self::ALLOWED_SENSITIVE_DISKS).'.'
            );
        }

        if ($disk === self::DISK_R2 && ! $this->isR2FullyConfigured()) {
            throw new InvalidArgumentException(
                'MEDIA_DISK=r2 is set but R2 is not fully configured (enabled flag, credentials, bucket, and endpoint/account id are required).'
            );
        }

        return [
            'disk' => $disk,
            'reason' => "Explicit MEDIA_DISK={$disk}.",
            'r2_configured' => $disk === self::DISK_R2,
        ];
    }
}
