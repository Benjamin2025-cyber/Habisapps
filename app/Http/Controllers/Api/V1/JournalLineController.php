<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\BaseController;
use App\Http\Requests\StoreJournalLineRequest;
use App\Http\Requests\UpdateJournalLineRequest;
use App\Http\Resources\JournalLineCollection;
use App\Http\Resources\JournalLineResource;
use App\Models\CustomerAccount;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\LedgerAccount;
use App\Models\User;
use App\Support\Security\SecurityAudit;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class JournalLineController extends BaseController
{
    public function __construct(
        private readonly SecurityAudit $securityAudit,
    ) {}

    #[Response(status: 200, type: 'array{success: bool, message: string, data: array{journal_lines: array<int, \App\Http\Resources\JournalLineResource>}, errors: null, meta: array{pagination: array{current_page: int, per_page: int, total: int, last_page: int}}}')]
    public function index(Request $request): JournalLineCollection|JsonResponse
    {
        if (! $request->user() instanceof User || ! $request->user()->hasRole('platform-admin')) {
            return $this->respondForbidden();
        }

        return new JournalLineCollection(JournalLine::query()->with(['journalEntry', 'ledgerAccount', 'customerAccount'])->latest()->paginate(min(max($request->integer('per_page', 25), 1), 100)));
    }

    #[Response(status: 201, type: 'array{success: bool, message: string, data: array{journal_line: \App\Http\Resources\JournalLineResource}, errors: null, meta: null}')]
    public function store(StoreJournalLineRequest $request): JsonResponse
    {
        $journalEntry = JournalEntry::query()->where('public_id', $request->string('journal_entry_public_id'))->first();
        if (! $journalEntry instanceof JournalEntry) {
            return $this->respondUnprocessable(errors: ['journal_entry_public_id' => ['The selected journal entry is invalid.']]);
        }

        if ($journalEntry->agency_id === null) {
            return $this->respondUnprocessable(errors: ['journal_entry_public_id' => ['The selected journal entry must be attached to an agency.']]);
        }
        if ($journalEntry->status !== JournalEntry::STATUS_DRAFT) {
            return $this->respondUnprocessable(errors: ['journal_entry_public_id' => ['Only draft journal entries can receive journal lines.']]);
        }

        $ledgerAccount = LedgerAccount::query()->where('public_id', $request->string('ledger_account_public_id'))->first();
        if (! $ledgerAccount instanceof LedgerAccount) {
            return $this->respondUnprocessable(errors: ['ledger_account_public_id' => ['The selected ledger account is invalid.']]);
        }
        if ($ledgerAccount->agency_id !== null && $ledgerAccount->agency_id !== $journalEntry->agency_id) {
            return $this->respondUnprocessable(errors: ['ledger_account_public_id' => ['The selected ledger account must belong to the same agency scope.']]);
        }
        if ($ledgerAccount->status !== LedgerAccount::STATUS_ACTIVE) {
            return $this->respondUnprocessable(errors: ['ledger_account_public_id' => ['The selected ledger account must be active.']]);
        }

        $customerAccount = null;
        if ($request->filled('customer_account_public_id')) {
            $customerAccount = CustomerAccount::query()->where('public_id', $request->string('customer_account_public_id'))->first();
            if (! $customerAccount instanceof CustomerAccount) {
                return $this->respondUnprocessable(errors: ['customer_account_public_id' => ['The selected customer account is invalid.']]);
            }
            if ($customerAccount->agency_id !== $journalEntry->agency_id) {
                return $this->respondUnprocessable(errors: ['customer_account_public_id' => ['The selected customer account must belong to the same agency scope.']]);
            }
        }

        $debit = $request->integer('debit_minor');
        $credit = $request->integer('credit_minor');
        if (($debit > 0 && $credit > 0) || ($debit === 0 && $credit === 0)) {
            return $this->respondUnprocessable(errors: ['debit_minor' => ['Exactly one side must be positive.'], 'credit_minor' => ['Exactly one side must be positive.']]);
        }

        $journalLine = JournalLine::query()->create([
            'public_id' => (string) Str::ulid(),
            'journal_entry_id' => $journalEntry->id,
            'agency_id' => $journalEntry->agency_id,
            'ledger_account_id' => $ledgerAccount->id,
            'customer_account_id' => $customerAccount?->id,
            'loan_id' => null,
            'debit_minor' => max(0, $debit),
            'credit_minor' => max(0, $credit),
            'currency' => $request->string('currency')->toString(),
            'line_memo' => $request->input('line_memo'),
        ]);

        $this->securityAudit->record('journal_line.created', actor: $request->user(), subject: $journalLine, request: $request);

        return $this->respondCreated(JournalLineResource::make($journalLine->loadMissing(['journalEntry', 'ledgerAccount', 'customerAccount'])), 'Journal line created successfully');
    }

    #[Response(status: 200, type: 'array{success: bool, message: string, data: array{journal_line: \App\Http\Resources\JournalLineResource}, errors: null, meta: null}')]
    public function show(Request $request, JournalLine $journalLine): JsonResponse
    {
        if (! $request->user() instanceof User || ! $request->user()->hasRole('platform-admin')) {
            return $this->respondForbidden();
        }

        return $this->respondSuccess(JournalLineResource::make($journalLine->loadMissing(['journalEntry', 'ledgerAccount', 'customerAccount'])));
    }

    #[Response(status: 200, type: 'array{success: bool, message: string, data: array{journal_line: \App\Http\Resources\JournalLineResource}, errors: null, meta: null}')]
    public function update(UpdateJournalLineRequest $request, JournalLine $journalLine): JsonResponse
    {
        $journalLine->loadMissing('journalEntry');
        if ($journalLine->journalEntry?->status !== JournalEntry::STATUS_DRAFT) {
            return $this->respondUnprocessable(errors: ['journal_line' => ['Only draft journal lines can be updated.']]);
        }

        $validated = $request->validated();
        $debit = array_key_exists('debit_minor', $validated) ? $request->integer('debit_minor') : $journalLine->debit_minor;
        $credit = array_key_exists('credit_minor', $validated) ? $request->integer('credit_minor') : $journalLine->credit_minor;
        if (($debit > 0 && $credit > 0) || ($debit === 0 && $credit === 0)) {
            return $this->respondUnprocessable(errors: ['debit_minor' => ['Exactly one side must be positive.'], 'credit_minor' => ['Exactly one side must be positive.']]);
        }

        $journalLine->fill($validated)->save();

        $this->securityAudit->record('journal_line.updated', actor: $request->user(), subject: $journalLine, properties: [
            'changed_fields' => array_keys($request->validated()),
        ], request: $request);

        return $this->respondSuccess(JournalLineResource::make($journalLine->loadMissing(['journalEntry', 'ledgerAccount', 'customerAccount'])), 'Journal line updated successfully');
    }

    public function destroy(Request $request, JournalLine $journalLine): JsonResponse
    {
        if (! $request->user() instanceof User || ! $request->user()->hasRole('platform-admin')) {
            return $this->respondForbidden();
        }

        $journalLine->loadMissing('journalEntry');
        if ($journalLine->journalEntry?->status !== JournalEntry::STATUS_DRAFT) {
            return $this->respondUnprocessable(errors: ['journal_line' => ['Only draft journal lines can be deleted.']]);
        }

        $journalLine->delete();
        $this->securityAudit->record('journal_line.archived', actor: $request->user(), subject: $journalLine, request: $request);

        return $this->respondSuccess(message: 'Journal line archived successfully');
    }
}
