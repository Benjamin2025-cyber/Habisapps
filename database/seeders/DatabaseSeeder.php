<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use InvalidArgumentException;

final class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(RolesAndPermissionsSeeder::class);

        if (! app()->environment(['local', 'testing'])) {
            return;
        }

        if (! (bool) env('SEED_BOOTSTRAP_ADMIN', false)) {
            return;
        }

        $password = (string) env('SEED_BOOTSTRAP_ADMIN_PASSWORD');

        if ($password === '') {
            throw new InvalidArgumentException('SEED_BOOTSTRAP_ADMIN_PASSWORD is required when SEED_BOOTSTRAP_ADMIN is enabled.');
        }

        $user = User::firstOrCreate(
            ['email' => (string) env('SEED_BOOTSTRAP_ADMIN_EMAIL', 'test@example.com')],
            [
                'name' => (string) env('SEED_BOOTSTRAP_ADMIN_NAME', 'Bootstrap Admin'),
                'phone_number' => (string) env('SEED_BOOTSTRAP_ADMIN_PHONE', '+237600000000'),
                'phone_verified_at' => now(),
                'password' => $password,
                'status' => User::STATUS_ACTIVE,
                'activated_at' => now(),
            ]
        );

        if (! $user->hasRole('platform-admin')) {
            $user->assignRole('platform-admin');
        }
    }
}
