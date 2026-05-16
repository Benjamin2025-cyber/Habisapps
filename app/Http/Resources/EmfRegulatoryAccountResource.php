<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\EmfRegulatoryAccount;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin EmfRegulatoryAccount
 */
final class EmfRegulatoryAccountResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var EmfRegulatoryAccount $account */
        $account = $this->resource;

        return [
            'public_id' => $account->public_id,
            'parent_public_id' => $account->relationLoaded('parentAccount') ? $account->parentAccount?->public_id : null,
            'code' => $account->code,
            'name' => $account->name,
            'account_class' => $account->account_class,
            'status' => $account->status,
            'metadata' => $account->metadata,
            'created_at' => $account->created_at?->toAtomString(),
            'updated_at' => $account->updated_at?->toAtomString(),
        ];
    }
}
