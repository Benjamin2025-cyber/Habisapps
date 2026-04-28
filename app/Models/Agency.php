<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $public_id
 * @property string $code
 * @property string $name
 * @property string|null $region
 * @property string|null $city
 * @property string|null $branch_name
 * @property string|null $phone_number
 * @property string|null $email
 * @property string|null $address_line_1
 * @property string|null $address_line_2
 * @property string|null $creation_date
 * @property string $status
 */
#[Fillable([
    'public_id',
    'code',
    'name',
    'region',
    'city',
    'branch_name',
    'phone_number',
    'email',
    'address_line_1',
    'address_line_2',
    'creation_date',
    'status',
    'manager_id',
])]
final class Agency extends Model
{
    use HasUlids;

    public const string STATUS_ACTIVE = 'active';

    public const string STATUS_INACTIVE = 'inactive';

    /**
     * @return array<int, string>
     */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }
}
