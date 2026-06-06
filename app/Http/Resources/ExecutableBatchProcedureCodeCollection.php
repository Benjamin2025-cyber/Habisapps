<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;

final class ExecutableBatchProcedureCodeCollection extends ResourceCollection
{
    /**
     * @var string
     */
    public $collects = ExecutableBatchProcedureCodeResource::class;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $count = $this->collection?->count() ?? 0;

        return [
            'success' => true,
            'message' => 'Executable batch-procedure codes retrieved successfully',
            'data' => [
                'executable_codes' => $this->collection,
            ],
            'errors' => null,
            'meta' => [
                'pagination' => [
                    'current_page' => 1,
                    'per_page' => $count,
                    'total' => $count,
                    'last_page' => 1,
                ],
            ],
        ];
    }
}
