<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $till_reconciliation_id
 * @property int $denomination_id
 * @property int $count
 * @property int $declared_amount_minor
 */
#[Fillable([
    'till_reconciliation_id',
    'denomination_id',
    'count',
    'declared_amount_minor',
])]
final class TillReconciliationLine extends Model
{
    /** @return BelongsTo<TillReconciliation, $this> */
    public function tillReconciliation(): BelongsTo
    {
        return $this->belongsTo(TillReconciliation::class);
    }

    /** @return BelongsTo<Denomination, $this> */
    public function denomination(): BelongsTo
    {
        return $this->belongsTo(Denomination::class);
    }
}
