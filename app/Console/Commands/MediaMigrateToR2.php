<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\MediaStorageMigration;
use App\Support\Media\MediaMigrationService;
use App\Support\Media\MediaStorageDiskResolver;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

#[Signature('media:migrate-to-r2 {--dry-run : Report candidate counts and bytes without copying anything}')]
#[Description('Migrate regulated document media from allowed source disks to Cloudflare R2 with checksum verification.')]
final class MediaMigrateToR2 extends Command
{
    public function handle(MediaMigrationService $service): int
    {
        $dryRun = $this->option('dry-run') === true;

        if (! $dryRun && ! MediaStorageDiskResolver::fromConfig()->isR2FullyConfigured()) {
            $this->error('R2 is not fully configured (R2_ENABLED, credentials, and bucket are required). Aborting.');

            return self::FAILURE;
        }

        $this->info('Starting media migration to R2...');
        if ($dryRun) {
            $this->warn('DRY-RUN MODE: no objects will be copied and no records will change.');
        }

        $sourceDisks = implode(',', $service->allowedSourceDisks());
        $this->line("Allowed source disks: {$sourceDisks}");
        $this->line('Target disk: '.MediaStorageDiskResolver::DISK_R2);

        $operation = MediaStorageMigration::query()->create([
            'public_id' => (string) Str::ulid(),
            'source_disk' => $sourceDisks,
            'target_disk' => MediaStorageDiskResolver::DISK_R2,
            'status' => MediaStorageMigration::STATUS_PENDING,
            'dry_run' => $dryRun,
            'requested_by_user_id' => null,
        ]);

        $operation = $service->execute($operation);

        $this->newLine();
        $this->info("Migration {$operation->public_id} finished with status: {$operation->status}");
        $this->line("  Candidates: {$operation->total_candidates}");
        $this->line("  Total bytes: {$operation->total_bytes}");
        if (! $dryRun) {
            $this->line("  Processed: {$operation->processed_count}");
            $this->line("  Migrated:  {$operation->migrated_count}");
            $this->line("  Failed:    {$operation->failed_count}");
        }

        if (is_string($operation->failure_summary) && $operation->failure_summary !== '') {
            $this->warn("  {$operation->failure_summary}");
        }

        return $operation->status === MediaStorageMigration::STATUS_FAILED ? self::FAILURE : self::SUCCESS;
    }
}
