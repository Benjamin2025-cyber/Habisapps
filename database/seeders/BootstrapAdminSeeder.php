<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Spatie\Permission\Models\Role;

final class BootstrapAdminSeeder extends Seeder
{
    public function run(): void
    {
        if (! (bool) config('security.bootstrap_admin.enabled', false)) {
            return;
        }

        $email = $this->stringConfig('security.bootstrap_admin.email');
        $phone = $this->stringConfig('security.bootstrap_admin.phone');
        $name = $this->stringConfig('security.bootstrap_admin.name');
        $password = $this->stringConfig('security.bootstrap_admin.password');
        $roleName = $this->stringConfig('security.bootstrap_admin.role', 'platform-admin');

        if ($email === '') {
            throw new InvalidArgumentException('SEED_BOOTSTRAP_ADMIN_EMAIL is required when SEED_BOOTSTRAP_ADMIN is enabled.');
        }
        if ($phone === '') {
            throw new InvalidArgumentException('SEED_BOOTSTRAP_ADMIN_PHONE is required when SEED_BOOTSTRAP_ADMIN is enabled.');
        }
        if ($name === '') {
            throw new InvalidArgumentException('SEED_BOOTSTRAP_ADMIN_NAME is required when SEED_BOOTSTRAP_ADMIN is enabled.');
        }
        if ($password === '') {
            throw new InvalidArgumentException('SEED_BOOTSTRAP_ADMIN_PASSWORD is required when SEED_BOOTSTRAP_ADMIN is enabled.');
        }

        $role = Role::query()->where('name', $roleName)->first();
        if (! $role instanceof Role) {
            throw new InvalidArgumentException("Role [{$roleName}] does not exist. Seed roles before bootstrapping the admin account.");
        }

        /** @var User $user */
        $user = DB::transaction(function () use ($email, $phone, $name, $password, $roleName): User {
            $user = User::query()->firstOrCreate(
                ['email' => $email],
                [
                    'name' => $name,
                    'phone_number' => $phone,
                    'password' => $password,
                    'status' => User::STATUS_ACTIVE,
                    'phone_verified_at' => now(),
                    'activated_at' => now(),
                ]
            );

            if (! $user->hasRole($roleName)) {
                $user->assignRole($roleName);
            }

            return $user->refresh();
        });

        $this->command->info(sprintf(
            'Bootstrap admin account ready: %s (%s)',
            $user->email,
            $user->public_id
        ));
    }

    private function stringConfig(string $key, string $default = ''): string
    {
        $value = config($key, $default);

        return is_string($value) ? $value : $default;
    }
}
