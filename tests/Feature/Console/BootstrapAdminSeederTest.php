<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\User;
use Database\Seeders\BootstrapAdminSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class BootstrapAdminSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_bootstrap_admin_seeder_creates_admin_from_env_values(): void
    {
        config([
            'security.bootstrap_admin.enabled' => true,
            'security.bootstrap_admin.email' => 'admin@example.com',
            'security.bootstrap_admin.phone' => '+237699100000',
            'security.bootstrap_admin.name' => 'Bootstrap Admin',
            'security.bootstrap_admin.password' => 'Password123!',
            'security.bootstrap_admin.role' => 'platform-admin',
        ]);

        self::assertSame(0, Artisan::call('db:seed', [
            '--class' => BootstrapAdminSeeder::class,
        ]));

        $user = User::query()->where('email', 'admin@example.com')->first();
        self::assertInstanceOf(User::class, $user);
        self::assertTrue(Hash::check('Password123!', (string) $user->password));
        self::assertSame('Bootstrap Admin', $user->name);
        self::assertSame('+237699100000', $user->phone_number);
        self::assertSame(User::STATUS_ACTIVE, $user->status);
        self::assertNotNull($user->phone_verified_at);
        self::assertNotNull($user->activated_at);
        self::assertTrue($user->hasRole('platform-admin'));
    }

    public function test_bootstrap_admin_seeder_leaves_existing_account_unchanged(): void
    {
        config([
            'security.bootstrap_admin.enabled' => true,
            'security.bootstrap_admin.email' => 'admin@example.com',
            'security.bootstrap_admin.phone' => '+237699100001',
            'security.bootstrap_admin.name' => 'Bootstrap Admin',
            'security.bootstrap_admin.password' => 'Password123!',
            'security.bootstrap_admin.role' => 'platform-admin',
        ]);

        self::assertSame(0, Artisan::call('db:seed', [
            '--class' => BootstrapAdminSeeder::class,
        ]));

        config([
            'security.bootstrap_admin.enabled' => true,
            'security.bootstrap_admin.email' => 'admin@example.com',
            'security.bootstrap_admin.phone' => '+237699100002',
            'security.bootstrap_admin.name' => 'Bootstrap Admin Updated',
            'security.bootstrap_admin.password' => 'Password123!',
            'security.bootstrap_admin.role' => 'platform-admin',
        ]);

        self::assertSame(0, Artisan::call('db:seed', [
            '--class' => BootstrapAdminSeeder::class,
        ]));

        self::assertSame(1, DB::table('users')->where('email', 'admin@example.com')->count());

        $user = User::query()->where('email', 'admin@example.com')->first();
        self::assertInstanceOf(User::class, $user);
        self::assertSame('Bootstrap Admin', $user->name);
        self::assertSame('+237699100001', $user->phone_number);
        self::assertTrue($user->hasRole('platform-admin'));
    }

    public function test_bootstrap_admin_seeder_noops_when_disabled(): void
    {
        config([
            'security.bootstrap_admin.enabled' => false,
            'security.bootstrap_admin.email' => 'admin@example.com',
            'security.bootstrap_admin.phone' => '+237699100000',
            'security.bootstrap_admin.name' => 'Bootstrap Admin',
            'security.bootstrap_admin.password' => 'Password123!',
            'security.bootstrap_admin.role' => 'platform-admin',
        ]);

        self::assertSame(0, Artisan::call('db:seed', [
            '--class' => BootstrapAdminSeeder::class,
        ]));

        self::assertSame(0, DB::table('users')->where('email', 'admin@example.com')->count());
    }
}
