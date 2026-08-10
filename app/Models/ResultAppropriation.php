<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Traits\HasAuditLog;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An affectation du résultat: the AG's decision, and the entry that carried it out.
 *
 * @property int $id
 * @property string $public_id
 * @property int $agency_id
 * @property int $fiscal_year
 * @property string $currency
 * @property string $source_account_code
 * @property int $amount_minor
 * @property Carbon $decided_on
 * @property int|null $journal_entry_id
 * @property int|null $created_by_user_id
 */
#[Fillable([
    'public_id',
    'agency_id',
    'fiscal_year',
    'currency',
    'source_account_code',
    'amount_minor',
    'decided_on',
    'journal_entry_id',
    'created_by_user_id',
])]
final class ResultAppropriation extends Model
{
    use HasAuditLog;
    use HasUlids;

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

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'decided_on' => 'date',
            'fiscal_year' => 'integer',
            'amount_minor' => 'integer',
        ];
    }

    /** @return BelongsTo<Agency, $this> */
    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }

    /** @return BelongsTo<JournalEntry, $this> */
    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    /**
     * Read from the entry rather than stored, for the same reason as a clôture:
     * an allocation whose entry is still in review has moved nothing, and a
     * duplicated status would eventually say otherwise.
     */
    public function status(): string
    {
        $entry = $this->journalEntry;

        return $entry instanceof JournalEntry ? $entry->status : 'pending';
    }

    public function isPosted(): bool
    {
        return $this->status() === JournalEntry::STATUS_POSTED;
    }
}
