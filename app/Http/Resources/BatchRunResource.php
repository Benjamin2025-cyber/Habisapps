<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Agency;
use App\Models\BatchRun;
use App\Models\BatchProcedure;
use App\Models\User;
use DateTimeInterface;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin BatchRun
 */
final class BatchRunResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $run = $this->resource;

        if (! $run instanceof BatchRun) {
            return [];
        }

        $procedure = $run->relationLoaded('batchProcedure') ? $run->batchProcedure : null;
        $agency = $run->relationLoaded('agency') ? $run->agency : null;
        $operator = $run->relationLoaded('operator') ? $run->operator : null;

        return [
            'public_id' => $run->public_id,
            'batch_procedure_public_id' => $procedure instanceof BatchProcedure ? $procedure->public_id : null,
            'batch_procedure_code' => $procedure instanceof BatchProcedure ? $procedure->code : null,
            'agency_public_id' => $agency instanceof Agency ? $agency->public_id : null,
            'agency_code' => $agency instanceof Agency ? $agency->code : null,
            'business_date' => $run->business_date,
            'status' => $run->status,
            'started_at' => $this->formatDate($run->started_at),
            'finished_at' => $this->formatDate($run->finished_at),
            'operator_public_id' => $operator instanceof User ? $operator->public_id : null,
            'summary_payload' => $run->summary_payload,
            'failure_reason' => $run->failure_reason,
            'idempotency_key' => $run->idempotency_key,
            'created_at' => $this->formatDate($run->created_at),
            'updated_at' => $this->formatDate($run->updated_at),
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
