<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin object
 */
final class UserNotificationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $row = (object) $this->resource;
        $metadata = $this->decodeMetadata($row->metadata ?? null);

        return [
            'public_id' => (string) ($row->public_id ?? ''),
            'type' => (string) ($row->type ?? ''),
            'category' => (string) ($row->category ?? ''),
            'title' => (string) ($row->title ?? ''),
            'message' => (string) ($row->message ?? ''),
            'action_url' => isset($row->action_url) && is_string($row->action_url) ? $row->action_url : null,
            'agency_public_id' => isset($row->agency_public_id) && is_string($row->agency_public_id) ? $row->agency_public_id : null,
            'created_at' => $this->dateString($row->created_at ?? null),
            'read_at' => $this->dateString($row->actor_read_at ?? null),
            'metadata' => $metadata,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeMetadata(mixed $metadata): array
    {
        if (! is_string($metadata) || $metadata === '') {
            return [];
        }

        $decoded = json_decode($metadata, true);
        if (! is_array($decoded)) {
            return [];
        }

        $result = [];
        foreach ($decoded as $key => $value) {
            if (is_string($key)) {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    private function dateString(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_string($value)) {
            return CarbonImmutable::parse($value)->toAtomString();
        }

        return null;
    }
}
