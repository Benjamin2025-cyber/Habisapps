<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Application\JournalEntries\JournalEntryWorkflow;
use App\Http\Controllers\BaseController;
use App\Http\Requests\StoreJournalEntryRequest;
use App\Http\Requests\UpdateJournalEntryRequest;
use App\Http\Resources\JournalEntryCollection;
use App\Models\JournalEntry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class JournalEntryController extends BaseController
{
    public function __construct(
        private readonly JournalEntryWorkflow $workflow,
    ) {}

    public function index(Request $request): JournalEntryCollection|JsonResponse
    {
        return $this->workflow->index($request);
    }

    public function store(StoreJournalEntryRequest $request): JsonResponse
    {
        return $this->workflow->store($request);
    }

    public function show(Request $request, JournalEntry $journalEntry): JsonResponse
    {
        return $this->workflow->show($request, $journalEntry);
    }

    public function update(UpdateJournalEntryRequest $request, JournalEntry $journalEntry): JsonResponse
    {
        return $this->workflow->update($request, $journalEntry);
    }

    public function submit(Request $request, JournalEntry $journalEntry): JsonResponse
    {
        return $this->workflow->submit($request, $journalEntry);
    }

    public function approve(Request $request, JournalEntry $journalEntry): JsonResponse
    {
        return $this->workflow->approve($request, $journalEntry);
    }

    public function reject(Request $request, JournalEntry $journalEntry): JsonResponse
    {
        return $this->workflow->reject($request, $journalEntry);
    }

    public function post(Request $request, JournalEntry $journalEntry): JsonResponse
    {
        return $this->workflow->post($request, $journalEntry);
    }

    public function reverse(Request $request, JournalEntry $journalEntry): JsonResponse
    {
        return $this->workflow->reverse($request, $journalEntry);
    }

    public function destroy(Request $request, JournalEntry $journalEntry): JsonResponse
    {
        return $this->workflow->destroy($request, $journalEntry);
    }
}
