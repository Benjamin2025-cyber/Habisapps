<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\ClientIdentityDocument;
use DateTimeInterface;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ClientIdentityDocument
 */
final class ClientIdentityDocumentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $record = $this->resource;
        if (! $record instanceof ClientIdentityDocument) {
            return [];
        }

        $showPii = $request->user()?->can('crm.pii.view') === true;

        return [
            'public_id' => $record->public_id,
            'client_public_id' => $record->relationLoaded('client') ? $record->client?->public_id : null,
            'document_public_id' => $record->relationLoaded('document') ? $record->document?->public_id : null,
            'document_type' => $record->document_type,
            'document_number' => $showPii ? $record->document_number : $this->maskDocumentNumber($record->document_number),
            'issuing_authority' => $showPii ? $record->issuing_authority : null,
            'issued_on' => $this->formatDate($record->issued_on),
            'expires_on' => $this->formatDate($record->expires_on),
            'status' => $record->status,
            'verification_status' => $record->verification_status,
            'submitted_at' => $this->formatDate($record->submitted_at),
            'verified_at' => $this->formatDate($record->verified_at),
            'rejected_at' => $this->formatDate($record->rejected_at),
            'rejection_reason' => $record->rejection_reason,
            'archived_at' => $this->formatDate($record->archived_at),
            'created_at' => $this->formatDate($record->created_at),
            'updated_at' => $this->formatDate($record->updated_at),
        ];
    }

    private function maskDocumentNumber(string $value): string
    {
        $length = strlen($value);
        if ($length <= 4) {
            return str_repeat('*', $length);
        }

        return str_repeat('*', $length - 4).substr($value, -4);
    }

    private function formatDate(mixed $value): ?string
    {
        if (! $value instanceof DateTimeInterface) {
            return null;
        }

        return $value->format(DateTimeInterface::ATOM);
    }
}
