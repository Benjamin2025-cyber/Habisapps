<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\ClientKycReview;
use DateTimeInterface;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ClientKycReview
 */
final class ClientKycReviewResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var ClientKycReview $review */
        $review = $this->resource;

        return [
            'public_id' => $review->public_id,
            'client_public_id' => $review->relationLoaded('client') ? $review->client?->public_id : null,
            'agency_public_id' => $review->relationLoaded('agency') ? $review->agency?->public_id : null,
            'acted_by_public_id' => $review->relationLoaded('actedBy') ? $review->actedBy?->public_id : null,
            'previous_kyc_status' => $review->previous_kyc_status,
            'new_kyc_status' => $review->new_kyc_status,
            'reason' => $review->reason,
            'comment' => $review->comment,
            'created_at' => $this->formatDate($review->created_at),
            'updated_at' => $this->formatDate($review->updated_at),
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
