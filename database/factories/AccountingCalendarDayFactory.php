<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\AccountingCalendarDay;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<AccountingCalendarDay>
 */
class AccountingCalendarDayFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'public_id' => (string) Str::ulid(),
            'scope_type' => AccountingCalendarDay::SCOPE_AGENCY,
            'agency_id' => null,
            'calendar_date' => now()->toDateString(),
            'business_date' => now()->toDateString(),
            'is_business_day' => true,
            'is_holiday' => false,
            'holiday_name' => null,
            'notes' => null,
        ];
    }

    public function holiday(?string $name = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_business_day' => false,
            'is_holiday' => true,
            'business_date' => null,
            'holiday_name' => $name ?? 'Public Holiday',
        ]);
    }

    public function institution(): static
    {
        return $this->state(fn (array $attributes): array => [
            'scope_type' => AccountingCalendarDay::SCOPE_INSTITUTION,
            'agency_id' => null,
        ]);
    }
}
