<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\ClientGuarantor;
use App\Models\User;
use DateTimeInterface;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ClientGuarantor
 */
final class ClientGuarantorResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var ClientGuarantor $record */
        $record = $this->resource;

        $showPii = $this->canViewPii($request);

        return [
            'public_id' => $record->public_id,
            'client_public_id' => $record->relationLoaded('client') ? $record->client?->public_id : null,
            'guarantor_client_public_id' => $record->relationLoaded('guarantorClient') ? $record->guarantorClient?->public_id : null,
            'document_type' => $record->document_type,
            'document_public_id' => $record->relationLoaded('document') ? $record->document?->public_id : null,
            'back_document_public_id' => $record->relationLoaded('backDocument') ? $record->backDocument?->public_id : null,
            'guarantor_full_name' => $showPii ? $record->guarantor_full_name : $this->maskName($record->guarantor_full_name),
            // Civility/title is a non-sensitive salutation; always visible.
            'guarantor_civility' => $record->guarantor_civility,
            'guarantor_first_name' => $showPii ? $record->guarantor_first_name : $this->maskName($record->guarantor_first_name),
            'guarantor_last_name' => $showPii ? $record->guarantor_last_name : $this->maskName($record->guarantor_last_name),
            'guarantor_middle_name' => $showPii ? $record->guarantor_middle_name : $this->maskName($record->guarantor_middle_name),
            'guarantor_father_name' => $showPii ? $record->guarantor_father_name : $this->maskName($record->guarantor_father_name),
            'guarantor_mother_name' => $showPii ? $record->guarantor_mother_name : $this->maskName($record->guarantor_mother_name),
            'guarantor_date_of_birth' => $showPii ? $this->formatDate($record->guarantor_date_of_birth) : null,
            'guarantor_place_of_birth' => $showPii ? $record->guarantor_place_of_birth : null,
            'guarantor_identity_document_number' => $showPii ? $record->guarantor_identity_document_number : $this->maskDocumentNumber($record->guarantor_identity_document_number),
            'guarantor_identity_issued_on' => $showPii ? $this->formatDate($record->guarantor_identity_issued_on) : null,
            'guarantor_identity_issued_at' => $showPii ? $record->guarantor_identity_issued_at : null,
            'guarantor_profession' => $showPii ? $record->guarantor_profession : null,
            'guarantor_address_line_1' => $showPii ? $record->guarantor_address_line_1 : null,
            'guarantor_address_line_2' => $showPii ? $record->guarantor_address_line_2 : null,
            'guarantor_business_address_line_1' => $showPii ? $record->guarantor_business_address_line_1 : null,
            'guarantor_business_address_line_2' => $showPii ? $record->guarantor_business_address_line_2 : null,
            'guarantor_phone_number' => $showPii ? $record->guarantor_phone_number : $this->maskPhone($record->guarantor_phone_number),
            'relationship_type' => $record->relationship_type,
            'status' => $record->status,
            'verification_status' => $record->verification_status,
            'starts_on' => $this->formatDate($record->starts_on),
            'ends_on' => $this->formatDate($record->ends_on),
            'submitted_at' => $this->formatDate($record->submitted_at),
            'verified_at' => $this->formatDate($record->verified_at),
            'rejected_at' => $this->formatDate($record->rejected_at),
            'rejection_reason' => $record->rejection_reason,
            'archived_at' => $this->formatDate($record->archived_at),
            'created_at' => $this->formatDate($record->created_at),
            'updated_at' => $this->formatDate($record->updated_at),
        ];
    }

    private function maskName(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return $value;
        }

        return substr($value, 0, 1).str_repeat('*', max(0, strlen($value) - 1));
    }

    private function canViewPii(Request $request): bool
    {
        $user = $request->user();

        return $user instanceof User
            && ($user->hasPermissionTo('crm.guarantors.pii.view') || $user->hasPermissionTo('crm.pii.view'));
    }

    private function maskDocumentNumber(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return $value;
        }

        $length = strlen($value);
        if ($length <= 4) {
            return str_repeat('*', $length);
        }

        return str_repeat('*', $length - 4).substr($value, -4);
    }

    private function maskPhone(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return $value;
        }

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
