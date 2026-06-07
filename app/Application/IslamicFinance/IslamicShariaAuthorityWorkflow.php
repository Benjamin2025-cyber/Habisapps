<?php

declare(strict_types=1);

namespace App\Application\IslamicFinance;

use App\Http\Controllers\BaseController;
use App\Models\User;
use App\Support\Security\SecurityAudit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

final class IslamicShariaAuthorityWorkflow extends BaseController
{
    public function __construct(
        private readonly SecurityAudit $securityAudit,
    ) {}

    public function index(Request $request): JsonResponse
    {
        if (! $this->requirePlatformAdmin($request)) {
            return $this->respondForbidden();
        }

        $validated = Validator::make($request->all(), [
            'status' => ['sometimes', 'nullable', Rule::in(IslamicShariaAuthorityService::STATUSES)],
            'authority_type' => ['sometimes', 'nullable', Rule::in(IslamicShariaAuthorityService::AUTHORITY_TYPES)],
            'jurisdiction' => ['sometimes', 'nullable', 'string'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ])->validate();

        $query = DB::table('islamic_sharia_authorities as a')->select('a.*');

        $status = isset($validated['status']) && is_string($validated['status']) ? $validated['status'] : '';
        $authorityType = isset($validated['authority_type']) && is_string($validated['authority_type']) ? $validated['authority_type'] : '';
        $jurisdiction = isset($validated['jurisdiction']) && is_string($validated['jurisdiction']) ? $validated['jurisdiction'] : '';

        if ($status !== '') {
            $query->where('a.status', $status);
        }
        if ($authorityType !== '') {
            $query->where('a.authority_type', $authorityType);
        }
        if ($jurisdiction !== '') {
            $query->where('a.jurisdiction', $jurisdiction);
        }

        $search = $request->query('search');
        if (is_string($search) && trim($search) !== '') {
            $term = trim($search);
            $query->where(function ($builder) use ($term): void {
                $builder->where('a.public_id', 'ilike', '%'.$term.'%')
                    ->orWhere('a.name', 'ilike', '%'.$term.'%')
                    ->orWhere('a.authority_type', 'ilike', '%'.$term.'%')
                    ->orWhere('a.jurisdiction', 'ilike', '%'.$term.'%')
                    ->orWhere('a.status', 'ilike', '%'.$term.'%');
            });
        }

        $perPage = isset($validated['per_page']) && is_numeric($validated['per_page']) ? (int) $validated['per_page'] : 25;
        $page = isset($validated['page']) && is_numeric($validated['page']) ? (int) $validated['page'] : 1;
        $total = (clone $query)->count();
        $rows = $query->orderByDesc('a.id')->forPage($page, $perPage)->get();

        $payload = $this->bulkAssemblePayloads($rows->all());

        return $this->respondSuccess($payload, 'Sharia authorities listed', meta: [
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        if (! $this->requirePlatformAdmin($request)) {
            return $this->respondForbidden();
        }
        $actor = $request->user();
        if (! $actor instanceof User) {
            return $this->respondForbidden();
        }

        try {
            $validated = $this->validateAuthorityPayload($request, requireDocument: true);
            $row = DB::transaction(function () use ($validated, $actor): object {
                return $this->insertDraft($validated, $actor);
            });
        } catch (InvalidArgumentException $exception) {
            return $this->respondUnprocessable(errors: ['islamic_sharia_authority' => [$exception->getMessage()]]);
        }

        $this->securityAudit->record('islamic.sharia_authority.created', actor: $actor, properties: [
            'authority_public_id' => $this->rowString($row, 'public_id'),
            'name' => $this->rowString($row, 'name'),
            'authority_type' => $this->rowString($row, 'authority_type'),
            'jurisdiction' => $this->rowString($row, 'jurisdiction'),
        ], request: $request);

        return $this->respondCreated($this->singlePayload($row), 'Sharia authority draft created');
    }

    public function show(Request $request, string $publicId): JsonResponse
    {
        if (! $this->requirePlatformAdmin($request)) {
            return $this->respondForbidden();
        }

        $row = DB::table('islamic_sharia_authorities')->where('public_id', $publicId)->first();
        if (! is_object($row)) {
            return $this->respondNotFound('Sharia authority not found.');
        }

        return $this->respondSuccess($this->singlePayload($row), 'Sharia authority retrieved');
    }

    public function updateDraft(Request $request, string $publicId): JsonResponse
    {
        if (! $this->requirePlatformAdmin($request)) {
            return $this->respondForbidden();
        }
        $actor = $request->user();
        if (! $actor instanceof User) {
            return $this->respondForbidden();
        }

        $existingForDefaults = DB::table('islamic_sharia_authorities')->where('public_id', $publicId)->first();
        if (! is_object($existingForDefaults)) {
            return $this->respondNotFound('Sharia authority not found.');
        }
        if ($this->rowString($existingForDefaults, 'status') !== 'draft') {
            return $this->respondUnprocessable(errors: ['islamic_sharia_authority' => [__('Only draft authorities can be updated.')]]);
        }

        try {
            $validated = $this->validateAuthorityPayload($request, requireDocument: false, defaultsFrom: $existingForDefaults);
            $result = DB::transaction(function () use ($publicId, $validated): array {
                $existing = DB::table('islamic_sharia_authorities')->where('public_id', $publicId)->lockForUpdate()->first();
                if (! is_object($existing)) {
                    throw new InvalidArgumentException('Sharia authority not found.');
                }
                if ($this->rowString($existing, 'status') !== 'draft') {
                    throw new InvalidArgumentException('Only draft authorities can be updated.');
                }
                $before = (array) $existing;
                $columns = $this->buildUpdateColumns($validated);
                if ($columns !== []) {
                    DB::table('islamic_sharia_authorities')->where('id', $this->rowInt($existing, 'id'))->update(array_merge($columns, ['updated_at' => now()]));
                }
                $updated = DB::table('islamic_sharia_authorities')->where('id', $this->rowInt($existing, 'id'))->first();
                if (! is_object($updated)) {
                    throw new InvalidArgumentException('Reload failed.');
                }

                return ['row' => $updated, 'before' => $before, 'changed' => array_keys($columns)];
            });
        } catch (InvalidArgumentException $exception) {
            return $this->respondUnprocessable(errors: ['islamic_sharia_authority' => [$exception->getMessage()]]);
        }

        $updated = $result['row'];
        $this->securityAudit->record('islamic.sharia_authority.updated', actor: $actor, properties: [
            'authority_public_id' => $this->rowString($updated, 'public_id'),
            'changed_fields' => $result['changed'],
        ], request: $request);

        return $this->respondSuccess($this->singlePayload($updated), 'Sharia authority updated');
    }

    public function activate(Request $request, string $publicId): JsonResponse
    {
        if (! $this->requirePlatformAdmin($request)) {
            return $this->respondForbidden();
        }
        $actor = $request->user();
        if (! $actor instanceof User) {
            return $this->respondForbidden();
        }

        try {
            $result = DB::transaction(function () use ($publicId, $actor): object {
                $row = DB::table('islamic_sharia_authorities')->where('public_id', $publicId)->lockForUpdate()->first();
                if (! is_object($row)) {
                    throw new InvalidArgumentException('Sharia authority not found.');
                }
                if ($this->rowString($row, 'status') !== 'draft') {
                    throw new InvalidArgumentException('Only draft authorities can be activated.');
                }
                $document = DB::table('documents')->where('id', $this->rowInt($row, 'document_id'))->first(['status']);
                if (! is_object($document) || $this->rowString($document, 'status') !== 'active') {
                    throw new InvalidArgumentException('Authority must have an active evidence document.');
                }

                $members = DB::table('islamic_sharia_authority_members')
                    ->where('islamic_sharia_authority_id', $this->rowInt($row, 'id'))
                    ->where('status', 'active')
                    ->get();
                $hasApprover = false;
                $hasChairOrAdmin = false;
                foreach ($members as $m) {
                    $role = $this->rowString($m, 'member_role');
                    if ($role === 'approver') {
                        $hasApprover = true;
                    }
                    if ($role === 'chair' || $role === 'administrator') {
                        $hasChairOrAdmin = true;
                    }
                }
                if (! $hasApprover) {
                    throw new InvalidArgumentException('Authority must have at least one active approver member.');
                }
                if (! $hasChairOrAdmin) {
                    throw new InvalidArgumentException('Authority must have at least one active chair or administrator member.');
                }

                DB::table('islamic_sharia_authorities')->where('id', $this->rowInt($row, 'id'))->update([
                    'status' => 'active',
                    'activated_by_user_id' => $actor->id,
                    'activated_at' => now(),
                    'updated_at' => now(),
                ]);

                $updated = DB::table('islamic_sharia_authorities')->where('id', $this->rowInt($row, 'id'))->first();
                if (! is_object($updated)) {
                    throw new InvalidArgumentException('Reload failed.');
                }

                return $updated;
            });
        } catch (InvalidArgumentException $exception) {
            return $this->respondUnprocessable(errors: ['islamic_sharia_authority' => [$exception->getMessage()]]);
        }

        $this->securityAudit->record('islamic.sharia_authority.activated', actor: $actor, properties: [
            'authority_public_id' => $this->rowString($result, 'public_id'),
            'effective_date' => $this->rowString($result, 'effective_date'),
            'expiry_date' => $this->nullableRowString($result, 'expiry_date'),
        ], request: $request);

        return $this->respondSuccess($this->singlePayload($result), 'Sharia authority activated');
    }

    public function suspend(Request $request, string $publicId): JsonResponse
    {
        return $this->statusTransition($request, $publicId, fromStatuses: ['active'], to: 'suspended', event: 'islamic.sharia_authority.suspended');
    }

    public function revoke(Request $request, string $publicId): JsonResponse
    {
        return $this->statusTransition($request, $publicId, fromStatuses: ['active', 'suspended'], to: 'revoked', event: 'islamic.sharia_authority.revoked');
    }

    public function retire(Request $request, string $publicId): JsonResponse
    {
        return $this->statusTransition($request, $publicId, fromStatuses: ['active', 'suspended', 'revoked'], to: 'retired', event: 'islamic.sharia_authority.retired');
    }

    public function storeMember(Request $request, string $authorityPublicId): JsonResponse
    {
        if (! $this->requirePlatformAdmin($request)) {
            return $this->respondForbidden();
        }
        $actor = $request->user();
        if (! $actor instanceof User) {
            return $this->respondForbidden();
        }

        try {
            $validated = $this->validateMemberPayload($request);
            $memberRow = DB::transaction(function () use ($authorityPublicId, $validated, $actor): object {
                $authority = DB::table('islamic_sharia_authorities')->where('public_id', $authorityPublicId)->lockForUpdate()->first();
                if (! is_object($authority)) {
                    throw new InvalidArgumentException('Sharia authority not found.');
                }

                $authorityEffective = $this->rowString($authority, 'effective_date');
                $authorityExpiry = $this->nullableRowString($authority, 'expiry_date');
                if ($authorityEffective !== '' && $validated['starts_on'] < $authorityEffective) {
                    throw new InvalidArgumentException('Member starts_on must not precede authority effective_date.');
                }
                if ($authorityExpiry !== null && $validated['starts_on'] >= $authorityExpiry) {
                    throw new InvalidArgumentException('Member starts_on must precede authority expiry_date.');
                }
                if ($validated['ends_on'] !== null && $authorityExpiry !== null && $validated['ends_on'] > $authorityExpiry) {
                    throw new InvalidArgumentException('Member ends_on must not exceed authority expiry_date.');
                }

                $conflict = DB::table('islamic_sharia_authority_members')
                    ->where('islamic_sharia_authority_id', $this->rowInt($authority, 'id'))
                    ->where('user_id', $validated['user_id'])
                    ->where('status', 'active')
                    ->whereIn('member_role', $this->conflictingRoles($validated['member_role']))
                    ->exists();
                if ($conflict) {
                    throw new InvalidArgumentException('User already holds a conflicting active role in this authority.');
                }

                $id = DB::table('islamic_sharia_authority_members')->insertGetId([
                    'public_id' => (string) Str::ulid(),
                    'islamic_sharia_authority_id' => $this->rowInt($authority, 'id'),
                    'user_id' => $validated['user_id'],
                    'member_role' => $validated['member_role'],
                    'scope' => $validated['scope'] !== null ? json_encode($validated['scope'], JSON_THROW_ON_ERROR) : null,
                    'starts_on' => $validated['starts_on'],
                    'ends_on' => $validated['ends_on'],
                    'status' => 'active',
                    'evidence_document_id' => $validated['evidence_document_id'],
                    'created_by_user_id' => $actor->id,
                    'metadata' => $validated['metadata'] !== null ? json_encode($validated['metadata'], JSON_THROW_ON_ERROR) : null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $row = DB::table('islamic_sharia_authority_members')->where('id', $id)->first();
                if (! is_object($row)) {
                    throw new InvalidArgumentException('Member reload failed.');
                }

                return $row;
            });
        } catch (InvalidArgumentException $exception) {
            return $this->respondUnprocessable(errors: ['islamic_sharia_authority_member' => [$exception->getMessage()]]);
        }

        $this->securityAudit->record('islamic.sharia_authority.member_added', actor: $actor, properties: [
            'authority_public_id' => $authorityPublicId,
            'member_public_id' => $this->rowString($memberRow, 'public_id'),
            'member_role' => $this->rowString($memberRow, 'member_role'),
            'user_id' => $this->rowInt($memberRow, 'user_id'),
        ], request: $request);

        return $this->respondCreated($this->memberPayload($memberRow), 'Authority member added');
    }

    public function updateMember(Request $request, string $authorityPublicId, string $memberPublicId): JsonResponse
    {
        if (! $this->requirePlatformAdmin($request)) {
            return $this->respondForbidden();
        }
        $actor = $request->user();
        if (! $actor instanceof User) {
            return $this->respondForbidden();
        }

        $validated = Validator::make($request->all(), [
            'member_role' => ['sometimes', Rule::in(IslamicShariaAuthorityService::MEMBER_ROLES)],
            'scope' => ['sometimes', 'nullable', 'array'],
            'starts_on' => ['sometimes', 'date'],
            'ends_on' => ['sometimes', 'nullable', 'date'],
            'evidence_document_public_id' => ['sometimes', 'nullable', 'string'],
            'metadata' => ['sometimes', 'nullable', 'array'],
        ])->validate();

        try {
            $result = DB::transaction(function () use ($authorityPublicId, $memberPublicId, $validated, $actor): array {
                $member = DB::table('islamic_sharia_authority_members as m')
                    ->join('islamic_sharia_authorities as a', 'a.id', '=', 'm.islamic_sharia_authority_id')
                    ->where('m.public_id', $memberPublicId)
                    ->where('a.public_id', $authorityPublicId)
                    ->lockForUpdate()
                    ->select('m.*', 'a.effective_date as authority_effective', 'a.expiry_date as authority_expiry')
                    ->first();
                if (! is_object($member)) {
                    throw new InvalidArgumentException('Authority member not found.');
                }
                if ($this->rowString($member, 'status') !== 'active') {
                    throw new InvalidArgumentException('Only active members can be updated; use suspend/revoke endpoints for state changes.');
                }

                $update = [];
                if (isset($validated['member_role']) && is_string($validated['member_role'])) {
                    $update['member_role'] = $validated['member_role'];
                }
                if (array_key_exists('scope', $validated)) {
                    $update['scope'] = is_array($validated['scope']) ? json_encode($validated['scope'], JSON_THROW_ON_ERROR) : null;
                }
                $startsOn = isset($validated['starts_on']) && is_string($validated['starts_on']) ? $validated['starts_on'] : $this->rowString($member, 'starts_on');
                $endsOn = array_key_exists('ends_on', $validated)
                    ? (is_string($validated['ends_on']) ? $validated['ends_on'] : null)
                    : $this->nullableRowString($member, 'ends_on');
                if ($endsOn !== null && $startsOn !== '' && $endsOn <= $startsOn) {
                    throw new InvalidArgumentException('ends_on must be after starts_on.');
                }
                $authorityEffective = $this->nullableRowString($member, 'authority_effective');
                $authorityExpiry = $this->nullableRowString($member, 'authority_expiry');
                if ($authorityEffective !== null && $startsOn !== '' && $startsOn < $authorityEffective) {
                    throw new InvalidArgumentException('Member starts_on must not precede authority effective_date.');
                }
                if ($authorityExpiry !== null && $endsOn !== null && $endsOn > $authorityExpiry) {
                    throw new InvalidArgumentException('Member ends_on must not exceed authority expiry_date.');
                }
                if (isset($validated['starts_on'])) {
                    $update['starts_on'] = $startsOn;
                }
                if (array_key_exists('ends_on', $validated)) {
                    $update['ends_on'] = $endsOn;
                }
                if (array_key_exists('evidence_document_public_id', $validated)) {
                    if (is_string($validated['evidence_document_public_id']) && $validated['evidence_document_public_id'] !== '') {
                        $doc = DB::table('documents')->where('public_id', $validated['evidence_document_public_id'])->first(['id', 'status']);
                        if (! is_object($doc) || $this->rowString($doc, 'status') !== 'active') {
                            throw new InvalidArgumentException('Evidence document must be active.');
                        }
                        $update['evidence_document_id'] = $this->rowInt($doc, 'id');
                    } else {
                        $update['evidence_document_id'] = null;
                    }
                }
                if (array_key_exists('metadata', $validated)) {
                    $update['metadata'] = is_array($validated['metadata']) ? json_encode($validated['metadata'], JSON_THROW_ON_ERROR) : null;
                }

                if ($update !== []) {
                    $update['updated_by_user_id'] = $actor->id;
                    $update['updated_at'] = now();
                    DB::table('islamic_sharia_authority_members')->where('id', $this->rowInt($member, 'id'))->update($update);
                }

                $updated = DB::table('islamic_sharia_authority_members')->where('id', $this->rowInt($member, 'id'))->first();
                if (! is_object($updated)) {
                    throw new InvalidArgumentException('Reload failed.');
                }

                return ['row' => $updated, 'changed' => array_keys($update)];
            });
        } catch (InvalidArgumentException $exception) {
            return $this->respondUnprocessable(errors: ['islamic_sharia_authority_member' => [$exception->getMessage()]]);
        }

        $updated = $result['row'];
        $this->securityAudit->record('islamic.sharia_authority.member_updated', actor: $actor, properties: [
            'authority_public_id' => $authorityPublicId,
            'member_public_id' => $this->rowString($updated, 'public_id'),
            'changed_fields' => $result['changed'],
        ], request: $request);

        return $this->respondSuccess($this->memberPayload($updated), 'Authority member updated');
    }

    public function suspendMember(Request $request, string $authorityPublicId, string $memberPublicId): JsonResponse
    {
        return $this->memberStatusTransition($request, $authorityPublicId, $memberPublicId, from: ['active'], to: 'suspended', event: 'islamic.sharia_authority.member_suspended');
    }

    public function revokeMember(Request $request, string $authorityPublicId, string $memberPublicId): JsonResponse
    {
        return $this->memberStatusTransition($request, $authorityPublicId, $memberPublicId, from: ['active', 'suspended'], to: 'revoked', event: 'islamic.sharia_authority.member_revoked');
    }

    /**
     * @param  array<int, string>  $fromStatuses
     */
    private function statusTransition(Request $request, string $publicId, array $fromStatuses, string $to, string $event): JsonResponse
    {
        if (! $this->requirePlatformAdmin($request)) {
            return $this->respondForbidden();
        }
        $actor = $request->user();
        if (! $actor instanceof User) {
            return $this->respondForbidden();
        }

        $requireReason = $to === 'retired' || $to === 'revoked' || $to === 'suspended';
        $validated = Validator::make($request->all(), [
            'reason' => [$requireReason ? 'required' : 'sometimes', 'string', 'max:4000'],
        ])->validate();
        $reason = isset($validated['reason']) && is_string($validated['reason']) ? $validated['reason'] : '';

        try {
            $result = DB::transaction(function () use ($publicId, $fromStatuses, $to, $reason, $actor): array {
                $row = DB::table('islamic_sharia_authorities')->where('public_id', $publicId)->lockForUpdate()->first();
                if (! is_object($row)) {
                    throw new InvalidArgumentException('Sharia authority not found.');
                }
                $previous = $this->rowString($row, 'status');
                if (! in_array($previous, $fromStatuses, true)) {
                    throw new InvalidArgumentException(__('islamic_governance.authority_status_cannot_transition', ['previous' => $previous, 'to' => $to]));
                }
                $update = ['status' => $to, 'updated_at' => now()];
                if ($to === 'retired') {
                    $update['retired_by_user_id'] = $actor->id;
                    $update['retired_at'] = now();
                    $update['retirement_reason'] = $reason;
                }
                DB::table('islamic_sharia_authorities')->where('id', $this->rowInt($row, 'id'))->update($update);

                $updated = DB::table('islamic_sharia_authorities')->where('id', $this->rowInt($row, 'id'))->first();
                if (! is_object($updated)) {
                    throw new InvalidArgumentException('Reload failed.');
                }

                return ['row' => $updated, 'previous' => $previous];
            });
        } catch (InvalidArgumentException $exception) {
            return $this->respondUnprocessable(errors: ['islamic_sharia_authority' => [$exception->getMessage()]]);
        }

        $updated = $result['row'];
        $this->securityAudit->record($event, actor: $actor, properties: [
            'authority_public_id' => $this->rowString($updated, 'public_id'),
            'previous_status' => $result['previous'],
            'new_status' => $to,
            'reason' => $reason,
        ], request: $request);

        return $this->respondSuccess($this->singlePayload($updated), 'Sharia authority '.$to);
    }

    /**
     * @param  array<int, string>  $from
     */
    private function memberStatusTransition(Request $request, string $authorityPublicId, string $memberPublicId, array $from, string $to, string $event): JsonResponse
    {
        if (! $this->requirePlatformAdmin($request)) {
            return $this->respondForbidden();
        }
        $actor = $request->user();
        if (! $actor instanceof User) {
            return $this->respondForbidden();
        }

        $validated = Validator::make($request->all(), [
            'reason' => ['required', 'string', 'max:4000'],
        ])->validate();
        $reason = isset($validated['reason']) && is_string($validated['reason']) ? $validated['reason'] : '';

        try {
            $result = DB::transaction(function () use ($authorityPublicId, $memberPublicId, $from, $to, $actor): array {
                $member = DB::table('islamic_sharia_authority_members as m')
                    ->join('islamic_sharia_authorities as a', 'a.id', '=', 'm.islamic_sharia_authority_id')
                    ->where('m.public_id', $memberPublicId)
                    ->where('a.public_id', $authorityPublicId)
                    ->lockForUpdate()
                    ->select('m.*')
                    ->first();
                if (! is_object($member)) {
                    throw new InvalidArgumentException('Authority member not found.');
                }
                $previous = $this->rowString($member, 'status');
                if (! in_array($previous, $from, true)) {
                    throw new InvalidArgumentException(__('islamic_governance.authority_member_status_cannot_transition', ['previous' => $previous, 'to' => $to]));
                }

                DB::table('islamic_sharia_authority_members')->where('id', $this->rowInt($member, 'id'))->update([
                    'status' => $to,
                    'updated_by_user_id' => $actor->id,
                    'updated_at' => now(),
                ]);

                $updated = DB::table('islamic_sharia_authority_members')->where('id', $this->rowInt($member, 'id'))->first();
                if (! is_object($updated)) {
                    throw new InvalidArgumentException('Reload failed.');
                }

                return ['row' => $updated, 'previous' => $previous];
            });
        } catch (InvalidArgumentException $exception) {
            return $this->respondUnprocessable(errors: ['islamic_sharia_authority_member' => [$exception->getMessage()]]);
        }

        $updated = $result['row'];
        $this->securityAudit->record($event, actor: $actor, properties: [
            'authority_public_id' => $authorityPublicId,
            'member_public_id' => $this->rowString($updated, 'public_id'),
            'previous_status' => $result['previous'],
            'new_status' => $to,
            'reason' => $reason,
        ], request: $request);

        return $this->respondSuccess($this->memberPayload($updated), 'Authority member '.$to);
    }

    /**
     * @return array<int, string>
     */
    private function conflictingRoles(string $newRole): array
    {
        // Policy: observer/approver are mutually exclusive (an observer cannot also approve).
        if ($newRole === 'approver') {
            return ['observer', 'approver'];
        }
        if ($newRole === 'observer') {
            return ['observer', 'approver'];
        }

        return [$newRole];
    }

    /**
     * @return array{
     *   name: string,
     *   authority_type: string,
     *   jurisdiction: string,
     *   mandate_scope: array<string, mixed>,
     *   mandate_summary: string,
     *   effective_date: string,
     *   expiry_date: string|null,
     *   document_id: int|null,
     *   metadata: array<array-key, mixed>|null,
     * }
     */
    private function validateAuthorityPayload(Request $request, bool $requireDocument, ?object $defaultsFrom = null): array
    {
        $hasDefaults = $defaultsFrom !== null;
        $validated = Validator::make($request->all(), [
            'name' => [$hasDefaults ? 'sometimes' : 'required', 'string', 'max:191'],
            'authority_type' => [$hasDefaults ? 'sometimes' : 'required', Rule::in(IslamicShariaAuthorityService::AUTHORITY_TYPES)],
            'jurisdiction' => [$hasDefaults ? 'sometimes' : 'required', 'string', 'max:64'],
            'mandate_scope' => [$hasDefaults ? 'sometimes' : 'required', 'array'],
            'mandate_summary' => [$hasDefaults ? 'sometimes' : 'required', 'string', 'max:8000'],
            'effective_date' => [$hasDefaults ? 'sometimes' : 'required', 'date'],
            'expiry_date' => ['sometimes', 'nullable', 'date'],
            'document_public_id' => [$requireDocument ? 'required' : 'sometimes', 'nullable', 'string'],
            'metadata' => ['sometimes', 'nullable', 'array'],
        ])->validate();

        $name = isset($validated['name']) && is_string($validated['name']) ? $validated['name'] : ($defaultsFrom !== null ? $this->rowString($defaultsFrom, 'name') : '');
        $authorityType = isset($validated['authority_type']) && is_string($validated['authority_type']) ? $validated['authority_type'] : ($defaultsFrom !== null ? $this->rowString($defaultsFrom, 'authority_type') : '');
        $jurisdiction = isset($validated['jurisdiction']) && is_string($validated['jurisdiction']) ? $validated['jurisdiction'] : ($defaultsFrom !== null ? $this->rowString($defaultsFrom, 'jurisdiction') : '');
        $mandateSummary = isset($validated['mandate_summary']) && is_string($validated['mandate_summary']) ? $validated['mandate_summary'] : ($defaultsFrom !== null ? $this->rowString($defaultsFrom, 'mandate_summary') : '');
        $effective = isset($validated['effective_date']) && is_string($validated['effective_date']) ? $validated['effective_date'] : ($defaultsFrom !== null ? $this->rowString($defaultsFrom, 'effective_date') : '');
        $expiry = array_key_exists('expiry_date', $validated) ? (is_string($validated['expiry_date']) ? $validated['expiry_date'] : null) : ($defaultsFrom !== null ? $this->nullableRowString($defaultsFrom, 'expiry_date') : null);

        if ($expiry !== null && $effective !== '' && $expiry <= $effective) {
            throw new InvalidArgumentException('expiry_date must be after effective_date.');
        }

        $mandateScope = null;
        if (isset($validated['mandate_scope']) && is_array($validated['mandate_scope'])) {
            $mandateScope = $this->normalizeScope($validated['mandate_scope']);
        } elseif ($defaultsFrom !== null) {
            $raw = ((array) $defaultsFrom)['mandate_scope'] ?? null;
            if (is_string($raw)) {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $mandateScope = $this->normalizeScope($decoded);
                }
            }
        }
        if ($mandateScope === null) {
            throw new InvalidArgumentException('mandate_scope is required and must be a structured object with type and optional codes.');
        }

        $documentId = null;
        if (isset($validated['document_public_id']) && is_string($validated['document_public_id']) && $validated['document_public_id'] !== '') {
            $doc = DB::table('documents')->where('public_id', $validated['document_public_id'])->first(['id', 'status']);
            if (! is_object($doc)) {
                throw new InvalidArgumentException('Evidence document not found.');
            }
            if ($this->rowString($doc, 'status') !== 'active') {
                throw new InvalidArgumentException('Evidence document must be active.');
            }
            $documentId = $this->rowInt($doc, 'id');
        } elseif ($defaultsFrom !== null) {
            $documentId = $this->rowInt($defaultsFrom, 'document_id');
        } elseif ($requireDocument) {
            throw new InvalidArgumentException('Evidence document is required.');
        }

        return [
            'name' => $name,
            'authority_type' => $authorityType,
            'jurisdiction' => $jurisdiction,
            'mandate_scope' => $mandateScope,
            'mandate_summary' => $mandateSummary,
            'effective_date' => $effective,
            'expiry_date' => $expiry,
            'document_id' => $documentId,
            'metadata' => isset($validated['metadata']) && is_array($validated['metadata']) ? $validated['metadata'] : null,
        ];
    }

    /**
     * @param  array<mixed>  $scope
     * @return array<string, mixed>
     */
    private function normalizeScope(array $scope): array
    {
        $type = isset($scope['type']) && is_string($scope['type']) ? $scope['type'] : '';
        if (! in_array($type, IslamicShariaAuthorityService::SCOPE_TYPES, true)) {
            throw new InvalidArgumentException(__('islamic_governance.mandate_scope_type_must_be_one_of', ['types' => implode(', ', IslamicShariaAuthorityService::SCOPE_TYPES)]));
        }
        if ($type === 'institution') {
            return ['type' => 'institution'];
        }
        $codes = isset($scope['codes']) && is_array($scope['codes']) ? $scope['codes'] : [];
        $sanitized = [];
        foreach ($codes as $code) {
            if (is_string($code) && $code !== '') {
                $sanitized[] = $code;
            }
        }
        if ($sanitized === []) {
            throw new InvalidArgumentException(__('islamic_governance.mandate_scope_codes_required_for_type', ['type' => $type]));
        }

        return ['type' => $type, 'codes' => array_values(array_unique($sanitized))];
    }

    /**
     * @return array{
     *   user_id: int,
     *   member_role: string,
     *   scope: array<string, mixed>|null,
     *   starts_on: string,
     *   ends_on: string|null,
     *   evidence_document_id: int|null,
     *   metadata: array<array-key, mixed>|null,
     * }
     */
    private function validateMemberPayload(Request $request): array
    {
        $validated = Validator::make($request->all(), [
            'user_public_id' => ['required', 'string', 'exists:users,public_id'],
            'member_role' => ['required', Rule::in(IslamicShariaAuthorityService::MEMBER_ROLES)],
            'scope' => ['sometimes', 'nullable', 'array'],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['sometimes', 'nullable', 'date'],
            'evidence_document_public_id' => ['sometimes', 'nullable', 'string'],
            'metadata' => ['sometimes', 'nullable', 'array'],
        ])->validate();

        $userPublicId = (string) $validated['user_public_id'];
        $user = DB::table('users')->where('public_id', $userPublicId)->first(['id']);
        if (! is_object($user)) {
            throw new InvalidArgumentException('User not found.');
        }

        $startsOn = (string) $validated['starts_on'];
        $endsOn = array_key_exists('ends_on', $validated) && is_string($validated['ends_on']) ? $validated['ends_on'] : null;
        if ($endsOn !== null && $endsOn <= $startsOn) {
            throw new InvalidArgumentException('ends_on must be after starts_on.');
        }

        $evidenceDocumentId = null;
        if (isset($validated['evidence_document_public_id']) && is_string($validated['evidence_document_public_id']) && $validated['evidence_document_public_id'] !== '') {
            $doc = DB::table('documents')->where('public_id', $validated['evidence_document_public_id'])->first(['id', 'status']);
            if (! is_object($doc)) {
                throw new InvalidArgumentException('Evidence document not found.');
            }
            if ($this->rowString($doc, 'status') !== 'active') {
                throw new InvalidArgumentException('Evidence document must be active.');
            }
            $evidenceDocumentId = $this->rowInt($doc, 'id');
        }

        /** @var array<string, mixed>|null $scope */
        $scope = isset($validated['scope']) && is_array($validated['scope'])
            ? $this->normalizeScope($validated['scope'])
            : null;

        return [
            'user_id' => $this->rowInt($user, 'id'),
            'member_role' => (string) $validated['member_role'],
            'scope' => $scope,
            'starts_on' => $startsOn,
            'ends_on' => $endsOn,
            'evidence_document_id' => $evidenceDocumentId,
            'metadata' => isset($validated['metadata']) && is_array($validated['metadata']) ? $validated['metadata'] : null,
        ];
    }

    /**
     * @param  array{
     *   name: string,
     *   authority_type: string,
     *   jurisdiction: string,
     *   mandate_scope: array<string, mixed>,
     *   mandate_summary: string,
     *   effective_date: string,
     *   expiry_date: string|null,
     *   document_id: int|null,
     *   metadata: array<array-key, mixed>|null,
     * }  $validated
     */
    private function insertDraft(array $validated, User $actor): object
    {
        if (! is_int($validated['document_id'])) {
            throw new InvalidArgumentException('Evidence document is required.');
        }
        $id = DB::table('islamic_sharia_authorities')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'name' => $validated['name'],
            'authority_type' => $validated['authority_type'],
            'jurisdiction' => $validated['jurisdiction'],
            'mandate_scope' => json_encode($validated['mandate_scope'], JSON_THROW_ON_ERROR),
            'mandate_summary' => $validated['mandate_summary'],
            'effective_date' => $validated['effective_date'],
            'expiry_date' => $validated['expiry_date'],
            'status' => 'draft',
            'document_id' => $validated['document_id'],
            'created_by_user_id' => $actor->id,
            'metadata' => $validated['metadata'] !== null ? json_encode($validated['metadata'], JSON_THROW_ON_ERROR) : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $row = DB::table('islamic_sharia_authorities')->where('id', $id)->first();
        if (! is_object($row)) {
            throw new InvalidArgumentException('Reload failed.');
        }

        return $row;
    }

    /**
     * @param  array{
     *   name: string,
     *   authority_type: string,
     *   jurisdiction: string,
     *   mandate_scope: array<string, mixed>,
     *   mandate_summary: string,
     *   effective_date: string,
     *   expiry_date: string|null,
     *   document_id: int|null,
     *   metadata: array<array-key, mixed>|null,
     * }  $validated
     * @return array<string, mixed>
     */
    private function buildUpdateColumns(array $validated): array
    {
        $update = [];
        foreach (['name', 'authority_type', 'jurisdiction', 'mandate_summary', 'effective_date'] as $key) {
            if ($validated[$key] !== '') {
                $update[$key] = $validated[$key];
            }
        }
        $update['expiry_date'] = $validated['expiry_date'];
        $update['mandate_scope'] = json_encode($validated['mandate_scope'], JSON_THROW_ON_ERROR);
        if (is_int($validated['document_id'])) {
            $update['document_id'] = $validated['document_id'];
        }
        if ($validated['metadata'] !== null) {
            $update['metadata'] = json_encode($validated['metadata'], JSON_THROW_ON_ERROR);
        }

        return $update;
    }

    /**
     * @param  array<int, object>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function bulkAssemblePayloads(array $rows): array
    {
        if ($rows === []) {
            return [];
        }
        $authorityIds = [];
        $documentIds = [];
        foreach ($rows as $row) {
            $authorityIds[] = $this->rowInt($row, 'id');
            $documentIds[] = $this->rowInt($row, 'document_id');
        }
        $documentPublicIds = DB::table('documents')->whereIn('id', array_unique($documentIds))->pluck('public_id', 'id');

        /** @var array<int, array<int, array<string, mixed>>> $membersByAuthority */
        $membersByAuthority = [];
        $memberRows = DB::table('islamic_sharia_authority_members')
            ->whereIn('islamic_sharia_authority_id', $authorityIds)
            ->where('status', 'active')
            ->orderBy('id')
            ->get();
        $userIds = [];
        foreach ($memberRows as $m) {
            $uid = $this->rowInt($m, 'user_id');
            if ($uid > 0) {
                $userIds[] = $uid;
            }
        }
        $userPublicIds = $userIds !== []
            ? DB::table('users')->whereIn('id', array_unique($userIds))->pluck('public_id', 'id')
            : collect();
        foreach ($memberRows as $m) {
            $aid = $this->rowInt($m, 'islamic_sharia_authority_id');
            $membersByAuthority[$aid] ??= [];
            $membersByAuthority[$aid][] = $this->memberPayload($m, $userPublicIds);
        }

        $payloads = [];
        foreach ($rows as $row) {
            $documentValue = $documentPublicIds[$this->rowInt($row, 'document_id')] ?? null;
            $payloads[] = $this->buildPayload(
                $row,
                $membersByAuthority[$this->rowInt($row, 'id')] ?? [],
                is_string($documentValue) ? $documentValue : null,
            );
        }

        return $payloads;
    }

    /**
     * @return array<string, mixed>
     */
    private function singlePayload(object $row): array
    {
        return $this->bulkAssemblePayloads([$row])[0];
    }

    /**
     * @param  array<int, array<string, mixed>>  $members
     * @return array<string, mixed>
     */
    private function buildPayload(object $row, array $members, ?string $documentPublicId): array
    {
        $mandateScope = null;
        $raw = ((array) $row)['mandate_scope'] ?? null;
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $mandateScope = $decoded;
            }
        }

        return [
            'public_id' => $this->rowString($row, 'public_id'),
            'name' => $this->rowString($row, 'name'),
            'authority_type' => $this->rowString($row, 'authority_type'),
            'jurisdiction' => $this->rowString($row, 'jurisdiction'),
            'mandate_scope' => $mandateScope,
            'mandate_summary' => $this->rowString($row, 'mandate_summary'),
            'effective_date' => $this->rowString($row, 'effective_date'),
            'expiry_date' => $this->nullableRowString($row, 'expiry_date'),
            'status' => $this->rowString($row, 'status'),
            'document_public_id' => $documentPublicId,
            'active_members' => $members,
            'created_at' => $this->nullableRowString($row, 'created_at'),
        ];
    }

    /**
     * @param  Collection<int, mixed>|null  $userPublicIds
     * @return array<string, mixed>
     */
    private function memberPayload(object $row, ?Collection $userPublicIds = null): array
    {
        $scope = null;
        $raw = ((array) $row)['scope'] ?? null;
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $scope = $decoded;
            }
        }
        $userPublicId = null;
        if ($userPublicIds !== null) {
            $value = $userPublicIds[$this->rowInt($row, 'user_id')] ?? null;
            $userPublicId = is_string($value) ? $value : null;
        } else {
            $value = DB::table('users')->where('id', $this->rowInt($row, 'user_id'))->value('public_id');
            $userPublicId = is_string($value) ? $value : null;
        }

        return [
            'public_id' => $this->rowString($row, 'public_id'),
            'user_public_id' => $userPublicId,
            'member_role' => $this->rowString($row, 'member_role'),
            'status' => $this->rowString($row, 'status'),
            'starts_on' => $this->rowString($row, 'starts_on'),
            'ends_on' => $this->nullableRowString($row, 'ends_on'),
            'scope' => $scope,
        ];
    }

    private function requirePlatformAdmin(Request $request): bool
    {
        $actor = $request->user();

        return $actor instanceof User && $actor->hasRole('platform-admin');
    }

    private function rowString(object $row, string $key): string
    {
        $value = ((array) $row)[$key] ?? '';

        return is_string($value) ? $value : (string) $value;
    }

    private function rowInt(object $row, string $key): int
    {
        $value = ((array) $row)[$key] ?? 0;

        return is_numeric($value) ? (int) $value : 0;
    }

    private function nullableRowString(object $row, string $key): ?string
    {
        $value = ((array) $row)[$key] ?? null;
        if ($value === null || $value === '') {
            return null;
        }

        return is_string($value) ? $value : (string) $value;
    }
}
