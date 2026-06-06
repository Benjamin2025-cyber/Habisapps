<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * @mixin DatabaseRestoreOperationResource
 */
final class DatabaseRestoreOperationCollection extends ResourceCollection
{
    public $collects = DatabaseRestoreOperationResource::class;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var LengthAwarePaginator<int, mixed> $paginator */
        $paginator = $this->resource;

        return [
            'success' => true,
            'message' => 'Success',
            'data' => [
                'restore_operations' => $this->collection,
            ],
            'errors' => null,
            'meta' => [
                'pagination' => [
                    'current_page' => $paginator->currentPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                    'last_page' => $paginator->lastPage(),
                ],
            ],
        ];
    }
}
