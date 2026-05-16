<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string|null $gender
 * @property Carbon|null $birth_date
 * @property string|null $birth_place
 * @property string|null $job_title
 * @property string|null $service_name
 * @property string|null $portfolio_code
 */
#[Fillable([
    'public_id',
    'user_id',
    'agency_id',
    'supervisor_id',
    'employee_number',
    'first_name',
    'last_name',
    'gender',
    'birth_date',
    'birth_place',
    'phone_number',
    'email',
    'job_title',
    'service_name',
    'portfolio_code',
    'status',
    'metadata',
])]
final class HrEmployee extends Model
{
    use HasUlids;

    public const string STATUS_ACTIVE = 'active';

    /**
     * @return array<int, string>
     */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'birth_date' => 'date',
            'metadata' => 'array',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Agency, $this> */
    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }

    /** @return BelongsTo<User, $this> */
    public function supervisor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'supervisor_id');
    }
}
