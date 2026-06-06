<?php

declare(strict_types=1);

namespace App\Support\DatabaseManagement;

use App\Models\DatabaseBackup;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Pure selection of retention deletion candidates from the current backup
 * inventory and configured policy. Never deletes below the minimum protected
 * count, and (when enabled) never the last successful verified backup
 * (ADM-DB-009).
 */
final class BackupRetentionService
{
    public function __construct(private readonly DatabaseManagementConfig $config) {}

    /**
     * @return Collection<int, DatabaseBackup>
     */
    public function candidates(): Collection
    {
        $query = DatabaseBackup::query()->latest('created_at')->latest('id');
        $query->getQuery()->whereIn('status', [DatabaseBackup::STATUS_COMPLETED, DatabaseBackup::STATUS_VERIFIED]);

        /** @var Collection<int, DatabaseBackup> $kept */
        $kept = $query->get();

        $minProtected = $this->config->retentionMinProtected();
        $maxCount = $this->config->retentionMaxCount();
        $maxAgeDays = $this->config->retentionMaxAgeDays();
        $keepLastVerified = $this->config->retentionKeepLastVerified();

        $lastVerifiedId = $keepLastVerified
            ? $kept->first(static fn (DatabaseBackup $backup): bool => $backup->status === DatabaseBackup::STATUS_VERIFIED
                || $backup->verification_status === DatabaseBackup::VERIFICATION_PASSED)?->id
            : null;

        $ageCutoff = $maxAgeDays > 0 ? Carbon::now()->subDays($maxAgeDays) : null;

        $candidates = new Collection;

        foreach ($kept->values() as $index => $backup) {
            // Always protect the newest N artifacts.
            if ($index < $minProtected) {
                continue;
            }

            if ($lastVerifiedId !== null && $backup->id === $lastVerifiedId) {
                continue;
            }

            $expiredByAge = $ageCutoff !== null
                && $backup->created_at->lt($ageCutoff);
            $excessByCount = $maxCount > 0 && $index >= $maxCount;

            if ($expiredByAge || $excessByCount) {
                $candidates->push($backup);
            }
        }

        return $candidates;
    }
}
