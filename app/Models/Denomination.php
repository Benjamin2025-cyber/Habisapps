<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Traits\HasAuditLog;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $public_id
 * @property string $code
 * @property string $label
 * @property int $value_minor
 * @property string $currency
 * @property string $type
 * @property string $status
 */
#[Fillable(['public_id', 'code', 'label', 'value_minor', 'currency', 'type', 'status'])]
final class Denomination extends Model
{
    use HasAuditLog;
    use HasUlids;

    public const string STATUS_ACTIVE = 'active';

    public const string STATUS_INACTIVE = 'inactive';

    public const string TYPE_BANKNOTE = 'banknote';

    public const string TYPE_COIN = 'coin';

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
}
