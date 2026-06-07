<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Pagination\LengthAwarePaginator;

final class AccountHoldCollection extends ResourceCollection
{
    public $collects = AccountHoldResource::class;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $paginator = $this->resource;

        if (! $paginator instanceof LengthAwarePaginator) {
            return [
                'success' => true,
                'message' => __('api.success'),
                'data' => [
                    'account_holds' => $this->collection,
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
            'message' => __('api.success'),
            'data' => [
                'account_holds' => $this->collection,
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
