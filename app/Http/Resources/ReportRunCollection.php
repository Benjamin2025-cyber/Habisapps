<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\ResourceCollection;

final class ReportRunCollection extends ResourceCollection
{
    public $collects = ReportRunResource::class;

    /**
     * @return array<string, mixed>
     */
    public function toArray($request): array
    {
        return [
            'report_runs' => $this->collection,
        ];
    }
}
