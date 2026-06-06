<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * @mixin StaffUserResource
 */
final class StaffUserCollection extends ResourceCollection
{
    /**
     * @var string
     */
    public $collects = StaffUserResource::class;

    /**
     * Actor-visible staff status counts, set by the listing workflow.
     *
     * @var array<string, int>|null
     */
    private ?array $statusCounts = null;

    /**
     * @param  array<string, int>  $statusCounts
     */
    public function withStatusCounts(array $statusCounts): self
    {
        $this->statusCounts = $statusCounts;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $paginator = $this->resource;

        $meta = $paginator instanceof LengthAwarePaginator
            ? [
                'pagination' => [
                    'current_page' => $paginator->currentPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                    'last_page' => $paginator->lastPage(),
                ],
            ]
            : [
                'pagination' => [
                    'current_page' => 1,
                    'per_page' => 25,
                    'total' => 0,
                    'last_page' => 1,
                ],
            ];

        if ($this->statusCounts !== null) {
            $meta['status_counts'] = $this->statusCounts;
        }

        return [
            'success' => true,
            'message' => 'Success',
            'data' => [
                'users' => $this->collection,
            ],
            'errors' => null,
            'meta' => $meta,
        ];
    }
}
