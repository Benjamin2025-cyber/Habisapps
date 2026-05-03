<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Pagination\LengthAwarePaginator;

final class CustomerAccountCollection extends ResourceCollection
{
    public $collects = CustomerAccountResource::class;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $paginator = $this->resource;

        if (! $paginator instanceof LengthAwarePaginator) {
            return [
                'success' => true,
                'message' => 'Success',
                'data' => [
                    'customer_accounts' => $this->collection,
                ],
                'errors' => null,
                'meta' => [
                    'pagination' => [
                        'current_page' => 1,
                        'per_page' => 25,
                        'total' => 0,
                        'last_page' => 1,
                    ],
                ],
            ];
        }

        return [
            'success' => true,
            'message' => 'Success',
            'data' => [
                'customer_accounts' => $this->collection,
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
