<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Application\Authorization\SyncRolePermissions;
use App\Http\Controllers\BaseController;
use App\Support\Security\SecurityAudit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

final class RoleController extends BaseController
{
    public function __construct(private readonly SecurityAudit $securityAudit) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Role::class);

        $protected = $this->protectedPermissions();
        $nonDelegable = $this->nonDelegableProtectedPermissions();
        sort($protected);
        sort($nonDelegable);
        $delegable = array_values(array_diff($protected, $nonDelegable));

        return $this->respondSuccess([
            'roles' => $this->roleCatalog(),
            'permissions' => $this->permissionCatalog(),
            // Lets the role editor distinguish a disabled/restricted checkbox
            // from an ordinary unchecked one (AIR-002).
            'permission_policy' => [
                'protected' => $protected,
                'non_delegable' => $nonDelegable,
                'delegable' => $delegable,
                'delegation_enabled' => config('security.permissions.allow_protected_delegation', false) === true,
            ],
        ]);
    }

    /**
     * Full-replacement update of a role's permission set.
     *
     * This intentionally replaces the entire set, so the frontend MUST send
     * the complete selected list. To make the destructive nature impossible to
     * miss, callers must send `replace=true`, the response reports
     * `previous_permissions`, `added_permissions`, and `removed_permissions`,
     * and an optional `expected_permissions_version` guards against a stale
     * editor baseline silently overwriting a newer save (AIR-001). For
     * single-checkbox toggles, prefer the grant/revoke endpoints.
     */
    public function updatePermissions(Request $request, string $role, SyncRolePermissions $syncRolePermissions): JsonResponse
    {
        $this->authorize('updatePermissions', [Role::class, $role]);

        $permissionNames = $this->permissionNames();

        $validated = Validator::make($request->all(), [
            'replace' => ['required', 'accepted'],
            'permissions' => ['required', 'array', 'min:1'],
            'permissions.*' => ['required', 'string', Rule::in($permissionNames)],
            'expected_permissions_version' => ['sometimes', 'nullable', 'string'],
        ])->validate();

        $roleModel = Role::query()->where('name', $role)->first();
        if ($roleModel === null) {
            return $this->respondNotFound();
        }

        $previous = $this->grantedPermissions($roleModel);

        $expectedVersion = $validated['expected_permissions_version'] ?? null;
        if (is_string($expectedVersion) && $expectedVersion !== '' && $expectedVersion !== $this->permissionsVersion($previous)) {
            return $this->respondError(
                'Role permissions changed since they were last loaded. Reload the role editor and reapply your selection.',
                ['expected_permissions_version' => ['Stale role permission baseline.']],
                409,
            );
        }

        $permissions = array_values(array_unique($validated['permissions']));
        sort($permissions);

        $protectedGuard = $this->guardProtectedPermissions($request, $role, $permissions, $previous);
        if ($protectedGuard instanceof JsonResponse) {
            return $protectedGuard;
        }

        $minimumGuard = $this->guardMinimumPlatformPermissions($role, $permissions);
        if ($minimumGuard instanceof JsonResponse) {
            return $minimumGuard;
        }

        $syncRolePermissions->execute($roleModel, $permissions);
        $roleModel->load('permissions');
        $current = $this->grantedPermissions($roleModel);

        $this->securityAudit->record('role.permissions_changed', actor: $request->user(), properties: [
            'role' => $role,
            'permissions' => $current,
            'added_permissions' => array_values(array_diff($current, $previous)),
            'removed_permissions' => array_values(array_diff($previous, $current)),
        ], request: $request);

        return $this->respondSuccess(
            $this->roleMutationPayload($roleModel, $previous, $current),
            'Role permissions updated successfully'
        );
    }

    /**
     * Additively grant a single permission (checkbox toggle on). Never revokes
     * any other permission, so it cannot silently strip a role (AIR-001).
     */
    public function grantPermission(Request $request, string $role, string $permission): JsonResponse
    {
        $this->authorize('updatePermissions', [Role::class, $role]);

        if (! in_array($permission, $this->permissionNames(), true)) {
            return $this->respondNotFound('Unknown permission.');
        }

        $roleModel = Role::query()->where('name', $role)->first();
        if ($roleModel === null) {
            return $this->respondNotFound();
        }

        $previous = $this->grantedPermissions($roleModel);
        if (in_array($permission, $previous, true)) {
            return $this->respondSuccess($this->roleMutationPayload($roleModel, $previous, $previous), 'Permission already granted');
        }

        $permissions = $previous;
        $permissions[] = $permission;
        sort($permissions);

        $protectedGuard = $this->guardProtectedPermissions($request, $role, $permissions, $previous);
        if ($protectedGuard instanceof JsonResponse) {
            return $protectedGuard;
        }

        $roleModel->givePermissionTo($permission);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $roleModel->load('permissions');
        $current = $this->grantedPermissions($roleModel);

        $this->securityAudit->record('role.permission_granted', actor: $request->user(), properties: [
            'role' => $role,
            'permission' => $permission,
        ], request: $request);

        return $this->respondSuccess($this->roleMutationPayload($roleModel, $previous, $current), 'Permission granted successfully');
    }

    /**
     * Subtractively revoke a single permission (checkbox toggle off). Only the
     * named permission is removed; platform-admin minimums cannot be revoked.
     */
    public function revokePermission(Request $request, string $role, string $permission): JsonResponse
    {
        $this->authorize('updatePermissions', [Role::class, $role]);

        $roleModel = Role::query()->where('name', $role)->first();
        if ($roleModel === null) {
            return $this->respondNotFound();
        }

        $previous = $this->grantedPermissions($roleModel);
        if (! in_array($permission, $previous, true)) {
            return $this->respondSuccess($this->roleMutationPayload($roleModel, $previous, $previous), 'Permission already absent');
        }

        $permissions = array_values(array_filter($previous, static fn (string $name): bool => $name !== $permission));

        $minimumGuard = $this->guardMinimumPlatformPermissions($role, $permissions);
        if ($minimumGuard instanceof JsonResponse) {
            return $minimumGuard;
        }

        $roleModel->revokePermissionTo($permission);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $roleModel->load('permissions');
        $current = $this->grantedPermissions($roleModel);

        $this->securityAudit->record('role.permission_revoked', actor: $request->user(), properties: [
            'role' => $role,
            'permission' => $permission,
        ], request: $request);

        return $this->respondSuccess($this->roleMutationPayload($roleModel, $previous, $current), 'Permission revoked successfully');
    }

    /**
     * @return array<int, string>
     */
    private function grantedPermissions(Role $roleModel): array
    {
        /** @var array<int, string> $names */
        $names = $roleModel->permissions->pluck('name')->sort()->values()->all();

        return $names;
    }

    /**
     * @param  array<int, string>  $previous
     * @param  array<int, string>  $current
     * @return array<string, mixed>
     */
    private function roleMutationPayload(Role $roleModel, array $previous, array $current): array
    {
        return [
            'role' => [
                'name' => $roleModel->name,
                'guard_name' => $roleModel->guard_name,
                'permissions' => $current,
                'permissions_version' => $this->permissionsVersion($current),
                'previous_permissions' => $previous,
                'added_permissions' => array_values(array_diff($current, $previous)),
                'removed_permissions' => array_values(array_diff($previous, $current)),
            ],
        ];
    }

    /**
     * Deterministic fingerprint of a permission set for optimistic concurrency.
     *
     * @param  array<int, string>  $permissions
     */
    private function permissionsVersion(array $permissions): string
    {
        $normalized = array_values(array_unique($permissions));
        sort($normalized);

        return sha1(implode("\n", $normalized));
    }

    /**
     * Enforce the protected-permission policy on a non-platform role. Already
     * granted (or configured-baseline) protected permissions may be retained;
     * only newly-added protected permissions are gated (AIR-002).
     *
     * @param  array<int, string>  $requested
     * @param  array<int, string>  $currentlyGranted
     */
    private function guardProtectedPermissions(Request $request, string $role, array $requested, array $currentlyGranted): ?JsonResponse
    {
        if ($role === 'platform-admin') {
            return null;
        }

        $requestedProtected = array_values(array_intersect($requested, $this->protectedPermissions()));
        if ($requestedProtected === []) {
            return null;
        }

        $retainable = array_values(array_unique(array_merge(
            $currentlyGranted,
            $this->configuredRoleDefinitions()[$role] ?? [],
        )));
        $newlyAdded = array_values(array_diff($requestedProtected, $retainable));
        if ($newlyAdded === []) {
            // Only retaining permissions the role already holds by grant or
            // configured baseline — never a silent escalation.
            return null;
        }

        if (config('security.permissions.allow_protected_delegation', false) !== true) {
            return $this->respondUnprocessable('Protected permissions can only be granted to platform administrators.');
        }

        $nonDelegable = array_values(array_intersect($newlyAdded, $this->nonDelegableProtectedPermissions()));
        if ($nonDelegable !== []) {
            return $this->respondUnprocessable('These permissions can never be delegated to non-platform roles: '.implode(', ', $nonDelegable).'.');
        }

        $this->securityAudit->record('role.protected_permissions_delegated', actor: $request->user(), properties: [
            'role' => $role,
            'delegated_permissions' => $newlyAdded,
        ], request: $request);

        return null;
    }

    /**
     * @param  array<int, string>  $permissions
     */
    private function guardMinimumPlatformPermissions(string $role, array $permissions): ?JsonResponse
    {
        if ($role !== 'platform-admin') {
            return null;
        }

        if (array_diff($this->minimumPlatformPermissions(), $permissions) !== []) {
            return $this->respondUnprocessable('Platform administrator must retain the minimum administration permissions.');
        }

        return null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function roleCatalog(): array
    {
        $roleDefinitions = $this->configuredRoleDefinitions();
        $roleModels = Role::whereIn('name', array_keys($roleDefinitions))
            ->with('permissions')
            ->get()
            ->keyBy('name');
        $roles = [];

        foreach (array_keys($roleDefinitions) as $name) {
            /** @var Role|null $roleModel */
            $roleModel = $roleModels->get($name);
            $permissions = $roleModel instanceof Role ? $this->grantedPermissions($roleModel) : [];

            $roles[] = [
                'name' => $name,
                'display_name' => str($name)->replace('-', ' ')->title()->toString(),
                'description' => $this->roleDescription($name),
                'assignable' => $name !== 'platform-admin',
                'permissions' => $permissions,
                'permissions_version' => $this->permissionsVersion($permissions),
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
            'cash.denominations.view',
            'cash.denominations.manage',
            'cash.tills.view',
            'cash.tills.manage',
        ];
    }

    /**
     * @return array<int, string>
     */
    /**
     * Protected permissions that can NEVER be delegated to non-platform roles,
     * even when protected delegation is enabled. These confer institution-wide
     * control and must remain platform-admin only.
     *
     * @return array<int, string>
     */
    private function nonDelegableProtectedPermissions(): array
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
            'cash.denominations.manage',
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
