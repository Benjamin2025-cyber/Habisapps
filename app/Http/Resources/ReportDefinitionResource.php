<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\ReportDefinition;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ReportDefinition
 */
final class ReportDefinitionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $effectiveFrom = $this->effective_from;
        $effectiveTo = $this->effective_to;
        /** @var array<string, mixed>|null $definition */
        $definition = $this->definition;

        return [
            'public_id' => $this->public_id,
            'code' => $this->code,
            'name' => $this->name,
            'report_type' => $this->report_type,
            'module' => $this->module,
            'status' => $this->status,
            'version' => $this->version,
            'effective_from' => is_string($effectiveFrom) ? $effectiveFrom : null,
            'effective_to' => is_string($effectiveTo) ? $effectiveTo : null,
            'supported_parameters' => is_array($definition) ? ($definition['supported_parameters'] ?? ['agency', 'currency']) : ['agency', 'currency'],
            'requires_agency' => is_array($definition) ? ($definition['requires_agency'] ?? true) : true,
            'requires_currency' => is_array($definition) ? ($definition['requires_currency'] ?? true) : true,
            'requires_period' => is_array($definition) ? ($definition['requires_period'] ?? false) : false,
            'description' => is_array($definition) ? ($definition['description'] ?? null) : null,
        ];
    }
}
