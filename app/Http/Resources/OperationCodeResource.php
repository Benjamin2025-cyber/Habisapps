<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\OperationCode;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin OperationCode
 */
final class OperationCodeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var OperationCode $code */
        $code = $this->resource;

        return [
            'public_id' => $code->public_id,
            'code' => $code->code,
            'label' => $code->label,
            'module' => $code->module,
            'operation_type' => $code->operation_type,
            'direction' => $code->direction,
            'status' => $code->status,
            'metadata' => $code->metadata,
            'created_at' => $code->created_at?->toAtomString(),
            'updated_at' => $code->updated_at?->toAtomString(),
        ];
    }
}
