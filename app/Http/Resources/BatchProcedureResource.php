<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\BatchProcedure;
use DateTimeInterface;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin BatchProcedure
 */
final class BatchProcedureResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $procedure = $this->resource;

        if (! $procedure instanceof BatchProcedure) {
            return [];
        }

        return [
            'public_id' => $procedure->public_id,
            'code' => $procedure->code,
            'name' => $procedure->name,
            'description' => $procedure->description,
            'schedule_type' => $procedure->schedule_type,
            'schedule_metadata' => $procedure->schedule_metadata,
            'status' => $procedure->status,
            'created_at' => $this->formatDate($procedure->created_at),
            'updated_at' => $this->formatDate($procedure->updated_at),
        ];
    }

    private function formatDate(mixed $value): ?string
    {
        if (! $value instanceof DateTimeInterface) {
            return null;
        }

        return $value->format(DateTimeInterface::ATOM);
    }
}
