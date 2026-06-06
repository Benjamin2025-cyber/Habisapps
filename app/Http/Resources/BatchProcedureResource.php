<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Application\BatchRuns\BatchProcedureRegistry;
use App\Models\BatchProcedure;
use App\Models\BatchProcedureOperationCode;
use App\Models\OperationCode;
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
            return [
                'public_id' => null,
                'code' => null,
                'name' => null,
                'description' => null,
                'schedule_type' => null,
                'execution_priority' => null,
                'schedule_metadata' => null,
                'operation_codes' => [],
                'status' => null,
                'executable' => false,
                'created_at' => null,
                'updated_at' => null,
            ];
        }

        return [
            'public_id' => $procedure->public_id,
            'code' => $procedure->code,
            'name' => $procedure->name,
            'description' => $procedure->description,
            'schedule_type' => $procedure->schedule_type,
            'execution_priority' => $procedure->execution_priority,
            'schedule_metadata' => $procedure->schedule_metadata,
            'operation_codes' => $this->operationCodes($procedure),
            'status' => $procedure->status,
            'executable' => BatchProcedureRegistry::isExecutable($procedure->code),
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

    /**
     * @return array<int, array<string, mixed>>
     */
    private function operationCodes(BatchProcedure $procedure): array
    {
        if (! $procedure->relationLoaded('operationCodes')) {
            return [];
        }

        return $procedure->operationCodes
            ->map(
                /**
                 * @return array<string, mixed>
                 */
                static function (OperationCode $code): array {
                    $pivot = $code->getRelationValue('pivot');
                    $attachmentStatus = $pivot instanceof BatchProcedureOperationCode ? $pivot->status : null;

                    return [
                        'public_id' => $code->public_id,
                        'code' => $code->code,
                        'label' => $code->label,
                        'module' => $code->module,
                        'operation_type' => $code->operation_type,
                        'direction' => $code->direction,
                        'status' => $code->status,
                        'attachment_status' => $attachmentStatus,
                    ];
                }
            )
            ->values()
            ->all();
    }
}
