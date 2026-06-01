<?php

declare(strict_types=1);

namespace App\Application\JournalEntries;

use App\Models\AccountingDay;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\User;
use App\Support\AccountingDay\AccountingDayGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class CreateJournalEntryReversal
{
    public function __construct(
        private readonly AccountingDayGuard $accountingDayGuard,
    ) {}

    public function execute(User $actor, JournalEntry $journalEntry, bool $postImmediately = false): JournalEntry
    {
        // Corrections post to the CURRENT open accounting day as a new event,
        // never back into the original (possibly closed) day. This throws if no
        // accounting day is open for the journal's scope.
        $reversalDay = $this->accountingDayGuard->assertCanRegister($actor, 'journal.reverse', $journalEntry->agency_id);
        $reversalBusinessDate = $reversalDay->business_date?->toDateString();

        return DB::transaction(function () use ($actor, $journalEntry, $postImmediately, $reversalDay, $reversalBusinessDate): JournalEntry {
            $journalEntry->loadMissing('lines');

            $reversal = JournalEntry::query()->create([
                'public_id' => (string) Str::ulid(),
                'reference' => $journalEntry->reference.'-REV-'.Str::upper(Str::random(6)),
                'business_date' => $reversalBusinessDate,
                'accounting_day_id' => $reversalDay->id,
                'posted_at' => null,
                'agency_id' => $journalEntry->agency_id,
                'source_module' => $journalEntry->source_module,
                'source_type' => $journalEntry->source_type,
                'source_public_id' => $journalEntry->source_public_id,
                'status' => JournalEntry::STATUS_DRAFT,
                'description' => 'Reversal of '.$journalEntry->reference,
                'created_by_user_id' => $actor->id,
                'submitted_by_user_id' => $actor->id,
                'submitted_at' => now(),
                'posted_by_user_id' => null,
                'reversed_by_user_id' => null,
                'reversal_of_journal_entry_id' => $journalEntry->id,
                'idempotency_key' => null,
            ]);

            foreach ($journalEntry->lines as $line) {
                JournalLine::query()->create([
                    'public_id' => (string) Str::ulid(),
                    'agency_id' => $line->agency_id,
                    'journal_entry_id' => $reversal->id,
                    'ledger_account_id' => $line->ledger_account_id,
                    'customer_account_id' => $line->customer_account_id,
                    'loan_id' => $line->loan_id,
                    'debit_minor' => $line->credit_minor,
                    'credit_minor' => $line->debit_minor,
                    'currency' => $line->currency,
                    'line_memo' => 'Reversal: '.$line->line_memo,
                ]);
            }

            if ($postImmediately) {
                $reversal->forceFill([
                    'status' => JournalEntry::STATUS_SUBMITTED,
                    'submitted_at' => now(),
                    'submitted_by_user_id' => $actor->id,
                ])->save();
                $reversal->forceFill([
                    'status' => JournalEntry::STATUS_APPROVED,
                    'reviewed_at' => now(),
                    'reviewed_by_user_id' => $actor->id,
                ])->save();
                $reversal->forceFill([
                    'status' => JournalEntry::STATUS_POSTED,
                    'posted_at' => now(),
                    'posted_by_user_id' => $actor->id,
                ])->save();
                $journalEntry->update([
                    'status' => JournalEntry::STATUS_REVERSED,
                    'reversed_by_user_id' => $actor->id,
                ]);
            } else {
                $reversal->forceFill([
                    'status' => JournalEntry::STATUS_SUBMITTED,
                    'submitted_at' => now(),
                    'submitted_by_user_id' => $actor->id,
                ])->save();
            }

            return $reversal->refresh();
        });
    }
}
