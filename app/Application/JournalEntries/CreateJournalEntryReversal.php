<?php

declare(strict_types=1);

namespace App\Application\JournalEntries;

use App\Models\JournalEntry;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class CreateJournalEntryReversal
{
    public function execute(User $actor, JournalEntry $journalEntry): JournalEntry
    {
        return DB::transaction(function () use ($actor, $journalEntry): JournalEntry {
            return JournalEntry::query()->create([
                'public_id' => (string) Str::ulid(),
                'reference' => $journalEntry->reference.'-REV-'.Str::upper(Str::random(6)),
                'business_date' => $journalEntry->business_date,
                'posted_at' => null,
                'agency_id' => $journalEntry->agency_id,
                'source_module' => $journalEntry->source_module,
                'source_type' => $journalEntry->source_type,
                'source_public_id' => $journalEntry->source_public_id,
                'status' => JournalEntry::STATUS_DRAFT,
                'description' => 'Reversal of '.$journalEntry->reference,
                'created_by_user_id' => $actor->id,
                'posted_by_user_id' => null,
                'reversed_by_user_id' => null,
                'reversal_of_journal_entry_id' => $journalEntry->id,
                'idempotency_key' => null,
            ]);
        });
    }
}
