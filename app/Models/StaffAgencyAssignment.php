<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * @property int $id
 * @property int $user_id
 * @property int $agency_id
 * @property string $role_at_agency
 * @property Carbon $starts_on
 * @property Carbon|null $ends_on
 * @property bool $is_primary
 * @property string $status
 */
#[Fillable([
    'public_id',
    'user_id',
    'agency_id',
    'role_at_agency',
    'starts_on',
    'ends_on',
    'is_primary',
    'status',
])]
final class StaffAgencyAssignment extends Model
{
    use HasUlids;

    public const string STATUS_ACTIVE = 'active';

    public const string STATUS_ENDED = 'ended';

    /**
     * Point a user's single open primary assignment at an agency, creating it on
     * first call and updating it afterwards.
     *
     * `staff_primary_assignment_no_overlap` excludes two active primary
     * assignments whose date ranges overlap for the same user, and an assignment
     * with no `ends_on` runs to infinity. So there is at most one open primary
     * assignment per user, and the idempotent operation is to move it rather than
     * to add another.
     *
     * Keyed on the user, deliberately not on (user, agency, starts_on): keying on
     * a date that moves means a second run on a later day matches nothing, tries
     * to insert another open-ended row, and is refused by the exclusion
     * constraint. That is a seeder that works the day it is written and fails
     * every deployment after it.
     *
     * `starts_on` is left alone when the assignment already exists — the staff
     * member has been there since the original date, not since the last deploy.
     */
    public static function assignPrimary(int $userId, int $agencyId, string $roleAtAgency): self
    {
        return DB::transaction(function () use ($userId, $agencyId, $roleAtAgency): self {
            // `where(..., null)` rather than whereNull: the latter is a forwarded
            // builder method larastan reports as a dynamic static call, and both
            // compile to IS NULL.
            $open = self::query()
                ->where('user_id', $userId)
                ->where('is_primary', true)
                ->where('status', self::STATUS_ACTIVE)
                ->where('ends_on', null)
                ->first();

            // Already where it should be: only the role can have moved.
            if ($open instanceof self && $open->agency_id === $agencyId) {
                $open->forceFill(['role_at_agency' => $roleAtAgency])->save();

                return $open;
            }

            // Close the open one before opening another, or
            // staff_primary_assignment_no_overlap refuses two active primary
            // assignments covering the same day.
            if ($open instanceof self) {
                $open->forceFill([
                    'ends_on' => now()->toDateString(),
                    'status' => self::STATUS_ENDED,
                ])->save();
            }

            // Reuse this user's row for this agency if one exists rather than
            // repointing the open assignment onto it. uniq_staff_agency_start is on
            // (user_id, agency_id, starts_on), so a staff member who has already
            // served at this agency has a row occupying that key -- moving another
            // row onto it collides, which is what broke db:seed.
            $atAgency = self::query()
                ->where('user_id', $userId)
                ->where('agency_id', $agencyId)
                ->oldest('starts_on')
                ->first();

            if ($atAgency instanceof self) {
                $atAgency->forceFill([
                    'role_at_agency' => $roleAtAgency,
                    'ends_on' => null,
                    'is_primary' => true,
                    'status' => self::STATUS_ACTIVE,
                ])->save();

                return $atAgency;
            }

            return self::query()->create([
                'user_id' => $userId,
                'agency_id' => $agencyId,
                'role_at_agency' => $roleAtAgency,
                'starts_on' => now()->toDateString(),
                'ends_on' => null,
                'is_primary' => true,
                'status' => self::STATUS_ACTIVE,
            ]);
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
            'is_primary' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Agency, $this>
     */
    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }

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
     * @return Attribute<string, string>
     */
    protected function roleAtAgency(): Attribute
    {
        return Attribute::make(
            set: static fn (string $value): string => str($value)->lower()->kebab()->toString(),
        );
    }
}
