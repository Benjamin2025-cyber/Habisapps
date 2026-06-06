<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Application\JournalEntries\JournalEntryStatsWorkflow;
use App\Application\JournalEntries\JournalEntryWorkflow;
use App\Http\Controllers\BaseController;
use App\Http\Requests\StoreJournalEntryRequest;
use App\Http\Requests\UpdateJournalEntryRequest;
use App\Http\Resources\JournalEntryCollection;
use App\Models\JournalEntry;
use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class JournalEntryController extends BaseController
{
    public function __construct(
        private readonly JournalEntryWorkflow $workflow,
        private readonly JournalEntryStatsWorkflow $journalStats,
    ) {}

    #[QueryParameter('filter[status]', 'Limit results to a journal entry status.', type: 'string')]
    #[QueryParameter('agency_public_id', 'Institution-scope readers may limit results to an agency public ID.', type: 'string')]
    #[QueryParameter('search', 'Search reference, description, source module/type, and status.', type: 'string')]
    public function stats(Request $request): JsonResponse
    {
        return $this->journalStats->stats($request);
    }

    #[QueryParameter('filter[status]', 'Limit results to a journal entry status.', type: 'string')]
    #[QueryParameter('agency_public_id', 'Institution-scope readers may limit results to an agency public ID.', type: 'string')]
    #[QueryParameter('search', 'Search reference, description, source module/type, and status.', type: 'string')]
    #[QueryParameter('per_page', 'Results per page. Capped at 100.', type: 'integer')]
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
