<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\BaseController;
use App\Http\Requests\StoreJournalEntryRequest;
use App\Http\Requests\UpdateJournalEntryRequest;
use App\Http\Resources\JournalEntryCollection;
use App\Http\Resources\JournalEntryResource;
use App\Models\Agency;
use App\Models\JournalEntry;
use App\Models\User;
use App\Support\Security\SecurityAudit;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class JournalEntryController extends BaseController
{
    public function __construct(
        private readonly SecurityAudit $securityAudit,
    ) {}

    #[Response(status: 200, type: 'array{success: bool, message: string, data: array{journal_entries: array<int, \App\Http\Resources\JournalEntryResource>}, errors: null, meta: array{pagination: array{current_page: int, per_page: int, total: int, last_page: int}}}')]
    public function index(Request $request): JournalEntryCollection|JsonResponse
    {
        if (! $request->user() instanceof User || ! $request->user()->hasRole('platform-admin')) {
            return $this->respondForbidden();
        }

        return new JournalEntryCollection(JournalEntry::query()->with(['agency', 'lines', 'reversalOf'])->latest()->paginate(min(max($request->integer('per_page', 25), 1), 100)));
    }

    #[Response(status: 201, type: 'array{success: bool, message: string, data: array{journal_entry: \App\Http\Resources\JournalEntryResource}, errors: null, meta: null}')]
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

        return $this->respondCreated(JournalEntryResource::make($journalEntry->loadMissing(['agency', 'lines', 'reversalOf'])), 'Journal entry created successfully');
    }

    #[Response(status: 200, type: 'array{success: bool, message: string, data: array{journal_entry: \App\Http\Resources\JournalEntryResource}, errors: null, meta: null}')]
    public function show(Request $request, JournalEntry $journalEntry): JsonResponse
    {
        if (! $request->user() instanceof User || ! $request->user()->hasRole('platform-admin')) {
            return $this->respondForbidden();
        }

        return $this->respondSuccess(JournalEntryResource::make($journalEntry->loadMissing(['agency', 'lines', 'reversalOf'])));
    }

    #[Response(status: 200, type: 'array{success: bool, message: string, data: array{journal_entry: \App\Http\Resources\JournalEntryResource}, errors: null, meta: null}')]
    public function update(UpdateJournalEntryRequest $request, JournalEntry $journalEntry): JsonResponse
    {
        if ($journalEntry->status !== JournalEntry::STATUS_DRAFT) {
            return $this->respondUnprocessable(errors: ['journal_entry' => ['Only draft journal entries can be updated.']]);
        }

        $journalEntry->fill($request->validated())->save();

        $this->securityAudit->record('journal_entry.updated', actor: $request->user(), subject: $journalEntry, properties: [
            'changed_fields' => array_keys($request->validated()),
        ], request: $request);

        return $this->respondSuccess(JournalEntryResource::make($journalEntry->loadMissing(['agency', 'lines', 'reversalOf'])), 'Journal entry updated successfully');
    }

    #[Response(status: 200, type: 'array{success: bool, message: string, data: array{journal_entry: \App\Http\Resources\JournalEntryResource}, errors: null, meta: null}')]
    public function submit(Request $request, JournalEntry $journalEntry): JsonResponse
    {
        if (! $request->user() instanceof User || ! $request->user()->hasRole('platform-admin')) {
            return $this->respondForbidden();
        }

        if ($journalEntry->status !== JournalEntry::STATUS_DRAFT) {
            return $this->respondUnprocessable(errors: ['journal_entry' => ['Only draft journal entries can be submitted for review.']]);
        }

        $journalEntry->loadMissing('lines');
        if ($journalEntry->lines->isEmpty()) {
            return $this->respondUnprocessable(errors: ['journal_entry' => ['Draft journal entries must contain at least one line before review.']]);
        }

        $debitTotal = $journalEntry->lines->sum('debit_minor');
        $creditTotal = $journalEntry->lines->sum('credit_minor');
        if ($debitTotal !== $creditTotal) {
            return $this->respondUnprocessable(errors: ['journal_entry' => ['Draft journal entries must be balanced before review.']]);
        }

        $journalEntry->update(['status' => JournalEntry::STATUS_PENDING_REVIEW]);
        $this->securityAudit->record('journal_entry.submitted_for_review', actor: $request->user(), subject: $journalEntry, request: $request);

        return $this->respondSuccess(JournalEntryResource::make($journalEntry->loadMissing(['agency', 'lines', 'reversalOf'])), 'Journal entry submitted for review successfully');
    }

    #[Response(status: 201, type: 'array{success: bool, message: string, data: array{journal_entry: \App\Http\Resources\JournalEntryResource}, errors: null, meta: null}')]
    public function reverse(Request $request, JournalEntry $journalEntry): JsonResponse
    {
        if (! $request->user() instanceof User || ! $request->user()->hasRole('platform-admin')) {
            return $this->respondForbidden();
        }

        $reversal = DB::transaction(function () use ($journalEntry, $request): JournalEntry {
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
                'created_by_user_id' => $request->user()->id,
                'posted_by_user_id' => null,
                'reversed_by_user_id' => null,
                'reversal_of_journal_entry_id' => $journalEntry->id,
                'idempotency_key' => null,
            ]);
        });

        $this->securityAudit->record('journal_entry.reversal_created', actor: $request->user(), subject: $reversal, properties: [
            'reversal_of_reference' => $journalEntry->reference,
        ], request: $request);

        return $this->respondCreated(JournalEntryResource::make($reversal->loadMissing(['agency', 'lines', 'reversalOf'])), 'Journal reversal created successfully');
    }

    public function destroy(Request $request, JournalEntry $journalEntry): JsonResponse
    {
        if (! $request->user() instanceof User || ! $request->user()->hasRole('platform-admin')) {
            return $this->respondForbidden();
        }

        $journalEntry->update(['status' => JournalEntry::STATUS_ARCHIVED]);
        $this->securityAudit->record('journal_entry.archived', actor: $request->user(), subject: $journalEntry, request: $request);

        return $this->respondSuccess(message: 'Journal entry archived successfully');
    }
}
