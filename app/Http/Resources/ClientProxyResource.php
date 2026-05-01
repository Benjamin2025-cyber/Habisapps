<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\ClientProxy;
use App\Models\User;
use DateTimeInterface;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ClientProxy
 */
final class ClientProxyResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var ClientProxy $record */
        $record = $this->resource;

        $showPii = $this->canViewPii($request);

        return [
            'public_id' => $record->public_id,
            'client_public_id' => $record->relationLoaded('client') ? $record->client?->public_id : null,
            'document_public_id' => $record->relationLoaded('document') ? $record->document?->public_id : null,
            'proxy_full_name' => $showPii ? $record->proxy_full_name : $this->maskName($record->proxy_full_name),
            'proxy_phone_number' => $showPii ? $record->proxy_phone_number : $this->maskPhone($record->proxy_phone_number),
            'proxy_email' => $showPii ? $record->proxy_email : $this->maskEmail($record->proxy_email),
            'proxy_id_document_type' => $record->proxy_id_document_type,
            'proxy_id_document_number' => $showPii ? $record->proxy_id_document_number : $this->maskDocumentNumber($record->proxy_id_document_number),
            'mandate_type' => $record->mandate_type,
            'starts_on' => $this->formatDate($record->starts_on),
            'ends_on' => $this->formatDate($record->ends_on),
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

        return $user instanceof User && $user->hasPermissionTo('crm.pii.view');
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

    private function maskEmail(?string $value): ?string
    {
        if ($value === null || $value === '' || ! str_contains($value, '@')) {
            return $value;
        }

        [$local, $domain] = explode('@', $value, 2);
        if ($local === '') {
            return '*@'.$domain;
        }

        return substr($local, 0, 1).str_repeat('*', max(0, strlen($local) - 1)).'@'.$domain;
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

    private function formatDate(mixed $value): ?string
    {
        if (! $value instanceof DateTimeInterface) {
            return null;
        }

        return $value->format(DateTimeInterface::ATOM);
    }
}
