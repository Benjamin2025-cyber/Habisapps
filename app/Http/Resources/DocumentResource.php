<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Document;
use DateTimeInterface;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Document
 */
final class DocumentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Document $document */
        $document = $this->resource;

        return [
            'public_id' => $document->public_id,
            'category' => $document->category,
            'title' => $document->title,
            'original_name' => $document->original_name,
            'mime_type' => $document->mime_type,
            'size_bytes' => $document->size_bytes,
            'checksum_sha256' => $document->checksum_sha256,
            'status' => $document->status,
            'metadata' => $document->metadata,
            'verified_at' => $this->formatDate($document->verified_at),
            'archived_at' => $this->formatDate($document->archived_at),
            'created_at' => $this->formatDate($document->created_at),
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
