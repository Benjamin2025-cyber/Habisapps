<?php

declare(strict_types=1);

namespace App\Http\Resources;

use DateTimeInterface;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Spatie\Activitylog\Models\Activity;

/**
 * @mixin Activity
 */
final class AuditEventResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $activity = $this->resource;

        if (! $activity instanceof Activity) {
            return [
                'log_name' => null,
                'event' => null,
                'description' => null,
                'subject_type' => null,
                'causer_type' => null,
                'properties' => null,
                'created_at' => null,
            ];
        }

        return [
            'log_name' => $activity->log_name,
            'event' => $activity->event,
            'description' => $activity->description,
            'subject_type' => $activity->subject_type,
            'causer_type' => $activity->causer_type,
            'properties' => $activity->properties,
            'created_at' => $this->formatDate($activity->created_at),
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
