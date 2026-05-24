<?php

declare(strict_types=1);

namespace App\Application\IslamicFinance;

use App\Http\Controllers\BaseController;
use App\Models\User;
use App\Support\Security\SecurityAudit;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use stdClass;

final class IslamicStandardWorkflow extends BaseController
{
    private const RESERVED_CONTRACT_TEMPLATE_CODES = [
        'mourabaha_contract_template',
        'ijara_contract_template',
        'ijara_wa_iqtina_contract_template',
        'salam_contract_template',
        'istisnaa_contract_template',
        'moudaraba_contract_template',
        'moucharaka_contract_template',
    ];

    private const RESERVED_SCREENING_POLICY_CODES = [
        'islamic_general_screening_policy',
        'islamic_equity_screening_policy',
        'islamic_revenue_screening_policy',
    ];

    public function __construct(
        private readonly SecurityAudit $securityAudit,
    ) {}

    public function index(Request $request): JsonResponse
    {
        if (! $this->requirePlatformAdmin($request)) {
            return $this->respondForbidden();
        }

        $validated = Validator::make($request->all(), [
            'source' => ['sometimes', 'nullable', 'string'],
            'status' => ['sometimes', 'nullable', 'string'],
            'linkable_type' => ['sometimes', 'nullable', Rule::in(IslamicStandardsBaselineService::LINK_TYPES)],
            'linkable_code' => ['sometimes', 'nullable', 'string'],
            'owner_type' => ['sometimes', 'nullable', 'string'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ])->validate();

        $query = DB::table('islamic_standards as s')->select('s.*');

        $source = isset($validated['source']) && is_string($validated['source']) ? $validated['source'] : '';
        $status = isset($validated['status']) && is_string($validated['status']) ? $validated['status'] : '';
        $ownerTypeFilter = isset($validated['owner_type']) && is_string($validated['owner_type']) ? $validated['owner_type'] : '';
        $linkableType = isset($validated['linkable_type']) && is_string($validated['linkable_type']) ? $validated['linkable_type'] : '';
        $linkableCode = isset($validated['linkable_code']) && is_string($validated['linkable_code']) ? $validated['linkable_code'] : '';

        if ($source !== '') {
            $query->where('s.source', $source);
        }
        if ($status !== '') {
            $query->where('s.status', $status);
        }
        if ($ownerTypeFilter !== '') {
            $query->where('s.owner_type', $ownerTypeFilter);
        }
        if ($linkableType !== '') {
            $query->whereExists(function ($q) use ($linkableType, $linkableCode): void {
                $q->select(DB::raw(1))
                    ->from('islamic_standard_links as l')
                    ->whereColumn('l.islamic_standard_id', 's.id')
                    ->where('l.linkable_type', $linkableType);
                if ($linkableCode !== '') {
                    $q->where('l.linkable_code', $linkableCode);
                }
            });
        } elseif ($linkableCode !== '') {
            $query->whereExists(function ($q) use ($linkableCode): void {
                $q->select(DB::raw(1))
                    ->from('islamic_standard_links as l')
                    ->whereColumn('l.islamic_standard_id', 's.id')
                    ->where('l.linkable_code', $linkableCode);
            });
        }

        $perPage = isset($validated['per_page']) && is_numeric($validated['per_page']) ? (int) $validated['per_page'] : 25;
        $page = isset($validated['page']) && is_numeric($validated['page']) ? (int) $validated['page'] : 1;
        $total = (clone $query)->count();
        $rows = $query->orderByDesc('s.id')->forPage($page, $perPage)->get();

        $payload = $this->bulkAssemblePayloads($rows->all());

        return $this->respondSuccess($payload, 'Islamic standards listed', meta: [
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
            $validated = $this->validateStandardPayload($request, requireDocument: true);
            $row = DB::transaction(function () use ($validated, $actor): object {
                return $this->insertDraftStandard($validated, $actor, supersedesId: null);
            });
        } catch (InvalidArgumentException $exception) {
            return $this->respondUnprocessable(errors: ['islamic_standard' => [$exception->getMessage()]]);
        }

        $this->securityAudit->record('islamic.standard.created', actor: $actor, properties: [
            'standard_public_id' => $this->rowString($row, 'public_id'),
            'source' => $this->rowString($row, 'source'),
            'reference' => $this->rowString($row, 'reference'),
            'owner_type' => $this->rowString($row, 'owner_type'),
            'document_public_id' => $this->documentPublicId($this->rowInt($row, 'document_id')),
        ], request: $request);

        return $this->respondCreated($this->standardPayload($row), 'Islamic standard draft created');
    }

    public function show(Request $request, string $publicId): JsonResponse
    {
        if (! $this->requirePlatformAdmin($request)) {
            return $this->respondForbidden();
        }

        $row = DB::table('islamic_standards')->where('public_id', $publicId)->first();
        if (! is_object($row)) {
            return $this->respondNotFound('Islamic standard not found.');
        }

        return $this->respondSuccess($this->standardPayload($row), 'Islamic standard retrieved');
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

        $existingForDefaults = DB::table('islamic_standards')->where('public_id', $publicId)->first();
        if (! is_object($existingForDefaults)) {
            return $this->respondNotFound('Islamic standard not found.');
        }

        try {
            $validated = $this->validateStandardPayload($request, requireDocument: false, defaultsFrom: $existingForDefaults);
            $row = DB::transaction(function () use ($publicId, $validated): array {
                $existing = DB::table('islamic_standards')->where('public_id', $publicId)->lockForUpdate()->first();
                if (! is_object($existing)) {
                    throw new InvalidArgumentException('Islamic standard not found.');
                }
                if ($this->rowString($existing, 'status') !== 'draft') {
                    throw new InvalidArgumentException('Only draft standards can be updated; active standards must be amended.');
                }

                $update = $this->buildUpdateColumns($validated);
                $before = (array) $existing;

                if ($update !== []) {
                    DB::table('islamic_standards')->where('id', $this->rowInt($existing, 'id'))->update(array_merge($update, [
                        'updated_at' => now(),
                    ]));
                }

                $updated = DB::table('islamic_standards')->where('id', $this->rowInt($existing, 'id'))->first();
                if (! is_object($updated)) {
                    throw new InvalidArgumentException('Islamic standard could not be reloaded.');
                }

                return ['row' => $updated, 'before' => $before, 'changed' => array_keys($update)];
            });
        } catch (InvalidArgumentException $exception) {
            return $this->respondUnprocessable(errors: ['islamic_standard' => [$exception->getMessage()]]);
        }

        $updated = $row['row'];

        $this->securityAudit->record('islamic.standard.updated', actor: $actor, properties: [
            'standard_public_id' => $this->rowString($updated, 'public_id'),
            'changed_fields' => $row['changed'],
        ], request: $request);

        return $this->respondSuccess($this->standardPayload($updated), 'Islamic standard updated');
    }

    public function amend(Request $request, string $publicId): JsonResponse
    {
        if (! $this->requirePlatformAdmin($request)) {
            return $this->respondForbidden();
        }

        $actor = $request->user();
        if (! $actor instanceof User) {
            return $this->respondForbidden();
        }

        try {
            $result = DB::transaction(function () use ($request, $publicId, $actor): array {
                $source = DB::table('islamic_standards')->where('public_id', $publicId)->lockForUpdate()->first();
                if (! is_object($source)) {
                    throw new InvalidArgumentException('Source standard not found.');
                }
                $sourceStatus = $this->rowString($source, 'status');
                if (! in_array($sourceStatus, ['active', 'expired'], true)) {
                    throw new InvalidArgumentException('Only active or expired standards can be amended.');
                }

                $validated = $this->validateStandardPayload($request, requireDocument: false, defaultsFrom: $source);
                $newRow = $this->insertDraftStandard($validated, $actor, supersedesId: $this->rowInt($source, 'id'));

                if (! $request->has('links')) {
                    $links = DB::table('islamic_standard_links')
                        ->where('islamic_standard_id', $this->rowInt($source, 'id'))
                        ->get();
                    foreach ($links as $link) {
                        DB::table('islamic_standard_links')->insert([
                            'public_id' => (string) Str::ulid(),
                            'islamic_standard_id' => $this->rowInt($newRow, 'id'),
                            'linkable_type' => $this->rowString($link, 'linkable_type'),
                            'linkable_code' => $this->rowString($link, 'linkable_code'),
                            'metadata' => isset(((array) $link)['metadata']) ? ((array) $link)['metadata'] : null,
                            'created_by_user_id' => $actor->id,
                            'created_at' => now(),
                        ]);
                    }
                }

                return ['source' => $source, 'new' => $newRow];
            });
        } catch (InvalidArgumentException $exception) {
            return $this->respondUnprocessable(errors: ['islamic_standard' => [$exception->getMessage()]]);
        }

        $this->securityAudit->record('islamic.standard.amended', actor: $actor, properties: [
            'old_standard_public_id' => $this->rowString($result['source'], 'public_id'),
            'new_standard_public_id' => $this->rowString($result['new'], 'public_id'),
        ], request: $request);

        return $this->respondCreated($this->standardPayload($result['new']), 'Islamic standard amendment drafted');
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
            $result = DB::transaction(function () use ($publicId, $actor): array {
                $standard = DB::table('islamic_standards')->where('public_id', $publicId)->lockForUpdate()->first();
                if (! is_object($standard)) {
                    throw new InvalidArgumentException('Islamic standard not found.');
                }
                if ($this->rowString($standard, 'status') !== 'draft') {
                    throw new InvalidArgumentException('Only draft standards can be activated.');
                }

                $document = DB::table('documents')->where('id', $this->rowInt($standard, 'document_id'))->first();
                if (! is_object($document) || $this->rowString($document, 'status') !== 'active') {
                    throw new InvalidArgumentException('Standard must have an active evidence document to be activated.');
                }

                $links = DB::table('islamic_standard_links')
                    ->where('islamic_standard_id', $this->rowInt($standard, 'id'))
                    ->get();
                if ($links->isEmpty()) {
                    throw new InvalidArgumentException('Standard must have at least one valid link before activation.');
                }
                $hasFamilyOrAccountLink = false;
                foreach ($links as $link) {
                    if (in_array($this->rowString($link, 'linkable_type'), ['product_family', 'account_type'], true)) {
                        $hasFamilyOrAccountLink = true;
                        break;
                    }
                }
                if (! $hasFamilyOrAccountLink) {
                    throw new InvalidArgumentException('Standard must link to at least one product family or account type before activation.');
                }

                DB::table('islamic_standards')->where('id', $this->rowInt($standard, 'id'))->update([
                    'status' => 'active',
                    'activated_by_user_id' => $actor->id,
                    'activated_at' => now(),
                    'updated_at' => now(),
                ]);

                $supersededRow = null;
                $supersedesId = $this->rowNullableInt($standard, 'supersedes_standard_id');
                $effectiveDate = $this->rowString($standard, 'effective_date');
                $today = CarbonImmutable::now()->toDateString();

                if ($supersedesId !== null && $effectiveDate !== '' && $effectiveDate <= $today) {
                    $predecessor = DB::table('islamic_standards')->where('id', $supersedesId)->lockForUpdate()->first();
                    if (is_object($predecessor) && $this->rowString($predecessor, 'status') === 'active') {
                        DB::table('islamic_standards')->where('id', $supersedesId)->update([
                            'status' => 'superseded',
                            'updated_at' => now(),
                        ]);
                        $supersededRow = DB::table('islamic_standards')->where('id', $supersedesId)->first();
                    }
                }

                $updated = DB::table('islamic_standards')->where('id', $this->rowInt($standard, 'id'))->first();
                if (! is_object($updated)) {
                    throw new InvalidArgumentException('Standard could not be reloaded.');
                }

                return ['standard' => $updated, 'links_count' => $links->count(), 'superseded' => $supersededRow];
            });
        } catch (InvalidArgumentException $exception) {
            return $this->respondUnprocessable(errors: ['islamic_standard' => [$exception->getMessage()]]);
        }

        $updated = $result['standard'];

        $this->securityAudit->record('islamic.standard.activated', actor: $actor, properties: [
            'standard_public_id' => $this->rowString($updated, 'public_id'),
            'source' => $this->rowString($updated, 'source'),
            'reference' => $this->rowString($updated, 'reference'),
            'effective_date' => $this->rowString($updated, 'effective_date'),
            'expiry_date' => $this->nullableRowString($updated, 'expiry_date'),
            'linkable_count' => $result['links_count'],
        ], request: $request);

        if ($result['superseded'] instanceof stdClass) {
            $this->securityAudit->record('islamic.standard.superseded', actor: $actor, properties: [
                'old_standard_public_id' => $this->rowString($result['superseded'], 'public_id'),
                'new_standard_public_id' => $this->rowString($updated, 'public_id'),
            ], request: $request);
        }

        return $this->respondSuccess($this->standardPayload($updated), 'Islamic standard activated');
    }

    public function retire(Request $request, string $publicId): JsonResponse
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
        $reason = is_string($validated['reason'] ?? null) ? $validated['reason'] : '';

        try {
            $result = DB::transaction(function () use ($publicId, $reason, $actor): array {
                $standard = DB::table('islamic_standards')->where('public_id', $publicId)->lockForUpdate()->first();
                if (! is_object($standard)) {
                    throw new InvalidArgumentException('Islamic standard not found.');
                }
                $previous = $this->rowString($standard, 'status');
                if (! in_array($previous, ['active', 'expired'], true)) {
                    throw new InvalidArgumentException('Only active or expired standards can be retired.');
                }

                DB::table('islamic_standards')->where('id', $this->rowInt($standard, 'id'))->update([
                    'status' => 'retired',
                    'retired_by_user_id' => $actor->id,
                    'retired_at' => now(),
                    'retirement_reason' => $reason,
                    'updated_at' => now(),
                ]);

                $updated = DB::table('islamic_standards')->where('id', $this->rowInt($standard, 'id'))->first();
                if (! is_object($updated)) {
                    throw new InvalidArgumentException('Standard could not be reloaded.');
                }

                return ['row' => $updated, 'previous' => $previous];
            });
        } catch (InvalidArgumentException $exception) {
            return $this->respondUnprocessable(errors: ['islamic_standard' => [$exception->getMessage()]]);
        }

        $updated = $result['row'];

        $this->securityAudit->record('islamic.standard.retired', actor: $actor, properties: [
            'standard_public_id' => $this->rowString($updated, 'public_id'),
            'previous_status' => $result['previous'],
            'new_status' => 'retired',
            'reason' => $reason,
        ], request: $request);

        return $this->respondSuccess($this->standardPayload($updated), 'Islamic standard retired');
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
            'linkable_type' => ['required', Rule::in(IslamicStandardsBaselineService::LINK_TYPES)],
            'linkable_code' => ['required', 'string', 'max:128'],
            'linkable_identifier' => ['sometimes', 'nullable', Rule::in(['code', 'public_id', 'reserved_code'])],
        ])->validate();

        try {
            $linkRow = DB::transaction(function () use ($publicId, $validated, $actor): object {
                $standard = DB::table('islamic_standards')->where('public_id', $publicId)->lockForUpdate()->first();
                if (! is_object($standard)) {
                    throw new InvalidArgumentException('Islamic standard not found.');
                }
                if ($this->rowString($standard, 'status') !== 'draft') {
                    throw new InvalidArgumentException('Only draft standards can be linked; amend an active standard to change its links.');
                }

                $type = (string) $validated['linkable_type'];
                $code = (string) $validated['linkable_code'];
                $identifier = isset($validated['linkable_identifier']) && is_string($validated['linkable_identifier'])
                    ? $validated['linkable_identifier']
                    : null;

                $resolved = $this->validateAndResolveLink($type, $code, $identifier);

                $existingDuplicate = DB::table('islamic_standard_links')
                    ->where('islamic_standard_id', $this->rowInt($standard, 'id'))
                    ->where('linkable_type', $type)
                    ->where('linkable_code', $resolved['stored_code'])
                    ->exists();
                if ($existingDuplicate) {
                    throw new InvalidArgumentException('This link target is already attached to the standard.');
                }

                $id = DB::table('islamic_standard_links')->insertGetId([
                    'public_id' => (string) Str::ulid(),
                    'islamic_standard_id' => $this->rowInt($standard, 'id'),
                    'linkable_type' => $type,
                    'linkable_code' => $resolved['stored_code'],
                    'metadata' => $resolved['metadata'] !== null
                        ? json_encode($resolved['metadata'], JSON_THROW_ON_ERROR)
                        : null,
                    'created_by_user_id' => $actor->id,
                    'created_at' => now(),
                ]);

                $row = DB::table('islamic_standard_links')->where('id', $id)->first();
                if (! is_object($row)) {
                    throw new InvalidArgumentException('Link could not be reloaded.');
                }

                return $row;
            });
        } catch (InvalidArgumentException $exception) {
            return $this->respondUnprocessable(errors: ['islamic_standard_link' => [$exception->getMessage()]]);
        }

        $standardPublicId = DB::table('islamic_standards')
            ->where('id', $this->rowInt($linkRow, 'islamic_standard_id'))
            ->value('public_id');

        $this->securityAudit->record('islamic.standard.linked', actor: $actor, properties: [
            'standard_public_id' => is_string($standardPublicId) ? $standardPublicId : '',
            'linkable_type' => $this->rowString($linkRow, 'linkable_type'),
            'linkable_code' => $this->rowString($linkRow, 'linkable_code'),
        ], request: $request);

        return $this->respondCreated($this->linkPayload($linkRow), 'Standard link created');
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
            'linkable_type' => ['required', Rule::in(IslamicStandardsBaselineService::LINK_TYPES)],
            'linkable_code' => ['required', 'string', 'max:128'],
        ])->validate();

        try {
            $result = DB::transaction(function () use ($publicId, $validated): array {
                $standard = DB::table('islamic_standards')->where('public_id', $publicId)->lockForUpdate()->first();
                if (! is_object($standard)) {
                    throw new InvalidArgumentException('Islamic standard not found.');
                }
                if ($this->rowString($standard, 'status') !== 'draft') {
                    throw new InvalidArgumentException('Only draft standards can be unlinked.');
                }

                $deleted = DB::table('islamic_standard_links')
                    ->where('islamic_standard_id', $this->rowInt($standard, 'id'))
                    ->where('linkable_type', (string) $validated['linkable_type'])
                    ->where('linkable_code', (string) $validated['linkable_code'])
                    ->delete();
                if ($deleted === 0) {
                    throw new InvalidArgumentException('Link not found on this standard.');
                }

                return [
                    'standard_public_id' => $this->rowString($standard, 'public_id'),
                    'linkable_type' => (string) $validated['linkable_type'],
                    'linkable_code' => (string) $validated['linkable_code'],
                ];
            });
        } catch (InvalidArgumentException $exception) {
            return $this->respondUnprocessable(errors: ['islamic_standard_link' => [$exception->getMessage()]]);
        }

        $this->securityAudit->record('islamic.standard.unlinked', actor: $actor, properties: $result, request: $request);

        return $this->respondSuccess($result, 'Standard link removed');
    }

    /**
     * @return array{
     *   source: string,
     *   reference: string,
     *   title: string,
     *   version: string|null,
     *   publication_date: string|null,
     *   scope_summary: string,
     *   owner_type: string,
     *   owner_user_id: int|null,
     *   owner_role: string|null,
     *   owner_department: string|null,
     *   owner_committee: string|null,
     *   effective_date: string,
     *   expiry_date: string|null,
     *   document_id: int|null,
     *   metadata: array<array-key, mixed>|null,
     * }
     */
    private function validateStandardPayload(Request $request, bool $requireDocument, ?object $defaultsFrom = null): array
    {
        $hasDefaults = $defaultsFrom !== null;
        $rules = [
            'source' => [$hasDefaults ? 'sometimes' : 'required', Rule::in(['AAOIFI', 'IFSB', 'COBAC', 'CEMAC', 'INTERNAL', 'LEGAL_OPINION', 'SHARIA_DECISION', 'POLICY'])],
            'reference' => [$hasDefaults ? 'sometimes' : 'required', 'string', 'max:128'],
            'title' => [$hasDefaults ? 'sometimes' : 'required', 'string', 'max:255'],
            'version' => ['sometimes', 'nullable', 'string', 'max:64'],
            'publication_date' => ['sometimes', 'nullable', 'date'],
            'scope_summary' => [$hasDefaults ? 'sometimes' : 'required', 'string', 'max:4000'],
            'owner_type' => [$hasDefaults ? 'sometimes' : 'required', Rule::in(['user', 'role', 'department', 'committee'])],
            'owner_user_public_id' => ['sometimes', 'nullable', 'string', 'exists:users,public_id'],
            'owner_role' => ['sometimes', 'nullable', 'string', 'max:128'],
            'owner_department' => ['sometimes', 'nullable', 'string', 'max:128'],
            'owner_committee' => ['sometimes', 'nullable', 'string', 'max:128'],
            'effective_date' => [$hasDefaults ? 'sometimes' : 'required', 'date'],
            'expiry_date' => ['sometimes', 'nullable', 'date', 'after:effective_date'],
            'document_public_id' => [$requireDocument ? 'required' : 'sometimes', 'nullable', 'string'],
            'metadata' => ['sometimes', 'nullable', 'array'],
        ];

        $validated = Validator::make($request->all(), $rules)->validate();

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

        $ownerUserId = null;
        $ownerRole = null;
        $ownerDepartment = null;
        $ownerCommittee = null;

        if ($ownerType === 'user') {
            $publicId = isset($validated['owner_user_public_id']) && is_string($validated['owner_user_public_id'])
                ? $validated['owner_user_public_id']
                : null;
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

        $source = isset($validated['source']) && is_string($validated['source']) ? $validated['source'] : ($defaultsFrom !== null ? $this->rowString($defaultsFrom, 'source') : '');
        $reference = isset($validated['reference']) && is_string($validated['reference']) ? $validated['reference'] : ($defaultsFrom !== null ? $this->rowString($defaultsFrom, 'reference') : '');
        $title = isset($validated['title']) && is_string($validated['title']) ? $validated['title'] : ($defaultsFrom !== null ? $this->rowString($defaultsFrom, 'title') : '');
        $scopeSummary = isset($validated['scope_summary']) && is_string($validated['scope_summary']) ? $validated['scope_summary'] : ($defaultsFrom !== null ? $this->rowString($defaultsFrom, 'scope_summary') : '');
        $effectiveDate = isset($validated['effective_date']) && is_string($validated['effective_date']) ? $validated['effective_date'] : ($defaultsFrom !== null ? $this->rowString($defaultsFrom, 'effective_date') : '');
        $expiryDate = array_key_exists('expiry_date', $validated) ? $this->nullableString($validated['expiry_date']) : ($defaultsFrom !== null ? $this->nullableRowString($defaultsFrom, 'expiry_date') : null);

        if ($expiryDate !== null && $effectiveDate !== '' && $expiryDate <= $effectiveDate) {
            throw new InvalidArgumentException('expiry_date must be after effective_date.');
        }

        return [
            'source' => $source,
            'reference' => $reference,
            'title' => $title,
            'version' => array_key_exists('version', $validated) ? $this->nullableString($validated['version']) : ($defaultsFrom !== null ? $this->nullableRowString($defaultsFrom, 'version') : null),
            'publication_date' => array_key_exists('publication_date', $validated) ? $this->nullableString($validated['publication_date']) : ($defaultsFrom !== null ? $this->nullableRowString($defaultsFrom, 'publication_date') : null),
            'scope_summary' => $scopeSummary,
            'owner_type' => $ownerType,
            'owner_user_id' => $ownerUserId,
            'owner_role' => $ownerRole,
            'owner_department' => $ownerDepartment,
            'owner_committee' => $ownerCommittee,
            'effective_date' => $effectiveDate,
            'expiry_date' => $expiryDate,
            'document_id' => $documentId,
            'metadata' => isset($validated['metadata']) && is_array($validated['metadata']) ? $validated['metadata'] : null,
        ];
    }

    /**
     * @param  array{
     *   source: string,
     *   reference: string,
     *   title: string,
     *   version: string|null,
     *   publication_date: string|null,
     *   scope_summary: string,
     *   owner_type: string,
     *   owner_user_id: int|null,
     *   owner_role: string|null,
     *   owner_department: string|null,
     *   owner_committee: string|null,
     *   effective_date: string,
     *   expiry_date: string|null,
     *   document_id: int|null,
     *   metadata: array<array-key, mixed>|null,
     * }  $validated
     */
    private function insertDraftStandard(array $validated, User $actor, ?int $supersedesId): object
    {
        if (! is_int($validated['document_id'])) {
            throw new InvalidArgumentException('Evidence document is required.');
        }

        $id = DB::table('islamic_standards')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'source' => $validated['source'],
            'reference' => $validated['reference'],
            'title' => $validated['title'],
            'version' => $validated['version'],
            'publication_date' => $validated['publication_date'],
            'scope_summary' => $validated['scope_summary'],
            'owner_type' => $validated['owner_type'],
            'owner_user_id' => $validated['owner_user_id'],
            'owner_role' => $validated['owner_role'],
            'owner_department' => $validated['owner_department'],
            'owner_committee' => $validated['owner_committee'],
            'effective_date' => $validated['effective_date'],
            'expiry_date' => $validated['expiry_date'],
            'status' => 'draft',
            'document_id' => $validated['document_id'],
            'supersedes_standard_id' => $supersedesId,
            'created_by_user_id' => $actor->id,
            'metadata' => $validated['metadata'] !== null
                ? json_encode($validated['metadata'], JSON_THROW_ON_ERROR)
                : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $row = DB::table('islamic_standards')->where('id', $id)->first();
        if (! is_object($row)) {
            throw new InvalidArgumentException('Standard could not be reloaded.');
        }

        return $row;
    }

    /**
     * @param  array{
     *   source: string,
     *   reference: string,
     *   title: string,
     *   version: string|null,
     *   publication_date: string|null,
     *   scope_summary: string,
     *   owner_type: string,
     *   owner_user_id: int|null,
     *   owner_role: string|null,
     *   owner_department: string|null,
     *   owner_committee: string|null,
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
        foreach (['source', 'reference', 'title', 'scope_summary', 'effective_date'] as $key) {
            if ($validated[$key] !== '') {
                $update[$key] = $validated[$key];
            }
        }
        foreach (['version', 'publication_date', 'expiry_date'] as $key) {
            $update[$key] = $validated[$key];
        }
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
        if ($validated['metadata'] !== null) {
            $update['metadata'] = json_encode($validated['metadata'], JSON_THROW_ON_ERROR);
        }

        return $update;
    }

    /**
     * @return array{stored_code: string, metadata: array<string, mixed>|null}
     */
    private function validateAndResolveLink(string $type, string $code, ?string $identifier): array
    {
        switch ($type) {
            case 'product_family':
                if (! in_array($code, IslamicStandardsBaselineService::PRODUCT_FAMILIES, true)) {
                    throw new InvalidArgumentException('Unknown product family code.');
                }

                return ['stored_code' => $code, 'metadata' => ['identifier_type' => 'code']];

            case 'account_type':
                if (! in_array($code, IslamicStandardsBaselineService::ACCOUNT_TYPES, true)) {
                    throw new InvalidArgumentException('Unknown account type code.');
                }

                return ['stored_code' => $code, 'metadata' => ['identifier_type' => 'code']];

            case 'accounting_mapping':
                $mapping = DB::table('operation_account_mappings as m')
                    ->join('operation_codes as oc', 'oc.id', '=', 'm.operation_code_id')
                    ->where('m.public_id', $code)
                    ->where('oc.module', 'islamic_finance')
                    ->select('m.public_id')
                    ->first();
                if (! is_object($mapping)) {
                    throw new InvalidArgumentException('Accounting mapping not found for the Islamic finance module; an operation-code-only value is not accepted.');
                }

                return ['stored_code' => $code, 'metadata' => ['identifier_type' => 'public_id']];

            case 'contract_template':
                if ($identifier === 'reserved_code' || $identifier === null) {
                    if (! in_array($code, self::RESERVED_CONTRACT_TEMPLATE_CODES, true)) {
                        throw new InvalidArgumentException('Unknown reserved contract template code.');
                    }

                    return [
                        'stored_code' => $code,
                        'metadata' => [
                            'identifier_type' => 'reserved_code',
                            'reserved_until_backlog' => 'IF-032',
                        ],
                    ];
                }
                throw new InvalidArgumentException('Contract template registry is not yet available; only reserved_code identifiers are accepted.');
            case 'screening_policy':
                if ($identifier === 'reserved_code' || $identifier === null) {
                    if (! in_array($code, self::RESERVED_SCREENING_POLICY_CODES, true)) {
                        throw new InvalidArgumentException('Unknown reserved screening policy code.');
                    }

                    return [
                        'stored_code' => $code,
                        'metadata' => [
                            'identifier_type' => 'reserved_code',
                            'reserved_until_backlog' => 'IF-020',
                        ],
                    ];
                }
                throw new InvalidArgumentException('Screening policy registry is not yet available; only reserved_code identifiers are accepted.');
        }

        throw new InvalidArgumentException('Unsupported link type.');
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

        $standardIds = [];
        $documentIds = [];
        $supersedesIds = [];
        $userIds = [];
        foreach ($rows as $row) {
            $standardIds[] = $this->rowInt($row, 'id');
            $documentIds[] = $this->rowInt($row, 'document_id');
            $supersedes = $this->rowNullableInt($row, 'supersedes_standard_id');
            if ($supersedes !== null) {
                $supersedesIds[] = $supersedes;
            }
            $owner = $this->rowNullableInt($row, 'owner_user_id');
            if ($owner !== null) {
                $userIds[] = $owner;
            }
        }

        $documentPublicIds = DB::table('documents')
            ->whereIn('id', array_unique($documentIds))
            ->pluck('public_id', 'id');
        $supersedesPublicIds = $supersedesIds !== []
            ? DB::table('islamic_standards')->whereIn('id', array_unique($supersedesIds))->pluck('public_id', 'id')
            : collect();
        $userPublicIds = $userIds !== []
            ? DB::table('users')->whereIn('id', array_unique($userIds))->pluck('public_id', 'id')
            : collect();

        /** @var array<int, array<int, array<string, mixed>>> $linksByStandard */
        $linksByStandard = [];
        $linkRows = DB::table('islamic_standard_links')
            ->whereIn('islamic_standard_id', $standardIds)
            ->orderBy('id')
            ->get();
        foreach ($linkRows as $link) {
            $sid = $this->rowInt($link, 'islamic_standard_id');
            $linksByStandard[$sid] ??= [];
            $linksByStandard[$sid][] = $this->linkPayload($link);
        }

        $payloads = [];
        foreach ($rows as $row) {
            $documentValue = $documentPublicIds[$this->rowInt($row, 'document_id')] ?? null;
            $payloads[] = $this->buildStandardPayload(
                $row,
                $linksByStandard[$this->rowInt($row, 'id')] ?? [],
                is_string($documentValue) ? $documentValue : null,
                $this->lookupSupersedesPublicId($row, $supersedesPublicIds),
                $this->lookupOwnerLabel($row, $userPublicIds),
            );
        }

        return $payloads;
    }

    /**
     * @param  Collection<int, mixed>  $supersedesPublicIds
     */
    private function lookupSupersedesPublicId(object $row, $supersedesPublicIds): ?string
    {
        $supersedesId = $this->rowNullableInt($row, 'supersedes_standard_id');
        if ($supersedesId === null) {
            return null;
        }
        $value = $supersedesPublicIds[$supersedesId] ?? null;

        return is_string($value) ? $value : null;
    }

    /**
     * @param  Collection<int, mixed>  $userPublicIds
     */
    private function lookupOwnerLabel(object $row, $userPublicIds): string
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

    /**
     * @param  array<int, array<string, mixed>>  $linksPayload
     * @return array<string, mixed>
     */
    private function buildStandardPayload(object $row, array $linksPayload, ?string $documentPublicId, ?string $supersedesPublicId, string $ownerLabel): array
    {
        return [
            'public_id' => $this->rowString($row, 'public_id'),
            'source' => $this->rowString($row, 'source'),
            'reference' => $this->rowString($row, 'reference'),
            'title' => $this->rowString($row, 'title'),
            'version' => $this->nullableRowString($row, 'version'),
            'publication_date' => $this->nullableRowString($row, 'publication_date'),
            'scope_summary' => $this->rowString($row, 'scope_summary'),
            'owner' => [
                'type' => $this->rowString($row, 'owner_type'),
                'label' => $ownerLabel,
            ],
            'effective_date' => $this->rowString($row, 'effective_date'),
            'expiry_date' => $this->nullableRowString($row, 'expiry_date'),
            'status' => $this->rowString($row, 'status'),
            'document_public_id' => $documentPublicId,
            'supersedes_standard_public_id' => $supersedesPublicId,
            'links' => $linksPayload,
            'created_at' => $this->nullableRowString($row, 'created_at'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function standardPayload(object $row): array
    {
        $links = DB::table('islamic_standard_links')
            ->where('islamic_standard_id', $this->rowInt($row, 'id'))
            ->get();

        $linksPayload = [];
        foreach ($links as $link) {
            $linksPayload[] = $this->linkPayload($link);
        }

        $documentPublicId = $this->documentPublicId($this->rowInt($row, 'document_id'));
        $supersedesId = $this->rowNullableInt($row, 'supersedes_standard_id');
        $supersedesPublicId = null;
        if ($supersedesId !== null) {
            $value = DB::table('islamic_standards')->where('id', $supersedesId)->value('public_id');
            $supersedesPublicId = is_string($value) ? $value : null;
        }

        return [
            'public_id' => $this->rowString($row, 'public_id'),
            'source' => $this->rowString($row, 'source'),
            'reference' => $this->rowString($row, 'reference'),
            'title' => $this->rowString($row, 'title'),
            'version' => $this->nullableRowString($row, 'version'),
            'publication_date' => $this->nullableRowString($row, 'publication_date'),
            'scope_summary' => $this->rowString($row, 'scope_summary'),
            'owner' => [
                'type' => $this->rowString($row, 'owner_type'),
                'label' => $this->ownerLabel($row),
            ],
            'effective_date' => $this->rowString($row, 'effective_date'),
            'expiry_date' => $this->nullableRowString($row, 'expiry_date'),
            'status' => $this->rowString($row, 'status'),
            'document_public_id' => $documentPublicId,
            'supersedes_standard_public_id' => $supersedesPublicId,
            'links' => $linksPayload,
            'created_at' => $this->nullableRowString($row, 'created_at'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function linkPayload(object $row): array
    {
        $metadata = null;
        $raw = ((array) $row)['metadata'] ?? null;
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $metadata = $decoded;
            }
        }

        return [
            'public_id' => $this->rowString($row, 'public_id'),
            'type' => $this->rowString($row, 'linkable_type'),
            'code' => $this->rowString($row, 'linkable_code'),
            'metadata' => $metadata,
        ];
    }

    private function ownerLabel(object $row): string
    {
        $type = $this->rowString($row, 'owner_type');
        if ($type === 'user') {
            $userId = $this->rowNullableInt($row, 'owner_user_id');
            if ($userId !== null) {
                $publicId = DB::table('users')->where('id', $userId)->value('public_id');
                if (is_string($publicId)) {
                    return $publicId;
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

    private function documentPublicId(int $documentId): ?string
    {
        $value = DB::table('documents')->where('id', $documentId)->value('public_id');

        return is_string($value) ? $value : null;
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
