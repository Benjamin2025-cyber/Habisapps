<?php

declare(strict_types=1);

namespace App\Application\Authorization;

use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

final class SyncRolePermissions
{
    /**
     * @param  array<int, string>  $permissions
     */
    public function execute(Role $role, array $permissions): void
    {
        DB::transaction(function () use ($role, $permissions): void {
            $role->syncPermissions($permissions);
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        });
    }
}
