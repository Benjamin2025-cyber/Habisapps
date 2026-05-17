<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AccountHold;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class ReleaseExpiredAccountHolds extends Command
{
    protected $signature = 'account-holds:release-expired {--dry-run : Count releasable expired holds without changing them}';

    protected $description = 'Release active account holds whose expiry timestamp has passed.';

    public function handle(): int
    {
        $query = DB::table('account_holds')
            ->where('status', AccountHold::STATUS_ACTIVE)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now());

        $count = $query->count();
        if ($this->option('dry-run')) {
            $this->info($count.' expired account hold(s) would be released.');

            return self::SUCCESS;
        }

        $query->update([
            'status' => AccountHold::STATUS_RELEASED,
            'released_at' => now(),
            'release_reason' => 'expired_hold_sweeper',
            'updated_at' => now(),
        ]);

        $this->info($count.' expired account hold(s) released.');

        return self::SUCCESS;
    }
}
