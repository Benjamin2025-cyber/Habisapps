<?php

declare(strict_types=1);

namespace App\Application\Accounting;

use App\Models\AccountHold;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ReleaseAccountHold
{
    public function handle(AccountHold $accountHold, User $actor, ?string $reference = null, ?string $releaseReason = null): AccountHold
    {
        if ($accountHold->status !== AccountHold::STATUS_ACTIVE) {
            throw ValidationException::withMessages([
                'account_hold' => ['Only active holds can be released.'],
            ]);
        }

        return DB::transaction(function () use ($accountHold, $actor, $reference, $releaseReason): AccountHold {
            $accountHold->update([
                'status' => AccountHold::STATUS_RELEASED,
                'released_at' => now(),
                'released_by_user_id' => $actor->id,
                'release_reason' => $releaseReason,
                'reference' => $reference ?? $accountHold->reference,
            ]);

            return $accountHold->refresh();
        });
    }
}
