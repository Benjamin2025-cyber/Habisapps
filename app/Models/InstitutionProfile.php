<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Traits\HasAuditLog;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * The institution — one legal entity owning every agency.
 *
 * A singleton: an EMF is a single legal entity, so there is exactly one row and
 * nothing references it by foreign key. Institution *scope* remains encoded as
 * `agency_id IS NULL`; this model holds institution *identity*.
 *
 * @property int $id
 * @property string $public_id
 * @property string|null $legal_name
 * @property string|null $trade_name
 * @property string|null $legal_form
 * @property string|null $emf_category
 * @property string|null $supervisory_authority
 * @property string|null $approval_number
 * @property \Illuminate\Support\Carbon|null $approval_date
 * @property string|null $registration_number
 * @property string|null $tax_identification_number
 * @property string|null $address_line_1
 * @property string|null $address_line_2
 * @property string|null $po_box
 * @property string|null $city
 * @property string|null $region
 * @property string|null $country
 * @property string|null $phone_number
 * @property string|null $fax_number
 * @property string|null $email
 * @property string|null $website
 * @property string|null $declared_reporting_currency
 * @property int|null $fiscal_year_start_month
 */
#[Fillable([
    'id',
    'public_id',
    'legal_name',
    'trade_name',
    'legal_form',
    'emf_category',
    'supervisory_authority',
    'approval_number',
    'approval_date',
    'registration_number',
    'tax_identification_number',
    'address_line_1',
    'address_line_2',
    'po_box',
    'city',
    'region',
    'country',
    'phone_number',
    'fax_number',
    'email',
    'website',
    'declared_reporting_currency',
    'fiscal_year_start_month',
])]
final class InstitutionProfile extends Model
{
    use HasAuditLog;
    use HasUlids;

    public const SINGLETON_ID = 1;

    protected $table = 'institution_profile';

    public $incrementing = false;

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
     * The profile as recorded, or null when the institution has not been
     * configured yet. Read paths use this so that generating a report never
     * writes.
     */
    public static function current(): ?self
    {
        return self::query()->whereKey(self::SINGLETON_ID)->first();
    }

    /**
     * The profile, created empty on first use. Write paths use this so the
     * endpoint works on a fresh install before the seeder has run.
     */
    public static function singleton(): self
    {
        $profile = self::current();
        if ($profile instanceof self) {
            return $profile;
        }

        return self::query()->create([
            'id' => self::SINGLETON_ID,
            'public_id' => (string) Str::ulid(),
        ]);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'approval_date' => 'date',
            'fiscal_year_start_month' => 'integer',
        ];
    }
}
