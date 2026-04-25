<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

final class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        /** @var array<string, array<int, string>> $roles */
        $roles = config('security.permissions.roles', []);

        foreach ($roles as $roleName => $permissions) {
            $role = Role::findOrCreate($roleName, 'web');

            foreach ($permissions as $permissionName) {
                $permission = Permission::findOrCreate($permissionName, 'web');
                $role->givePermissionTo($permission);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
