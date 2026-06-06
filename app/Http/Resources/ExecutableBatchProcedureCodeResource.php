<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class ExecutableBatchProcedureCodeResource extends JsonResource
{
    /**
     * @return array{
     *     code: string,
     *     label: string,
     *     description: string,
     *     group: string,
     *     default_schedule_type: string,
     *     prerequisite_codes: array<int, string>,
     * }
     */
    public function toArray(Request $request): array
    {
        $item = is_array($this->resource) ? $this->resource : [];

        return [
            'code' => is_string($item['code'] ?? null) ? $item['code'] : '',
            'label' => is_string($item['label'] ?? null) ? $item['label'] : '',
            'description' => is_string($item['description'] ?? null) ? $item['description'] : '',
            'group' => is_string($item['group'] ?? null) ? $item['group'] : '',
            'default_schedule_type' => is_string($item['default_schedule_type'] ?? null) ? $item['default_schedule_type'] : '',
            'prerequisite_codes' => $this->stringList($item['prerequisite_codes'] ?? []),
        ];
    }

    /**
     * @return array<int, string>
     */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, static fn (mixed $item): bool => is_string($item)));
    }
}
