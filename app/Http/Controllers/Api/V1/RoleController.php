<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\BaseController;
use App\Support\Security\SecurityAudit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

final class RoleController extends BaseController
{
    public function __construct(private readonly SecurityAudit $securityAudit) {}

    public function index(Request $request): JsonResponse
    {
        if ($request->user()?->can('roles.view') !== true && $request->user()?->can('roles.manage') !== true) {
            return $this->respondForbidden();
        }

        return $this->respondSuccess([
            'roles' => $this->roleCatalog(),
            'permissions' => $this->permissionCatalog(),
        ]);
    }

    public function updatePermissions(Request $request, string $role): JsonResponse
    {
        if ($request->user()?->can('roles.manage') !== true) {
            return $this->respondForbidden();
        }

        $permissionNames = $this->permissionNames();

        $validated = Validator::make($request->all(), [
            'permissions' => ['required', 'array', 'min:1'],
            'permissions.*' => ['required', 'string', Rule::in($permissionNames)],
        ])->validate();

        $roleModel = Role::query()->where('name', $role)->first();
        if ($roleModel === null) {
            return $this->respondNotFound();
        }

        $permissions = $validated['permissions'];

        if ($role !== 'platform-admin') {
            $protectedPermissions = $this->protectedPermissions();
            $violations = array_values(array_intersect($permissions, $protectedPermissions));
            if ($violations !== []) {
                return $this->respondUnprocessable('Protected permissions can only be granted to platform administrators.');
            }
        }

        if ($role === 'platform-admin') {
            $minimum = $this->minimumPlatformPermissions();
            $missing = array_diff($minimum, $permissions);
            if ($missing !== []) {
                return $this->respondUnprocessable('Platform administrator must retain the minimum administration permissions.');
            }
        }

        DB::transaction(function () use ($roleModel, $permissions): void {
            $roleModel->syncPermissions($permissions);
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        });

        $roleModel->load('permissions');

        $this->securityAudit->record('role.permissions_changed', actor: $request->user(), properties: [
            'role' => $role,
            'permissions' => array_values($permissions),
        ], request: $request);

        return $this->respondSuccess([
            'role' => [
                'name' => $roleModel->name,
                'guard_name' => $roleModel->guard_name,
                'permissions' => $roleModel->permissions->pluck('name')->values()->all(),
            ],
        ], 'Role permissions updated successfully');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function roleCatalog(): array
    {
        $roleDefinitions = $this->configuredRoleDefinitions();
        $roles = [];

        foreach ($roleDefinitions as $name => $permissions) {
            $roles[] = [
                'name' => $name,
                'display_name' => str($name)->replace('-', ' ')->title()->toString(),
                'description' => $this->roleDescription($name),
                'assignable' => $name !== 'platform-admin',
                'permissions' => array_values($permissions),
            ];
        }

        return $roles;
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function permissionCatalog(): array
    {
        $permissions = [];

        foreach ($this->configuredRoleDefinitions() as $permissionList) {
            foreach ($permissionList as $permission) {
                $module = explode('.', $permission, 2)[0];
                $permissions[$module] ??= [];
                if (! in_array($permission, $permissions[$module], true)) {
                    $permissions[$module][] = $permission;
                }
            }
        }

        ksort($permissions);

        foreach ($permissions as &$group) {
            sort($group);
        }

        return $permissions;
    }

    /**
     * @return array<int, string>
     */
    private function permissionNames(): array
    {
        $names = [];

        foreach ($this->permissionCatalog() as $group) {
            foreach ($group as $permission) {
                $names[] = $permission;
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function configuredRoleDefinitions(): array
    {
        $configured = config('security.permissions.roles', []);
        if (! is_array($configured)) {
            return [];
        }

        $definitions = [];
        foreach ($configured as $roleName => $permissions) {
            if (! is_string($roleName) || ! is_array($permissions)) {
                continue;
            }

            $normalized = [];
            foreach ($permissions as $permission) {
                if (is_string($permission) && $permission !== '') {
                    $normalized[] = $permission;
                }
            }

            $definitions[$roleName] = array_values(array_unique($normalized));
        }

        return $definitions;
    }

    /**
     * @return array<int, string>
     */
    private function minimumPlatformPermissions(): array
    {
        return [
            'system.view-health',
            'audit.view',
            'agencies.manage',
            'users.view',
            'users.create',
            'users.manage',
            'users.update',
            'users.status.manage',
            'roles.manage',
            'users.roles.manage',
            'staff.assignments.manage',
            'batch.procedures.manage',
            'batch.runs.manage',
            'documents.view',
            'documents.create',
            'documents.archive',
            'references.reserve',
            'crm.scope.institution.read',
            'crm.scope.institution.review',
            'crm.scope.institution.manage',
            'crm.pii.view',
            'crm.reviews.view',
            'crm.kyc.override.expired_identity',
            'crm.kyc.override.self_verify',
            'crm.clients.view',
            'crm.clients.create',
            'crm.clients.update',
            'crm.clients.archive',
            'crm.kyc.submit',
            'crm.kyc.review',
            'crm.kyc.verify',
            'crm.kyc.reject',
            'crm.identity_documents.view',
            'crm.identity_documents.create',
            'crm.identity_documents.update',
            'crm.identity_documents.archive',
            'crm.identity_documents.verify',
            'crm.identity_documents.reject',
            'crm.guarantors.view',
            'crm.guarantors.create',
            'crm.guarantors.update',
            'crm.guarantors.archive',
            'crm.guarantors.verify',
            'crm.guarantors.reject',
            'crm.proxies.view',
            'crm.proxies.create',
            'crm.proxies.update',
            'crm.proxies.archive',
            'crm.proxies.verify',
            'crm.proxies.reject',
            'crm.proxies.expire',
        ];
    }

    /**
     * @return array<int, string>
     */
    private function protectedPermissions(): array
    {
        return [
            'agencies.manage',
            'roles.manage',
            'users.manage',
            'users.roles.manage',
            'staff.assignments.manage',
            'batch.procedures.manage',
            'batch.runs.manage',
            'crm.scope.institution.read',
            'crm.scope.institution.review',
            'crm.scope.institution.manage',
            'crm.pii.view',
            'crm.kyc.override.expired_identity',
            'crm.kyc.override.self_verify',
            'crm.kyc.verify',
            'crm.kyc.reject',
            'crm.identity_documents.verify',
            'crm.identity_documents.reject',
            'crm.guarantors.verify',
            'crm.guarantors.reject',
            'crm.proxies.verify',
            'crm.proxies.reject',
        ];
    }

    private function roleDescription(string $name): string
    {
        return match ($name) {
            'platform-admin' => 'Full institution-level administration authority.',
            'user-admin' => 'Legacy compatibility role for staff administration.',
            'agency-manager' => 'Agency-scoped operational manager.',
            'regional-manager' => 'Regional oversight role with read access.',
            'teller' => 'Cash operations and teller workflow role.',
            'loan-officer' => 'Loan origination and servicing role.',
            'accountant' => 'Accounting and audit support role.',
            'kyc-officer' => 'Agency KYC operations and verification role.',
            'compliance-officer' => 'Institution-wide KYC compliance review role.',
            'auditor' => 'Read-only audit and oversight role.',
            'staff' => 'Minimal baseline staff role.',
            default => 'Configured role.',
        };
    }
}
