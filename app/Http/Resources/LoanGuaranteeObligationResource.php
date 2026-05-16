<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\LoanGuaranteeObligation;
use DateTimeInterface;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin LoanGuaranteeObligation */
final class LoanGuaranteeObligationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $obligation = $this->resource;
        if (! $obligation instanceof LoanGuaranteeObligation) {
            return [];
        }

        return [
            'public_id' => $obligation->public_id,
            'agency_public_id' => $obligation->agency?->public_id,
            'loan_public_id' => $obligation->loan?->public_id,
            'client_guarantor_public_id' => $obligation->clientGuarantor?->public_id,
            'document_public_id' => $obligation->document?->public_id,
            'obligation_type' => $obligation->obligation_type,
            'obligation_amount_minor' => $obligation->obligation_amount_minor,
            'obligation_percentage' => $obligation->obligation_percentage,
            'currency' => $obligation->currency,
            'status' => $obligation->status,
            'starts_on' => $this->formatDate($obligation->starts_on),
            'ends_on' => $this->formatDate($obligation->ends_on),
            'release_condition' => $obligation->release_condition,
            'released_at' => $this->formatDate($obligation->released_at),
            'released_by_user_public_id' => $obligation->releasedBy?->public_id,
            'guarantor_identity_snapshot' => $obligation->guarantor_identity_snapshot,
            'created_at' => $this->formatDate($obligation->created_at),
            'updated_at' => $this->formatDate($obligation->updated_at),
        ];
    }

    private function formatDate(mixed $value): ?string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format(DateTimeInterface::ATOM);
        }

        return is_string($value) && $value !== '' ? $value : null;
    }
}
