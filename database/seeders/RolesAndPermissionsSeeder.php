<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

final class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        /** @var array<string, array<int, string>> $roles */
        $roles = config('security.permissions.roles', []);
        $tableNames = config('permission.table_names');
        $guardName = 'web';
        $now = now();

        if (! is_array($tableNames) || ! is_string($guardName)) {
            return;
        }

        $rolesTable = (string) ($tableNames['roles'] ?? 'roles');
        $permissionsTable = (string) ($tableNames['permissions'] ?? 'permissions');
        $pivotTable = (string) ($tableNames['role_has_permissions'] ?? 'role_has_permissions');

        $permissionNames = [];
        foreach ($roles as $permissions) {
            foreach ($permissions as $permissionName) {
                $permissionNames[$permissionName] = true;
            }
        }

        if ($permissionNames !== []) {
            DB::table($permissionsTable)->upsert(
                array_map(static fn (string $name): array => [
                    'name' => $name,
                    'guard_name' => $guardName,
                    'created_at' => $now,
                    'updated_at' => $now,
                ], array_keys($permissionNames)),
                ['name', 'guard_name'],
                ['updated_at']
            );
        }

        if ($roles !== []) {
            DB::table($rolesTable)->upsert(
                array_map(static fn (string $name): array => [
                    'name' => $name,
                    'guard_name' => $guardName,
                    'created_at' => $now,
                    'updated_at' => $now,
                ], array_keys($roles)),
                ['name', 'guard_name'],
                ['updated_at']
            );
        }

        /** @var array<string, int> $permissionIds */
        $permissionIds = DB::table($permissionsTable)
            ->where('guard_name', $guardName)
            ->whereIn('name', array_keys($permissionNames))
            ->pluck('id', 'name')
            ->all();

        /** @var array<string, int> $roleIds */
        $roleIds = DB::table($rolesTable)
            ->where('guard_name', $guardName)
            ->whereIn('name', array_keys($roles))
            ->pluck('id', 'name')
            ->all();

        $pivotRows = [];
        foreach ($roles as $roleName => $permissions) {
            $roleId = $roleIds[$roleName] ?? null;
            if (! is_int($roleId)) {
                continue;
            }

            foreach ($permissions as $permissionName) {
                $permissionId = $permissionIds[$permissionName] ?? null;
                if (is_int($permissionId)) {
                    $pivotRows[] = [
                        'role_id' => $roleId,
                        'permission_id' => $permissionId,
                    ];
                }
            }
        }

        if ($pivotRows !== []) {
            DB::table($pivotTable)->insertOrIgnore($pivotRows);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
