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

final class IslamicRegulatorySignoffWorkflow extends BaseController
{
    private const RESTRICTION_KEYS = ['conditions', 'prohibited_features', 'accounting_limits', 'notes'];

    public function __construct(
        private readonly SecurityAudit $securityAudit,
        private readonly IslamicProductFamilyRegistry $productFamilies,
    ) {}

    public function index(Request $request): JsonResponse
    {
        if (! $this->requirePlatformAdmin($request)) {
            return $this->respondForbidden();
        }

        $validated = Validator::make($request->all(), [
            'jurisdiction' => ['sometimes', 'nullable', Rule::in(IslamicRegulatorySignoffService::JURISDICTIONS)],
            'regulator' => ['sometimes', 'nullable', Rule::in(IslamicRegulatorySignoffService::REGULATORS)],
            'status' => ['sometimes', 'nullable', Rule::in(IslamicRegulatorySignoffService::STATUSES)],
            'approval_type' => ['sometimes', 'nullable', Rule::in(IslamicRegulatorySignoffService::APPROVAL_TYPES)],
            'linkable_type' => ['sometimes', 'nullable', Rule::in(IslamicRegulatorySignoffService::LINK_TYPES)],
            'linkable_code' => ['sometimes', 'nullable', 'string'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ])->validate();

        $query = DB::table('islamic_regulatory_signoffs as s')->select('s.*');

        $jurisdiction = isset($validated['jurisdiction']) && is_string($validated['jurisdiction']) ? $validated['jurisdiction'] : '';
        $regulator = isset($validated['regulator']) && is_string($validated['regulator']) ? $validated['regulator'] : '';
        $status = isset($validated['status']) && is_string($validated['status']) ? $validated['status'] : '';
        $approvalType = isset($validated['approval_type']) && is_string($validated['approval_type']) ? $validated['approval_type'] : '';
        $linkableType = isset($validated['linkable_type']) && is_string($validated['linkable_type']) ? $validated['linkable_type'] : '';
        $linkableCode = isset($validated['linkable_code']) && is_string($validated['linkable_code']) ? $validated['linkable_code'] : '';

        if ($jurisdiction !== '') {
            $query->where('s.jurisdiction', $jurisdiction);
        }
        if ($regulator !== '') {
            $query->where('s.regulator', $regulator);
        }
        if ($status !== '') {
            $query->where('s.status', $status);
        }
        if ($approvalType !== '') {
            $query->where('s.approval_type', $approvalType);
        }
        if ($linkableType !== '' || $linkableCode !== '') {
            $query->whereExists(function ($q) use ($linkableType, $linkableCode): void {
                $q->select(DB::raw(1))
                    ->from('islamic_regulatory_signoff_links as l')
                    ->whereColumn('l.islamic_regulatory_signoff_id', 's.id');
                if ($linkableType !== '') {
                    $q->where('l.linkable_type', $linkableType);
                }
                if ($linkableCode !== '') {
                    $q->where('l.linkable_code', $linkableCode);
                }
            });
        }

        $perPage = isset($validated['per_page']) && is_numeric($validated['per_page']) ? (int) $validated['per_page'] : 25;
        $page = isset($validated['page']) && is_numeric($validated['page']) ? (int) $validated['page'] : 1;
        $total = (clone $query)->count();
        $rows = $query->orderByDesc('s.id')->forPage($page, $perPage)->get();

        $payload = $this->bulkAssemblePayloads($rows->all());

        return $this->respondSuccess($payload, 'Regulatory sign-offs listed', meta: [
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
            $validated = $this->validatePayload($request, requireDocument: true);
            $row = DB::transaction(function () use ($validated, $actor): object {
                return $this->insertDraft($validated, $actor);
            });
        } catch (InvalidArgumentException $exception) {
            return $this->respondUnprocessable(errors: ['islamic_regulatory_signoff' => [$exception->getMessage()]]);
        }

        $this->securityAudit->record('islamic.regulatory_signoff.created', actor: $actor, properties: [
            'signoff_public_id' => $this->rowString($row, 'public_id'),
            'jurisdiction' => $this->rowString($row, 'jurisdiction'),
            'regulator' => $this->rowString($row, 'regulator'),
            'approval_type' => $this->rowString($row, 'approval_type'),
            'owner_type' => $this->rowString($row, 'owner_type'),
        ], request: $request);

        return $this->respondCreated($this->singlePayload($row), 'Regulatory sign-off draft created');
    }

    public function show(Request $request, string $publicId): JsonResponse
    {
        if (! $this->requirePlatformAdmin($request)) {
            return $this->respondForbidden();
        }

        $row = DB::table('islamic_regulatory_signoffs')->where('public_id', $publicId)->first();
        if (! is_object($row)) {
            return $this->respondNotFound('Regulatory sign-off not found.');
        }

        return $this->respondSuccess($this->singlePayload($row), 'Regulatory sign-off retrieved');
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

        $existingForDefaults = DB::table('islamic_regulatory_signoffs')->where('public_id', $publicId)->first();
        if (! is_object($existingForDefaults)) {
            return $this->respondNotFound('Regulatory sign-off not found.');
        }
        if ($this->rowString($existingForDefaults, 'status') !== 'draft') {
            return $this->respondUnprocessable(errors: ['islamic_regulatory_signoff' => ['Only draft sign-offs can be updated; active sign-offs must be suspended/revoked.']]);
        }

        try {
            $validated = $this->validatePayload($request, requireDocument: false, defaultsFrom: $existingForDefaults);
            $row = DB::transaction(function () use ($publicId, $validated): array {
                $existing = DB::table('islamic_regulatory_signoffs')->where('public_id', $publicId)->lockForUpdate()->first();
                if (! is_object($existing)) {
                    throw new InvalidArgumentException('Regulatory sign-off not found.');
                }
                if ($this->rowString($existing, 'status') !== 'draft') {
                    throw new InvalidArgumentException('Only draft sign-offs can be updated.');
                }
                $columns = $this->buildUpdateColumns($validated);
                if ($columns !== []) {
                    DB::table('islamic_regulatory_signoffs')->where('id', $this->rowInt($existing, 'id'))->update(array_merge($columns, ['updated_at' => now()]));
                }

                $updated = DB::table('islamic_regulatory_signoffs')->where('id', $this->rowInt($existing, 'id'))->first();
                if (! is_object($updated)) {
                    throw new InvalidArgumentException('Reload failed.');
                }

                return ['row' => $updated, 'changed' => array_keys($columns)];
            });
        } catch (InvalidArgumentException $exception) {
            return $this->respondUnprocessable(errors: ['islamic_regulatory_signoff' => [$exception->getMessage()]]);
        }

        $updated = $row['row'];

        $this->securityAudit->record('islamic.regulatory_signoff.updated', actor: $actor, properties: [
            'signoff_public_id' => $this->rowString($updated, 'public_id'),
            'changed_fields' => $row['changed'],
        ], request: $request);

        return $this->respondSuccess($this->singlePayload($updated), 'Regulatory sign-off updated');
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
                $row = DB::table('islamic_regulatory_signoffs')->where('public_id', $publicId)->lockForUpdate()->first();
                if (! is_object($row)) {
                    throw new InvalidArgumentException('Regulatory sign-off not found.');
                }
                if ($this->rowString($row, 'status') !== 'draft') {
                    throw new InvalidArgumentException('Only draft sign-offs can be activated.');
                }
                if ($this->rowString($row, 'approval_type') === 'deny') {
                    throw new InvalidArgumentException('A sign-off with approval_type=deny cannot be activated; create a separate denying record only as historical evidence in draft.');
                }

                $document = DB::table('documents')->where('id', $this->rowInt($row, 'document_id'))->first();
                if (! is_object($document) || $this->rowString($document, 'status') !== 'active') {
                    throw new InvalidArgumentException('Sign-off must have an active evidence document.');
                }

                $links = DB::table('islamic_regulatory_signoff_links')
                    ->where('islamic_regulatory_signoff_id', $this->rowInt($row, 'id'))
                    ->get();
                if ($links->isEmpty()) {
                    throw new InvalidArgumentException('Sign-off must have at least one link before activation.');
                }

                $hasAllow = false;
                foreach ($links as $link) {
                    if ($this->rowString($link, 'restriction_mode') === 'allow') {
                        $hasAllow = true;
                        break;
                    }
                }
                if (! $hasAllow) {
                    throw new InvalidArgumentException('Sign-off must contain at least one allow link before activation.');
                }

                DB::table('islamic_regulatory_signoffs')->where('id', $this->rowInt($row, 'id'))->update([
                    'status' => 'active',
                    'activated_by_user_id' => $actor->id,
                    'activated_at' => now(),
                    'updated_at' => now(),
                ]);

                $updated = DB::table('islamic_regulatory_signoffs')->where('id', $this->rowInt($row, 'id'))->first();
                if (! is_object($updated)) {
                    throw new InvalidArgumentException('Reload failed.');
                }

                return $updated;
            });
        } catch (InvalidArgumentException $exception) {
            return $this->respondUnprocessable(errors: ['islamic_regulatory_signoff' => [$exception->getMessage()]]);
        }

