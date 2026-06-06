<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Media;

use App\Support\Media\MediaStorageDiskResolver;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Tests\TestCase;

final class MediaStorageDiskResolverTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $overrides
     */
    private function configureR2(array $overrides = []): void
    {
        config(array_merge([
            'security.documents.media.auto' => true,
            'security.documents.media.r2_enabled' => true,
            'security.documents.media.r2_fallback_mode' => MediaStorageDiskResolver::FALLBACK_FAIL_CLOSED,
            'filesystems.disks.r2.key' => 'test-key',
            'filesystems.disks.r2.secret' => 'test-secret',
            'filesystems.disks.r2.bucket' => 'test-bucket',
            'filesystems.disks.r2.account_id' => 'test-account',
            'filesystems.disks.r2.endpoint' => 'https://test-account.r2.cloudflarestorage.com',
        ], $overrides));
    }

    public function test_complete_config_resolves_to_r2(): void
    {
        $this->configureR2();

        $resolver = MediaStorageDiskResolver::fromConfig();

        self::assertTrue($resolver->isR2Enabled());
        self::assertTrue($resolver->isR2FullyConfigured());
        self::assertSame(MediaStorageDiskResolver::DISK_R2, $resolver->resolve()['disk']);
    }

    public function test_missing_bucket_is_not_fully_configured_and_falls_back_to_local(): void
    {
        $this->configureR2(['filesystems.disks.r2.bucket' => '']);

        $resolver = MediaStorageDiskResolver::fromConfig();

        self::assertFalse($resolver->isR2FullyConfigured());
        self::assertSame(MediaStorageDiskResolver::DISK_LOCAL, $resolver->resolve()['disk']);
    }

    public function test_missing_endpoint_or_account_id_is_not_fully_configured(): void
    {
        $this->configureR2([
            'filesystems.disks.r2.account_id' => '',
            'filesystems.disks.r2.endpoint' => '',
        ]);

        $resolver = MediaStorageDiskResolver::fromConfig();

        self::assertFalse($resolver->isR2FullyConfigured());
        self::assertSame(MediaStorageDiskResolver::DISK_LOCAL, $resolver->resolve()['disk']);
    }

    public function test_missing_credentials_is_not_fully_configured(): void
    {
        $this->configureR2(['filesystems.disks.r2.key' => '', 'filesystems.disks.r2.secret' => '']);

        $resolver = MediaStorageDiskResolver::fromConfig();

        self::assertFalse($resolver->isR2FullyConfigured());
        self::assertSame(MediaStorageDiskResolver::DISK_LOCAL, $resolver->resolve()['disk']);
    }

    public function test_disabled_r2_uses_local(): void
    {
        $this->configureR2(['security.documents.media.r2_enabled' => false]);

        $resolver = MediaStorageDiskResolver::fromConfig();

        self::assertFalse($resolver->isR2Enabled());
        self::assertSame(MediaStorageDiskResolver::DISK_LOCAL, $resolver->resolve()['disk']);
    }

    public function test_explicit_media_disk_local_remains_supported_when_auto_is_off(): void
    {
        config([
            'security.documents.media.auto' => false,
            'media-library.disk_name' => 'local',
        ]);

        $resolver = MediaStorageDiskResolver::fromConfig();

        self::assertSame(MediaStorageDiskResolver::DISK_LOCAL, $resolver->resolve()['disk']);
    }

    public function test_explicit_public_disk_is_rejected(): void
    {
        config([
            'security.documents.media.auto' => false,
            'media-library.disk_name' => 'public',
        ]);

        $this->expectException(InvalidArgumentException::class);

        MediaStorageDiskResolver::fromConfig()->resolve();
    }

    public function test_healthy_r2_resolves_for_upload_to_r2(): void
    {
        $this->configureR2();
        Storage::fake('r2');

        $decision = MediaStorageDiskResolver::fromConfig()->resolveForUpload();

        self::assertSame(MediaStorageDiskResolver::OUTCOME_R2, $decision['outcome']);
        self::assertSame(MediaStorageDiskResolver::DISK_R2, $decision['disk']);
    }

    public function test_unhealthy_r2_fail_closed_keeps_r2_target_with_fail_closed_outcome(): void
    {
        // An invalid driver makes Storage::disk('r2') throw, simulating an
        // unreachable R2 without any network access.
        $this->configureR2([
            'security.documents.media.r2_fallback_mode' => MediaStorageDiskResolver::FALLBACK_FAIL_CLOSED,
            'filesystems.disks.r2.driver' => 'invalid-driver-for-test',
        ]);

        $decision = MediaStorageDiskResolver::fromConfig()->resolveForUpload();

        self::assertSame(MediaStorageDiskResolver::OUTCOME_FAIL_CLOSED, $decision['outcome']);
        self::assertTrue($decision['r2_attempted']);
    }

    public function test_unhealthy_r2_fallback_local_uses_local(): void
    {
        $this->configureR2([
            'security.documents.media.r2_fallback_mode' => MediaStorageDiskResolver::FALLBACK_LOCAL,
            'filesystems.disks.r2.driver' => 'invalid-driver-for-test',
        ]);

        $decision = MediaStorageDiskResolver::fromConfig()->resolveForUpload();

        self::assertSame(MediaStorageDiskResolver::OUTCOME_FALLBACK_LOCAL, $decision['outcome']);
        self::assertSame(MediaStorageDiskResolver::DISK_LOCAL, $decision['disk']);
    }

    public function test_r2_disk_uses_s3_driver_and_auto_region(): void
    {
        self::assertSame('s3', config('filesystems.disks.r2.driver'));
        self::assertSame('auto', config('filesystems.disks.r2.region'));
    }

    public function test_r2_endpoint_uses_cloudflare_documented_account_shape(): void
    {
        $this->configureR2();

        self::assertSame(
            'https://test-account.r2.cloudflarestorage.com',
            MediaStorageDiskResolver::fromConfig()->r2Endpoint()
        );
    }
}
