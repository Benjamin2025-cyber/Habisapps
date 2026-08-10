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
 * A clôture annuelle: the transfer of solde 87 into 131 or 132.
 *
 * @property int $id
 * @property string $public_id
 * @property int $agency_id
 * @property int $fiscal_year
 * @property Carbon $opens_on
 * @property Carbon $closes_on
 * @property string $currency
 * @property int $net_result_minor
 * @property string $result_account_code
 * @property int|null $journal_entry_id
 * @property int|null $created_by_user_id
 */
#[Fillable([
    'public_id',
    'agency_id',
    'fiscal_year',
    'opens_on',
    'closes_on',
    'currency',
    'net_result_minor',
    'result_account_code',
    'journal_entry_id',
    'created_by_user_id',
])]
final class ExerciseClosing extends Model
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
            'opens_on' => 'date',
            'closes_on' => 'date',
            'fiscal_year' => 'integer',
            'net_result_minor' => 'integer',
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

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * The closing's state is the state of the entry that performs it, read rather
     * than stored. A duplicate status column would be one more thing to keep in
     * step, and the moment it drifted a closing would claim to have transferred a
     * result that is still sitting in a draft awaiting review.
     */
    public function status(): string
    {
        $entry = $this->journalEntry;

        return $entry instanceof JournalEntry ? $entry->status : 'pending';
    }

    /** True once the transfer has actually reached the ledger. */
    public function isPosted(): bool
    {
        return $this->status() === JournalEntry::STATUS_POSTED;
    }
}
