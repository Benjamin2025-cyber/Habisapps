<?php

declare(strict_types=1);

namespace App\Application\JournalEntries;

use App\Http\Controllers\BaseController;
use App\Models\JournalEntry;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class JournalEntryStatsWorkflow extends BaseController
{
    public function __construct(
        private readonly JournalEntryListQuery $journalEntryListQuery,
    ) {}

    public function stats(Request $request): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || $actor->cannot('viewAny', JournalEntry::class)) {
            return $this->respondForbidden();
        }

        $built = $this->journalEntryListQuery->build($actor, $request);
        if ($built['error'] instanceof JsonResponse) {
            return $built['error'];
        }

        $byStatus = $this->zeroFilledStatusCounts();
        $rows = (clone $built['query'])->toBase()->reorder()
            ->selectRaw('status, COUNT(*) AS row_count')
            ->groupBy('status')
            ->get();

        foreach ($rows as $row) {
            $status = (string) ($row->status ?? '');
            $count = is_numeric($row->row_count ?? null) ? (int) $row->row_count : 0;
            if ($status !== '' && array_key_exists($status, $byStatus)) {
                $byStatus[$status] = $count;
            }
        }

        return $this->respondSuccess([
            'by_status' => $byStatus,
            'submitted_count' => $byStatus[JournalEntry::STATUS_SUBMITTED],
        ], 'Journal entry statistics');
    }

    /**
     * @return array<string, int>
     */
    private function zeroFilledStatusCounts(): array
    {
        $counts = [];
        foreach (JournalEntryListQuery::STATUS_KEYS as $status) {
            $counts[$status] = 0;
        }

        return $counts;
    }
}
