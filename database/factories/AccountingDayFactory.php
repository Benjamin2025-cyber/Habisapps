<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\AccountingDay;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<AccountingDay>
 */
class AccountingDayFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'public_id' => (string) Str::ulid(),
            'scope_type' => AccountingDay::SCOPE_AGENCY,
            'agency_id' => null,
            'business_date' => now()->toDateString(),
            'calendar_opened_at' => now(),
            'calendar_closed_at' => null,
            'status' => AccountingDay::STATUS_OPEN,
            'is_holiday' => false,
            'holiday_name' => null,
            'origin' => AccountingDay::ORIGIN_MANUAL,
            'write_lock_version' => 0,
        ];
    }

    public function institution(): static
    {
        return $this->state(fn (array $attributes): array => [
            'scope_type' => AccountingDay::SCOPE_INSTITUTION,
            'agency_id' => null,
        ]);
    }

    public function closed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => AccountingDay::STATUS_CLOSED,
            'calendar_closed_at' => now(),
        ]);
    }

    public function closing(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => AccountingDay::STATUS_CLOSING,
        ]);
    }
}
