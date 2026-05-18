<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Pagination\LengthAwarePaginator;

final class CustomerAccountSignatureCollection extends ResourceCollection
{
    public $collects = CustomerAccountSignatureResource::class;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $paginator = $this->resource;

        if (! $paginator instanceof LengthAwarePaginator) {
            return [
                'success' => true,
                'message' => 'Success',
                'data' => [
                    'signatures' => $this->collection,
                ],
                'errors' => null,
            ];
        }

        return [
            'success' => true,
            'message' => 'Success',
            'data' => [
                'signatures' => $this->collection,
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
