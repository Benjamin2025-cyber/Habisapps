<?php

declare(strict_types=1);

namespace App\Application\JournalEntries;

use App\Http\Controllers\BaseController;
use App\Http\Requests\StoreJournalEntryRequest;
use App\Http\Requests\UpdateJournalEntryRequest;
use App\Http\Resources\JournalEntryCollection;
use App\Http\Resources\JournalEntryResource;
use App\Models\Agency;
use App\Models\JournalEntry;
use App\Models\TellerTransaction;
use App\Models\User;
use App\Support\Security\SecurityAudit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

final class JournalEntryWorkflow extends BaseController
{
    public function __construct(
        private readonly SecurityAudit $securityAudit,
        private readonly CreateJournalEntryReversal $createJournalEntryReversal,
    ) {}

    public function index(Request $request): JournalEntryCollection|JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || $actor->cannot('viewAny', JournalEntry::class)) {
            return $this->respondForbidden();
        }

        $query = JournalEntry::query()->with(['agency', 'lines', 'reversalOf', 'submittedBy', 'reviewedBy'])->latest();

        $search = $request->query('search');
        if (is_string($search) && trim($search) !== '') {
            $term = trim($search);
            $query->where(function (Builder $builder) use ($term): void {
                $builder
                    ->where('reference', 'ilike', '%'.$term.'%')
                    ->orWhere('description', 'ilike', '%'.$term.'%')
                    ->orWhere('source_module', 'ilike', '%'.$term.'%')
                    ->orWhere('source_type', 'ilike', '%'.$term.'%')
                    ->orWhere('status', 'ilike', '%'.$term.'%');
            });
        }

        return new JournalEntryCollection($query->paginate(min(max($request->integer('per_page', 25), 1), 100)));
    }

    public function store(StoreJournalEntryRequest $request): JsonResponse
    {
        $agency = null;
        if ($request->filled('agency_public_id')) {
            $agency = Agency::query()->where('public_id', $request->string('agency_public_id'))->first();
            if (! $agency instanceof Agency) {
                return $this->respondUnprocessable(errors: ['agency_public_id' => ['The selected agency is invalid.']]);
            }
        }

        $journalEntry = JournalEntry::query()->create([
            'public_id' => (string) Str::ulid(),
            'reference' => $request->string('reference')->toString(),
            'business_date' => $request->date('business_date')?->toDateString(),
            'posted_at' => null,
            'agency_id' => $agency?->id,
            'source_module' => $request->input('source_module'),
            'source_type' => $request->input('source_type'),
            'source_public_id' => $request->input('source_public_id'),
            'status' => JournalEntry::STATUS_DRAFT,
            'description' => $request->input('description'),
            'created_by_user_id' => $request->user()?->id,
            'posted_by_user_id' => null,
            'reversed_by_user_id' => null,
            'reversal_of_journal_entry_id' => null,
            'idempotency_key' => $request->input('idempotency_key'),
        ]);

        $this->securityAudit->record('journal_entry.created', actor: $request->user(), subject: $journalEntry, request: $request);

        return $this->respondCreated(JournalEntryResource::make($journalEntry->loadMissing(['agency', 'lines', 'reversalOf', 'submittedBy', 'reviewedBy'])), 'Journal entry created successfully');
    }

    public function show(Request $request, JournalEntry $journalEntry): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || $actor->cannot('view', $journalEntry)) {
            return $this->respondForbidden();
        }

        return $this->respondSuccess(JournalEntryResource::make($journalEntry->loadMissing(['agency', 'lines', 'reversalOf', 'submittedBy', 'reviewedBy'])));
    }

    public function update(UpdateJournalEntryRequest $request, JournalEntry $journalEntry): JsonResponse
    {
        if ($journalEntry->status !== JournalEntry::STATUS_DRAFT) {
            return $this->respondUnprocessable(errors: ['journal_entry' => ['Only draft journal entries can be updated.']]);
        }

        $journalEntry->fill($request->validated())->save();

        $this->securityAudit->record('journal_entry.updated', actor: $request->user(), subject: $journalEntry, properties: [
            'changed_fields' => array_keys($request->validated()),
        ], request: $request);

        return $this->respondSuccess(JournalEntryResource::make($journalEntry->loadMissing(['agency', 'lines', 'reversalOf', 'submittedBy', 'reviewedBy'])), 'Journal entry updated successfully');
    }

    public function submit(Request $request, JournalEntry $journalEntry): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || $actor->cannot('submit', $journalEntry)) {
            return $this->respondForbidden();
        }

        if ($journalEntry->status !== JournalEntry::STATUS_DRAFT) {
            return $this->respondUnprocessable(errors: ['journal_entry' => ['Only draft journal entries can be submitted for review.']]);
        }

        $journalEntry->loadMissing('lines');
        if ($journalEntry->lines->count() < 2) {
            return $this->respondUnprocessable(errors: ['journal_entry' => ['Draft journal entries must contain at least two lines before review.']]);
        }

        $debitTotal = $journalEntry->lines->sum('debit_minor');
        $creditTotal = $journalEntry->lines->sum('credit_minor');
        if ($debitTotal !== $creditTotal) {
            return $this->respondUnprocessable(errors: ['journal_entry' => ['Draft journal entries must be balanced before review.']]);
        }

        $journalEntry->update([
            'status' => JournalEntry::STATUS_SUBMITTED,
            'submitted_at' => now(),
            'submitted_by_user_id' => $actor->id,
        ]);
        $this->securityAudit->record('journal_entry.submitted_for_review', actor: $request->user(), subject: $journalEntry, request: $request);

        return $this->respondSuccess(JournalEntryResource::make($journalEntry->refresh()->loadMissing(['agency', 'lines', 'reversalOf', 'submittedBy', 'reviewedBy'])), 'Journal entry submitted for review successfully');
    }

    public function approve(Request $request, JournalEntry $journalEntry): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || $actor->cannot('approve', $journalEntry)) {
            return $this->respondForbidden();
        }

        if ($journalEntry->status !== JournalEntry::STATUS_SUBMITTED) {
            return $this->respondUnprocessable(errors: ['journal_entry' => ['Only submitted journal entries can be approved.']]);
        }

        if ($journalEntry->created_by_user_id === $actor->id || $journalEntry->submitted_by_user_id === $actor->id) {
            return $this->respondForbidden('Journal approval requires a reviewer different from the maker.');
        }

        $validated = Validator::make($request->all(), [
            'comment' => ['nullable', 'string', 'max:2000'],
        ])->validate();

        $journalEntry->update([
            'status' => JournalEntry::STATUS_APPROVED,
            'reviewed_at' => now(),
            'reviewed_by_user_id' => $actor->id,
            'review_comment' => $validated['comment'] ?? null,
            'rejection_reason' => null,
        ]);

        $this->securityAudit->record('journal_entry.approved', actor: $actor, subject: $journalEntry, request: $request);

        return $this->respondSuccess(
            JournalEntryResource::make($journalEntry->refresh()->loadMissing(['agency', 'lines', 'reversalOf', 'submittedBy', 'reviewedBy'])),
            'Journal entry approved successfully'
        );
    }

    public function reject(Request $request, JournalEntry $journalEntry): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || $actor->cannot('reject', $journalEntry)) {
            return $this->respondForbidden();
        }

        if ($journalEntry->status !== JournalEntry::STATUS_SUBMITTED) {
            return $this->respondUnprocessable(errors: ['journal_entry' => ['Only submitted journal entries can be rejected.']]);
        }

        if ($journalEntry->created_by_user_id === $actor->id || $journalEntry->submitted_by_user_id === $actor->id) {
            return $this->respondForbidden('Journal rejection requires a reviewer different from the maker.');
        }

        $validated = Validator::make($request->all(), [
            'reason' => ['required', 'string', 'max:2000'],
            'comment' => ['nullable', 'string', 'max:2000'],
        ])->validate();

        $journalEntry->update([
            'status' => JournalEntry::STATUS_REJECTED,
            'reviewed_at' => now(),
            'reviewed_by_user_id' => $actor->id,
            'review_comment' => $validated['comment'] ?? null,
            'rejection_reason' => $validated['reason'],
        ]);
        $this->syncCashManualJournalTransactionStatus($journalEntry, TellerTransaction::STATUS_CANCELLED);

        $this->securityAudit->record('journal_entry.rejected', actor: $actor, subject: $journalEntry, request: $request);

        return $this->respondSuccess(
            JournalEntryResource::make($journalEntry->refresh()->loadMissing(['agency', 'lines', 'reversalOf', 'submittedBy', 'reviewedBy'])),
            'Journal entry rejected successfully'
        );
    }

    public function post(Request $request, JournalEntry $journalEntry): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || $actor->cannot('post', $journalEntry)) {
            return $this->respondForbidden();
        }

        try {
            $posted = DB::transaction(function () use ($actor, $journalEntry): JournalEntry {
                DB::table('journal_entries')
                    ->where('id', $journalEntry->id)
                    ->lockForUpdate()
                    ->first();

                /** @var JournalEntry $entry */
                $entry = JournalEntry::query()->with('lines')->findOrFail($journalEntry->id);

                if ($entry->status === JournalEntry::STATUS_POSTED) {
                    return $entry;
                }

                if ($entry->status !== JournalEntry::STATUS_APPROVED) {
                    throw new \DomainException('Only approved journal entries can be posted.');
                }

                $this->assertBalancedForWorkflow($entry, 'Approved journal entries');

                $entry->update([
                    'status' => JournalEntry::STATUS_POSTED,
                    'posted_at' => now(),
                    'posted_by_user_id' => $actor->id,
                ]);
                if ($entry->reversal_of_journal_entry_id !== null) {
                    JournalEntry::query()
                        ->whereKey($entry->reversal_of_journal_entry_id)
                        ->where('status', JournalEntry::STATUS_POSTED)
                        ->update([
                            'status' => JournalEntry::STATUS_REVERSED,
                            'reversed_by_user_id' => $actor->id,
                            'updated_at' => now(),
                        ]);
                }
                $this->syncCashManualJournalTransactionStatus($entry, TellerTransaction::STATUS_POSTED);

                return $entry->refresh();
            });
        } catch (\DomainException $exception) {
            return $this->respondUnprocessable(errors: ['journal_entry' => [$exception->getMessage()]]);
        }

        $this->securityAudit->record('journal_entry.posted', actor: $actor, subject: $posted, request: $request);

        return $this->respondSuccess(
            JournalEntryResource::make($posted->loadMissing(['agency', 'lines', 'reversalOf', 'submittedBy', 'reviewedBy'])),
            'Journal entry posted successfully'
        );
    }

    public function reverse(Request $request, JournalEntry $journalEntry): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || $actor->cannot('reverse', $journalEntry)) {
            return $this->respondForbidden();
        }

        if ($journalEntry->status !== JournalEntry::STATUS_POSTED) {
            return $this->respondUnprocessable(errors: ['journal_entry' => ['Only posted journal entries can be reversed.']]);
        }

        $reversal = $this->createJournalEntryReversal->execute($actor, $journalEntry);

        $this->securityAudit->record('journal_entry.reversal_created', actor: $request->user(), subject: $reversal, properties: [
            'reversal_of_reference' => $journalEntry->reference,
        ], request: $request);

        return $this->respondCreated(JournalEntryResource::make($reversal->loadMissing(['agency', 'lines', 'reversalOf', 'submittedBy', 'reviewedBy'])), 'Journal reversal created successfully');
    }

    public function destroy(Request $request, JournalEntry $journalEntry): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || $actor->cannot('delete', $journalEntry)) {
            return $this->respondForbidden();
        }

        if (! in_array($journalEntry->status, [JournalEntry::STATUS_DRAFT, JournalEntry::STATUS_SUBMITTED, JournalEntry::STATUS_REJECTED], true)) {
            return $this->respondUnprocessable(errors: ['journal_entry' => ['Only draft, submitted, or rejected journal entries can be cancelled.']]);
        }

        $journalEntry->update(['status' => JournalEntry::STATUS_CANCELLED]);
        $this->securityAudit->record('journal_entry.cancelled', actor: $request->user(), subject: $journalEntry, request: $request);

        return $this->respondSuccess(message: 'Journal entry cancelled successfully');
    }

    private function assertBalancedForWorkflow(JournalEntry $journalEntry, string $label): void
    {
        if ($journalEntry->lines->count() < 2) {
            throw new \DomainException($label.' must contain at least two lines.');
        }

        $debitTotal = $journalEntry->lines->sum('debit_minor');
        $creditTotal = $journalEntry->lines->sum('credit_minor');
        if ($debitTotal !== $creditTotal) {
            throw new \DomainException($label.' must be balanced.');
        }
    }

    private function syncCashManualJournalTransactionStatus(JournalEntry $journalEntry, string $status): void
    {
        if ($journalEntry->source_module !== 'cash_operations'
            || $journalEntry->source_type !== TellerTransaction::TYPE_MANUAL_JOURNAL
            || $journalEntry->source_public_id === null) {
            return;
        }

        TellerTransaction::query()
            ->where('public_id', $journalEntry->source_public_id)
            ->where('transaction_type', TellerTransaction::TYPE_MANUAL_JOURNAL)
            ->update(['status' => $status]);
    }
}
