<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * @property int $batch_procedure_id
 * @property int $operation_code_id
 * @property string $status
 */
#[Fillable([
    'batch_procedure_id',
    'operation_code_id',
    'status',
])]
final class BatchProcedureOperationCode extends Pivot
{
    public const string STATUS_ACTIVE = 'active';

    public const string STATUS_INACTIVE = 'inactive';

    protected $table = 'batch_procedure_operation_codes';

    public $incrementing = true;

    /** @return BelongsTo<BatchProcedure, $this> */
    public function batchProcedure(): BelongsTo
    {
        return $this->belongsTo(BatchProcedure::class);
    }

    /** @return BelongsTo<OperationCode, $this> */
    public function operationCode(): BelongsTo
    {
        return $this->belongsTo(OperationCode::class);
    }
}
