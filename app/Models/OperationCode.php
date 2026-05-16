<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Traits\HasAuditLog;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'public_id',
    'code',
    'label',
    'module',
    'operation_type',
    'direction',
    'status',
    'metadata',
])]
final class OperationCode extends Model
{
    use HasAuditLog, HasUlids;

    public const string STATUS_ACTIVE = 'active';

    public const string STATUS_INACTIVE = 'inactive';

    public const string STATUS_ARCHIVED = 'archived';

    public const array MODULES = [
        'accounting',
        'cash',
        'loan',
        'insurance',
        'hr',
        'fx',
        'islamic_finance',
        'sms',
        'reporting',
        'alert',
    ];

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
            'metadata' => 'array',
        ];
    }

    /** @return HasMany<OperationAccountMapping, $this> */
    public function accountMappings(): HasMany
    {
        return $this->hasMany(OperationAccountMapping::class);
    }
}
