<?php

declare(strict_types=1);

namespace App\Http\Resources;

use DateTimeInterface;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;
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
            'properties' => $this->safeProperties($activity->properties),
            'created_at' => $this->formatDate($activity->created_at),
        ];
    }

    /**
     * @return array<array-key, mixed>|null
     */
    private function safeProperties(mixed $properties): ?array
    {
        if ($properties instanceof Collection) {
            $properties = $properties->all();
        }

        if (! is_array($properties)) {
            return null;
        }

        return $this->sanitizeArray($properties);
    }

    /**
     * Strips sensitive string-keyed entries while preserving list values
     * (integer keys), so readable metadata such as `changed_fields` survives
     * serialization. Sensitivity is a property of named keys, so integer keys
     * are kept and arrays are sanitized recursively.
     *
     * @param  array<mixed>  $values
     * @return array<array-key, mixed>
     */
    private function sanitizeArray(array $values): array
    {
        $safe = [];

        foreach ($values as $key => $value) {
            if (is_string($key) && $this->isSensitivePropertyKey($key)) {
                continue;
            }

            $safe[$key] = is_array($value)
                ? $this->sanitizeArray($value)
                : $value;
        }

        return $safe;
    }

    private function isSensitivePropertyKey(string $key): bool
    {
        $normalized = strtolower($key);

        if ($normalized === 'id' || (str_ends_with($normalized, '_id') && $normalized !== 'public_id' && ! str_ends_with($normalized, '_public_id'))) {
            return true;
        }

        foreach (['password', 'token', 'otp', 'secret', 'authorization', 'phone'] as $sensitiveFragment) {
            if (str_contains($normalized, $sensitiveFragment)) {
                return true;
            }
        }

        return false;
    }

    private function formatDate(mixed $value): ?string
    {
        if (! $value instanceof DateTimeInterface) {
            return null;
        }

        return $value->format(DateTimeInterface::ATOM);
    }
}
