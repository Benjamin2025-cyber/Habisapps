<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'public_id',
    'report_definition_id',
    'agency_id',
    'period_starts_on',
    'period_ends_on',
    'status',
    'generated_at',
    'generated_by_user_id',
    'document_id',
    'parameters',
    'summary',
    'source_version_snapshot',
])]
final class ReportRun extends Model
{
    use HasUlids;

    public const string STATUS_COMPLETED = 'completed';

    public const string STATUS_FAILED = 'failed';

    /**
     * @return array<int, string>
     */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    protected function casts(): array
    {
        return [
            'period_starts_on' => 'date',
            'period_ends_on' => 'date',
            'generated_at' => 'datetime',
            'parameters' => 'array',
            'summary' => 'array',
            'source_version_snapshot' => 'array',
        ];
    }

    /** @return BelongsTo<ReportDefinition, $this> */
    public function reportDefinition(): BelongsTo
    {
        return $this->belongsTo(ReportDefinition::class);
    }

    /** @return BelongsTo<Agency, $this> */
    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }

    /** @return BelongsTo<Document, $this> */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }
}