        $this->securityAudit->record('islamic.regulatory_signoff.activated', actor: $actor, properties: [
            'signoff_public_id' => $this->rowString($result, 'public_id'),
            'effective_date' => $this->rowString($result, 'effective_date'),
            'expiry_date' => $this->nullableRowString($result, 'expiry_date'),
        ], request: $request);

        return $this->respondSuccess($this->singlePayload($result), 'Regulatory sign-off activated');
    }

    public function suspend(Request $request, string $publicId): JsonResponse
    {
        return $this->statusTransition($request, $publicId, fromStatuses: ['active'], to: 'suspended', event: 'islamic.regulatory_signoff.suspended', requireReason: true);
    }

    public function revoke(Request $request, string $publicId): JsonResponse
    {
        return $this->statusTransition($request, $publicId, fromStatuses: ['active', 'suspended'], to: 'revoked', event: 'islamic.regulatory_signoff.revoked', requireReason: true);
    }

    public function retire(Request $request, string $publicId): JsonResponse
    {
        return $this->statusTransition($request, $publicId, fromStatuses: ['active', 'suspended', 'revoked', 'expired'], to: 'retired', event: 'islamic.regulatory_signoff.retired', requireReason: true);
    }

    public function link(Request $request, string $publicId): JsonResponse
    {
        if (! $this->requirePlatformAdmin($request)) {
            return $this->respondForbidden();
        }

        $actor = $request->user();
        if (! $actor instanceof User) {
            return $this->respondForbidden();
        }

        $validated = Validator::make($request->all(), [
            'linkable_type' => ['required', Rule::in(IslamicRegulatorySignoffService::LINK_TYPES)],
            'linkable_code' => ['required', 'string', 'max:128'],
            'restriction_mode' => ['sometimes', 'nullable', Rule::in(IslamicRegulatorySignoffService::RESTRICTION_MODES)],
        ])->validate();

        $type = (string) $validated['linkable_type'];
        $code = (string) $validated['linkable_code'];
        $mode = isset($validated['restriction_mode']) && is_string($validated['restriction_mode']) ? $validated['restriction_mode'] : 'allow';

        if ($type === 'product_family' && $this->productFamilies->familyKindFor($code) !== 'financing') {
            return $this->respondUnprocessable(errors: ['islamic_regulatory_signoff_link' => ['Unknown product family code.']]);
        }
        if ($type === 'account_type' && $this->productFamilies->familyKindFor($code) !== 'account') {
            return $this->respondUnprocessable(errors: ['islamic_regulatory_signoff_link' => ['Unknown account type code.']]);
        }

        try {
            $linkRow = DB::transaction(function () use ($publicId, $type, $code, $mode, $actor): object {
                $signoff = DB::table('islamic_regulatory_signoffs')->where('public_id', $publicId)->lockForUpdate()->first();
                if (! is_object($signoff)) {
                    throw new InvalidArgumentException('Regulatory sign-off not found.');
                }
                if ($this->rowString($signoff, 'status') !== 'draft') {
                    throw new InvalidArgumentException('Only draft sign-offs can be linked.');
                }

                $duplicate = DB::table('islamic_regulatory_signoff_links')
                    ->where('islamic_regulatory_signoff_id', $this->rowInt($signoff, 'id'))
                    ->where('linkable_type', $type)
                    ->where('linkable_code', $code)
                    ->exists();
                if ($duplicate) {
                    throw new InvalidArgumentException('This link target is already attached to the sign-off.');
                }

                $id = DB::table('islamic_regulatory_signoff_links')->insertGetId([
                    'public_id' => (string) Str::ulid(),
                    'islamic_regulatory_signoff_id' => $this->rowInt($signoff, 'id'),
                    'linkable_type' => $type,
                    'linkable_code' => $code,
                    'restriction_mode' => $mode,
                    'created_by_user_id' => $actor->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $row = DB::table('islamic_regulatory_signoff_links')->where('id', $id)->first();
                if (! is_object($row)) {
                    throw new InvalidArgumentException('Link reload failed.');
                }

                return $row;
            });
        } catch (InvalidArgumentException $exception) {
            return $this->respondUnprocessable(errors: ['islamic_regulatory_signoff_link' => [$exception->getMessage()]]);
        }

        $signoffPublicId = DB::table('islamic_regulatory_signoffs')
            ->where('id', $this->rowInt($linkRow, 'islamic_regulatory_signoff_id'))
            ->value('public_id');

        $this->securityAudit->record('islamic.regulatory_signoff.linked', actor: $actor, properties: [
            'signoff_public_id' => is_string($signoffPublicId) ? $signoffPublicId : '',
            'linkable_type' => $this->rowString($linkRow, 'linkable_type'),
            'linkable_code' => $this->rowString($linkRow, 'linkable_code'),
            'restriction_mode' => $this->rowString($linkRow, 'restriction_mode'),
        ], request: $request);

        return $this->respondCreated($this->linkPayload($linkRow), 'Sign-off link created');
    }

    public function unlink(Request $request, string $publicId): JsonResponse
    {
        if (! $this->requirePlatformAdmin($request)) {
            return $this->respondForbidden();
        }

        $actor = $request->user();
        if (! $actor instanceof User) {
            return $this->respondForbidden();
        }

        $validated = Validator::make($request->all(), [
            'linkable_type' => ['required', Rule::in(IslamicRegulatorySignoffService::LINK_TYPES)],
            'linkable_code' => ['required', 'string', 'max:128'],
        ])->validate();

        try {
            $result = DB::transaction(function () use ($publicId, $validated): array {
                $signoff = DB::table('islamic_regulatory_signoffs')->where('public_id', $publicId)->lockForUpdate()->first();
                if (! is_object($signoff)) {
                    throw new InvalidArgumentException('Regulatory sign-off not found.');
                }
                if ($this->rowString($signoff, 'status') !== 'draft') {
                    throw new InvalidArgumentException('Only draft sign-offs can be unlinked.');
                }

                $deleted = DB::table('islamic_regulatory_signoff_links')
                    ->where('islamic_regulatory_signoff_id', $this->rowInt($signoff, 'id'))
                    ->where('linkable_type', (string) $validated['linkable_type'])
                    ->where('linkable_code', (string) $validated['linkable_code'])
                    ->delete();
                if ($deleted === 0) {
                    throw new InvalidArgumentException('Link not found on this sign-off.');
                }

                return [
                    'signoff_public_id' => $this->rowString($signoff, 'public_id'),
                    'linkable_type' => (string) $validated['linkable_type'],
                    'linkable_code' => (string) $validated['linkable_code'],
                ];
            });
        } catch (InvalidArgumentException $exception) {
            return $this->respondUnprocessable(errors: ['islamic_regulatory_signoff_link' => [$exception->getMessage()]]);
        }

        $this->securityAudit->record('islamic.regulatory_signoff.unlinked', actor: $actor, properties: $result, request: $request);

        return $this->respondSuccess($result, 'Sign-off link removed');
    }

    /**
     * @param  array<int, string>  $fromStatuses
     */
    private function statusTransition(Request $request, string $publicId, array $fromStatuses, string $to, string $event, bool $requireReason): JsonResponse
    {
        if (! $this->requirePlatformAdmin($request)) {
            return $this->respondForbidden();
        }

        $actor = $request->user();
        if (! $actor instanceof User) {
            return $this->respondForbidden();
        }

        $validated = Validator::make($request->all(), [
            'reason' => [$requireReason ? 'required' : 'sometimes', 'nullable', 'string', 'max:4000'],
        ])->validate();
        $reason = isset($validated['reason']) && is_string($validated['reason']) ? $validated['reason'] : '';

        try {
            $result = DB::transaction(function () use ($publicId, $fromStatuses, $to, $reason, $actor): array {
                $row = DB::table('islamic_regulatory_signoffs')->where('public_id', $publicId)->lockForUpdate()->first();
                if (! is_object($row)) {
                    throw new InvalidArgumentException('Regulatory sign-off not found.');
                }
                $previous = $this->rowString($row, 'status');
                if (! in_array($previous, $fromStatuses, true)) {
                    throw new InvalidArgumentException('Sign-off status '.$previous.' cannot transition to '.$to.'.');
                }

                $update = [
                    'status' => $to,
                    'updated_at' => now(),
                ];
                if ($to === 'retired') {
                    $update['retired_by_user_id'] = $actor->id;
                    $update['retired_at'] = now();
                    $update['retirement_reason'] = $reason;
                }

                DB::table('islamic_regulatory_signoffs')->where('id', $this->rowInt($row, 'id'))->update($update);

                $updated = DB::table('islamic_regulatory_signoffs')->where('id', $this->rowInt($row, 'id'))->first();
                if (! is_object($updated)) {
                    throw new InvalidArgumentException('Reload failed.');
                }

                return ['row' => $updated, 'previous' => $previous];
            });
        } catch (InvalidArgumentException $exception) {
            return $this->respondUnprocessable(errors: ['islamic_regulatory_signoff' => [$exception->getMessage()]]);
        }

        $updated = $result['row'];
        $this->securityAudit->record($event, actor: $actor, properties: [
            'signoff_public_id' => $this->rowString($updated, 'public_id'),
            'previous_status' => $result['previous'],
            'new_status' => $to,
            'reason' => $reason,
        ], request: $request);

        return $this->respondSuccess($this->singlePayload($updated), 'Regulatory sign-off '.$to);
    }

    /**
     * @return array{
     *   jurisdiction: string,
     *   regulator: string,
     *   opinion_reference: string,
     *   opinion_summary: string,
     *   approval_type: string,
     *   restrictions: array<string, mixed>|null,
     *   accounting_implications: string|null,
     *   owner_type: string,
     *   owner_user_id: int|null,
     *   owner_role: string|null,
     *   owner_department: string|null,
     *   owner_committee: string|null,
     *   approved_on: string,
     *   effective_date: string,
     *   expiry_date: string|null,
     *   document_id: int|null,
     *   metadata: array<array-key, mixed>|null,
     * }
     */
    private function validatePayload(Request $request, bool $requireDocument, ?object $defaultsFrom = null): array
    {
        $hasDefaults = $defaultsFrom !== null;
        $rules = [
            'jurisdiction' => [$hasDefaults ? 'sometimes' : 'required', Rule::in(IslamicRegulatorySignoffService::JURISDICTIONS)],
            'regulator' => [$hasDefaults ? 'sometimes' : 'required', Rule::in(IslamicRegulatorySignoffService::REGULATORS)],
            'opinion_reference' => [$hasDefaults ? 'sometimes' : 'required', 'string', 'max:191'],
            'opinion_summary' => [$hasDefaults ? 'sometimes' : 'required', 'string', 'max:8000'],
            'approval_type' => [$hasDefaults ? 'sometimes' : 'required', Rule::in(IslamicRegulatorySignoffService::APPROVAL_TYPES)],
            'restrictions' => ['sometimes', 'nullable', 'array'],
            'accounting_implications' => ['sometimes', 'nullable', 'string', 'max:8000'],
            'owner_type' => [$hasDefaults ? 'sometimes' : 'required', Rule::in(['user', 'role', 'department', 'committee'])],
            'owner_user_public_id' => ['sometimes', 'nullable', 'string', 'exists:users,public_id'],
            'owner_role' => ['sometimes', 'nullable', 'string', 'max:128'],
            'owner_department' => ['sometimes', 'nullable', 'string', 'max:128'],
            'owner_committee' => ['sometimes', 'nullable', 'string', 'max:128'],
            'approved_on' => [$hasDefaults ? 'sometimes' : 'required', 'date'],
            'effective_date' => [$hasDefaults ? 'sometimes' : 'required', 'date'],
            'expiry_date' => ['sometimes', 'nullable', 'date'],
            'document_public_id' => [$requireDocument ? 'required' : 'sometimes', 'nullable', 'string'],
            'metadata' => ['sometimes', 'nullable', 'array'],
        ];

        $validated = Validator::make($request->all(), $rules)->validate();

        if (isset($validated['restrictions']) && is_array($validated['restrictions'])) {
            foreach ($validated['restrictions'] as $key => $_value) {
                if (! is_string($key) || ! in_array($key, self::RESTRICTION_KEYS, true)) {
                    throw new InvalidArgumentException('restrictions may only contain keys: '.implode(', ', self::RESTRICTION_KEYS).'.');
                }
            }
        }

        $approvalType = isset($validated['approval_type']) && is_string($validated['approval_type'])
            ? $validated['approval_type']
            : ($defaultsFrom !== null ? $this->rowString($defaultsFrom, 'approval_type') : '');
        $accountingImplications = array_key_exists('accounting_implications', $validated)
            ? $this->nullableString($validated['accounting_implications'])
            : ($defaultsFrom !== null ? $this->nullableRowString($defaultsFrom, 'accounting_implications') : null);

        // Plan: accounting_implications is required only when approval_type=allow_with_conditions
        // AND restrictions actually reference accounting controls (the accounting_limits key).
        // Check the MERGED state (request overlay on persisted defaults), not just the payload,
        // so updateDraft cannot leave the row in a state that violates this invariant.
        $mergedRestrictions = $this->resolveMergedRestrictions($validated, $defaultsFrom);
        $restrictionsTouchAccounting = $mergedRestrictions !== null
            && array_key_exists('accounting_limits', $mergedRestrictions);
        if ($approvalType === 'allow_with_conditions'
            && $restrictionsTouchAccounting
            && ($accountingImplications === null || $accountingImplications === '')) {
            throw new InvalidArgumentException('accounting_implications is required when approval_type is allow_with_conditions and restrictions reference accounting controls.');
        }

        $ownerType = isset($validated['owner_type']) && is_string($validated['owner_type'])
            ? $validated['owner_type']
            : ($defaultsFrom !== null ? $this->rowString($defaultsFrom, 'owner_type') : '');
        if ($ownerType === '') {
            throw new InvalidArgumentException('Owner type is required.');
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

        [$ownerUserId, $ownerRole, $ownerDepartment, $ownerCommittee] = $this->resolveOwnerIdentity($ownerType, $validated, $defaultsFrom);

        $jurisdiction = isset($validated['jurisdiction']) && is_string($validated['jurisdiction']) ? $validated['jurisdiction'] : ($defaultsFrom !== null ? $this->rowString($defaultsFrom, 'jurisdiction') : '');
        $regulator = isset($validated['regulator']) && is_string($validated['regulator']) ? $validated['regulator'] : ($defaultsFrom !== null ? $this->rowString($defaultsFrom, 'regulator') : '');
        $opinionReference = isset($validated['opinion_reference']) && is_string($validated['opinion_reference']) ? $validated['opinion_reference'] : ($defaultsFrom !== null ? $this->rowString($defaultsFrom, 'opinion_reference') : '');
        $opinionSummary = isset($validated['opinion_summary']) && is_string($validated['opinion_summary']) ? $validated['opinion_summary'] : ($defaultsFrom !== null ? $this->rowString($defaultsFrom, 'opinion_summary') : '');
        $approvedOn = isset($validated['approved_on']) && is_string($validated['approved_on']) ? $validated['approved_on'] : ($defaultsFrom !== null ? $this->rowString($defaultsFrom, 'approved_on') : '');
        $effectiveDate = isset($validated['effective_date']) && is_string($validated['effective_date']) ? $validated['effective_date'] : ($defaultsFrom !== null ? $this->rowString($defaultsFrom, 'effective_date') : '');
        $expiryDate = array_key_exists('expiry_date', $validated) ? $this->nullableString($validated['expiry_date']) : ($defaultsFrom !== null ? $this->nullableRowString($defaultsFrom, 'expiry_date') : null);

        if ($expiryDate !== null && $effectiveDate !== '' && $expiryDate <= $effectiveDate) {
            throw new InvalidArgumentException('expiry_date must be after effective_date.');
        }

        /** @var array<string, mixed>|null $restrictions */
        $restrictions = isset($validated['restrictions']) && is_array($validated['restrictions']) ? $validated['restrictions'] : null;

        return [
            'jurisdiction' => $jurisdiction,
            'regulator' => $regulator,
            'opinion_reference' => $opinionReference,
            'opinion_summary' => $opinionSummary,
            'approval_type' => $approvalType,
            'restrictions' => $restrictions,
            'accounting_implications' => $accountingImplications,
            'owner_type' => $ownerType,
            'owner_user_id' => $ownerUserId,
            'owner_role' => $ownerRole,
            'owner_department' => $ownerDepartment,
            'owner_committee' => $ownerCommittee,
            'approved_on' => $approvedOn,
            'effective_date' => $effectiveDate,
            'expiry_date' => $expiryDate,
            'document_id' => $documentId,
            'metadata' => isset($validated['metadata']) && is_array($validated['metadata']) ? $validated['metadata'] : null,
        ];
    }

    /**
     * Compute the restrictions array that will be persisted after this request,
     * taking into account that updateDraft preserves existing restrictions when
     * the payload omits the key.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>|null
     */
    private function resolveMergedRestrictions(array $validated, ?object $defaultsFrom): ?array
    {
        $source = null;
        if (isset($validated['restrictions']) && is_array($validated['restrictions'])) {
            $source = $validated['restrictions'];
        } elseif ($defaultsFrom !== null) {
            $raw = ((array) $defaultsFrom)['restrictions'] ?? null;
            if (is_string($raw) && $raw !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $source = $decoded;
                }
            }
        }

        if ($source === null) {
            return null;
        }

        $stringKeyed = [];
        foreach ($source as $key => $value) {
            if (is_string($key)) {
                $stringKeyed[$key] = $value;
            }
        }

        return $stringKeyed;
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array{0: int|null, 1: string|null, 2: string|null, 3: string|null}
     */
    private function resolveOwnerIdentity(string $ownerType, array $validated, ?object $defaultsFrom): array
    {
        $ownerUserId = null;
        $ownerRole = null;
        $ownerDepartment = null;
        $ownerCommittee = null;

        if ($ownerType === 'user') {
            $publicId = isset($validated['owner_user_public_id']) && is_string($validated['owner_user_public_id']) ? $validated['owner_user_public_id'] : null;
            if ($publicId === null) {
                if ($defaultsFrom !== null && $this->rowString($defaultsFrom, 'owner_type') === 'user') {
                    $ownerUserId = $this->rowNullableInt($defaultsFrom, 'owner_user_id');
                }
                if ($ownerUserId === null) {
                    throw new InvalidArgumentException('owner_user_public_id is required when owner_type is user.');
                }
            } else {
                $user = DB::table('users')->where('public_id', $publicId)->first(['id']);
                if (! is_object($user)) {
                    throw new InvalidArgumentException('Owner user not found.');
                }
                $ownerUserId = $this->rowInt($user, 'id');
            }
        } elseif ($ownerType === 'role') {
            $ownerRole = isset($validated['owner_role']) && is_string($validated['owner_role']) && $validated['owner_role'] !== ''
                ? $validated['owner_role']
                : ($defaultsFrom !== null ? $this->nullableRowString($defaultsFrom, 'owner_role') : null);
            if ($ownerRole === null || $ownerRole === '') {
                throw new InvalidArgumentException('owner_role is required when owner_type is role.');
            }
        } elseif ($ownerType === 'department') {
            $ownerDepartment = isset($validated['owner_department']) && is_string($validated['owner_department']) && $validated['owner_department'] !== ''
                ? $validated['owner_department']
                : ($defaultsFrom !== null ? $this->nullableRowString($defaultsFrom, 'owner_department') : null);
            if ($ownerDepartment === null || $ownerDepartment === '') {
                throw new InvalidArgumentException('owner_department is required when owner_type is department.');
            }
        } elseif ($ownerType === 'committee') {
            $ownerCommittee = isset($validated['owner_committee']) && is_string($validated['owner_committee']) && $validated['owner_committee'] !== ''
                ? $validated['owner_committee']
                : ($defaultsFrom !== null ? $this->nullableRowString($defaultsFrom, 'owner_committee') : null);
            if ($ownerCommittee === null || $ownerCommittee === '') {
                throw new InvalidArgumentException('owner_committee is required when owner_type is committee.');
            }
        }

        return [$ownerUserId, $ownerRole, $ownerDepartment, $ownerCommittee];
    }

    /**
     * @param  array{
     *   jurisdiction: string,
     *   regulator: string,
     *   opinion_reference: string,
     *   opinion_summary: string,
     *   approval_type: string,
     *   restrictions: array<string, mixed>|null,
     *   accounting_implications: string|null,
     *   owner_type: string,
     *   owner_user_id: int|null,
     *   owner_role: string|null,
     *   owner_department: string|null,
     *   owner_committee: string|null,
     *   approved_on: string,
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

        $id = DB::table('islamic_regulatory_signoffs')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'jurisdiction' => $validated['jurisdiction'],
            'regulator' => $validated['regulator'],
            'opinion_reference' => $validated['opinion_reference'],
            'opinion_summary' => $validated['opinion_summary'],
            'approval_type' => $validated['approval_type'],
            'restrictions' => $validated['restrictions'] !== null
                ? json_encode($validated['restrictions'], JSON_THROW_ON_ERROR)
                : null,
            'accounting_implications' => $validated['accounting_implications'],
            'owner_type' => $validated['owner_type'],
            'owner_user_id' => $validated['owner_user_id'],
            'owner_role' => $validated['owner_role'],
            'owner_department' => $validated['owner_department'],
            'owner_committee' => $validated['owner_committee'],
            'approved_on' => $validated['approved_on'],
            'effective_date' => $validated['effective_date'],
            'expiry_date' => $validated['expiry_date'],
            'status' => 'draft',
            'document_id' => $validated['document_id'],
            'created_by_user_id' => $actor->id,
            'metadata' => $validated['metadata'] !== null
                ? json_encode($validated['metadata'], JSON_THROW_ON_ERROR)
                : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $row = DB::table('islamic_regulatory_signoffs')->where('id', $id)->first();
        if (! is_object($row)) {
            throw new InvalidArgumentException('Sign-off reload failed.');
        }

        return $row;
    }

    /**
     * @param  array{
     *   jurisdiction: string,
     *   regulator: string,
     *   opinion_reference: string,
     *   opinion_summary: string,
     *   approval_type: string,
     *   restrictions: array<string, mixed>|null,
     *   accounting_implications: string|null,
     *   owner_type: string,
     *   owner_user_id: int|null,
     *   owner_role: string|null,
     *   owner_department: string|null,
     *   owner_committee: string|null,
     *   approved_on: string,
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
        foreach (['jurisdiction', 'regulator', 'opinion_reference', 'opinion_summary', 'approval_type', 'approved_on', 'effective_date'] as $key) {
            if ($validated[$key] !== '') {
                $update[$key] = $validated[$key];
            }
        }
        $update['expiry_date'] = $validated['expiry_date'];
        $update['accounting_implications'] = $validated['accounting_implications'];
        if ($validated['owner_type'] !== '') {
            $update['owner_type'] = $validated['owner_type'];
            $update['owner_user_id'] = $validated['owner_user_id'];
            $update['owner_role'] = $validated['owner_role'];
            $update['owner_department'] = $validated['owner_department'];
            $update['owner_committee'] = $validated['owner_committee'];
        }
        if (is_int($validated['document_id'])) {
            $update['document_id'] = $validated['document_id'];
        }
        if ($validated['restrictions'] !== null) {
            $update['restrictions'] = json_encode($validated['restrictions'], JSON_THROW_ON_ERROR);
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

        $signoffIds = [];
        $documentIds = [];
        $userIds = [];
        foreach ($rows as $row) {
            $signoffIds[] = $this->rowInt($row, 'id');
            $documentIds[] = $this->rowInt($row, 'document_id');
            $owner = $this->rowNullableInt($row, 'owner_user_id');
            if ($owner !== null) {
                $userIds[] = $owner;
            }
        }

        $documentPublicIds = DB::table('documents')->whereIn('id', array_unique($documentIds))->pluck('public_id', 'id');
        $userPublicIds = $userIds !== []
            ? DB::table('users')->whereIn('id', array_unique($userIds))->pluck('public_id', 'id')
            : collect();

        /** @var array<int, array<int, array<string, mixed>>> $linksBySignoff */
        $linksBySignoff = [];
        $linkRows = DB::table('islamic_regulatory_signoff_links')->whereIn('islamic_regulatory_signoff_id', $signoffIds)->orderBy('id')->get();
        foreach ($linkRows as $link) {
            $sid = $this->rowInt($link, 'islamic_regulatory_signoff_id');
            $linksBySignoff[$sid] ??= [];
            $linksBySignoff[$sid][] = $this->linkPayload($link);
        }

        $payloads = [];
        foreach ($rows as $row) {
            $documentValue = $documentPublicIds[$this->rowInt($row, 'document_id')] ?? null;
            $payloads[] = $this->buildPayload(
                $row,
                $linksBySignoff[$this->rowInt($row, 'id')] ?? [],
                is_string($documentValue) ? $documentValue : null,
                $this->lookupOwnerLabel($row, $userPublicIds),
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
     * @param  array<int, array<string, mixed>>  $linksPayload
     * @return array<string, mixed>
     */
    private function buildPayload(object $row, array $linksPayload, ?string $documentPublicId, string $ownerLabel): array
    {
        $restrictions = null;
        $raw = ((array) $row)['restrictions'] ?? null;
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $restrictions = $decoded;
            }
        }

        return [
            'public_id' => $this->rowString($row, 'public_id'),
            'jurisdiction' => $this->rowString($row, 'jurisdiction'),
            'regulator' => $this->rowString($row, 'regulator'),
            'opinion_reference' => $this->rowString($row, 'opinion_reference'),
            'opinion_summary' => $this->rowString($row, 'opinion_summary'),
            'approval_type' => $this->rowString($row, 'approval_type'),
            'restrictions' => $restrictions,
            'accounting_implications' => $this->nullableRowString($row, 'accounting_implications'),
            'owner' => [
                'type' => $this->rowString($row, 'owner_type'),
                'label' => $ownerLabel,
            ],
            'approved_on' => $this->rowString($row, 'approved_on'),
            'effective_date' => $this->rowString($row, 'effective_date'),
            'expiry_date' => $this->nullableRowString($row, 'expiry_date'),
            'status' => $this->rowString($row, 'status'),
            'document_public_id' => $documentPublicId,
            'links' => $linksPayload,
            'created_at' => $this->nullableRowString($row, 'created_at'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function linkPayload(object $row): array
    {
        return [
            'public_id' => $this->rowString($row, 'public_id'),
            'type' => $this->rowString($row, 'linkable_type'),
            'code' => $this->rowString($row, 'linkable_code'),
            'restriction_mode' => $this->rowString($row, 'restriction_mode'),
        ];
    }

    /**
     * @param  Collection<int, mixed>  $userPublicIds
     */
    private function lookupOwnerLabel(object $row, Collection $userPublicIds): string
    {
        $type = $this->rowString($row, 'owner_type');
        if ($type === 'user') {
            $userId = $this->rowNullableInt($row, 'owner_user_id');
            if ($userId !== null) {
                $value = $userPublicIds[$userId] ?? null;
                if (is_string($value)) {
                    return $value;
                }
            }

            return '';
        }
        if ($type === 'role') {
            return $this->nullableRowString($row, 'owner_role') ?? '';
        }
        if ($type === 'department') {
            return $this->nullableRowString($row, 'owner_department') ?? '';
        }
        if ($type === 'committee') {
            return $this->nullableRowString($row, 'owner_committee') ?? '';
        }

        return '';
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

    private function rowNullableInt(object $row, string $key): ?int
    {
        $value = ((array) $row)[$key] ?? null;

        return is_numeric($value) ? (int) $value : null;
    }

    private function nullableRowString(object $row, string $key): ?string
    {
        $value = ((array) $row)[$key] ?? null;
        if ($value === null || $value === '') {
            return null;
        }

        return is_string($value) ? $value : (string) $value;
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_scalar($value) ? (string) $value : null;
    }
}
