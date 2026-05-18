<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\CustomerAccountSignature;
use DateTimeInterface;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CustomerAccountSignature
 */
final class CustomerAccountSignatureResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var CustomerAccountSignature $signature */
        $signature = $this->resource;

        return [
            'public_id' => $signature->public_id,
            'agency_public_id' => $signature->relationLoaded('agency') ? $signature->agency?->public_id : null,
            'customer_account_public_id' => $signature->relationLoaded('customerAccount') ? $signature->customerAccount?->public_id : null,
            'client_public_id' => $signature->relationLoaded('client') ? $signature->client?->public_id : null,
            'document_public_id' => $signature->relationLoaded('document') ? $signature->document?->public_id : null,
            'client_proxy_public_id' => $signature->relationLoaded('clientProxy') ? $signature->clientProxy?->public_id : null,
            'signature_type' => $signature->signature_type,
            'signer_name' => $signature->signer_name,
            'signer_role' => $signature->signer_role,
            'status' => $signature->status,
            'captured_on' => $signature->captured_on?->toDateString(),
            'verified_at' => $this->formatDate($signature->verified_at),
            'verified_by_user_public_id' => $signature->relationLoaded('verifiedBy') ? $signature->verifiedBy?->public_id : null,
            'revoked_at' => $this->formatDate($signature->revoked_at),
            'revoked_by_user_public_id' => $signature->relationLoaded('revokedBy') ? $signature->revokedBy?->public_id : null,
            'revocation_reason' => $signature->revocation_reason,
            'metadata' => $signature->metadata,
            'created_at' => $this->formatDate($signature->created_at),
            'updated_at' => $this->formatDate($signature->updated_at),
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
