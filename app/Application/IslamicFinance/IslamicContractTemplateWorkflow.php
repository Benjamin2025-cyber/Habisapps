<?php

declare(strict_types=1);

namespace App\Application\IslamicFinance;

use App\Http\Controllers\BaseController;
use App\Models\User;
use App\Support\Security\SecurityAudit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

final class IslamicContractTemplateWorkflow extends BaseController
{
    public function __construct(
        private readonly SecurityAudit $securityAudit,
        private readonly IslamicApprovalWorkflowService $approvalWorkflow,
        private readonly IslamicProductFamilyRegistry $families,
    ) {}

    public function index(Request $request): JsonResponse
    {
        if (! $this->requirePlatformAdmin($request)) {
            return $this->respondForbidden();
        }

        $validated = Validator::make($request->all(), [
            'family_code' => ['sometimes', 'nullable', 'string', 'max:64'],
            'language_code' => ['sometimes', 'nullable', 'string', 'max:8'],
            'status' => ['sometimes', 'nullable', Rule::in(['draft', 'submitted', 'approved', 'suspended', 'revoked', 'expired', 'retired', 'archived'])],
            'template_code' => ['sometimes', 'nullable', 'string', 'max:128'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ])->validate();

        $query = DB::table('islamic_contract_templates')->orderByDesc('id');
        foreach (['family_code', 'language_code', 'status', 'template_code'] as $key) {
            if (is_string($validated[$key] ?? null) && $validated[$key] !== '') {
                $query->where($key, $validated[$key]);
            }
        }

        $search = $request->query('search');
        if (is_string($search) && trim($search) !== '') {
            $term = trim($search);
            $query->where(function ($builder) use ($term): void {
                $builder->where('public_id', 'ilike', '%'.$term.'%')
                    ->orWhere('family_code', 'ilike', '%'.$term.'%')
                    ->orWhere('language_code', 'ilike', '%'.$term.'%')
                    ->orWhere('template_code', 'ilike', '%'.$term.'%')
                    ->orWhere('status', 'ilike', '%'.$term.'%')
                    ->orWhere('legal_signoff_ref', 'ilike', '%'.$term.'%')
                    ->orWhere('sharia_signoff_ref', 'ilike', '%'.$term.'%');
            });
        }

        $perPage = isset($validated['per_page']) && is_numeric($validated['per_page']) ? (int) $validated['per_page'] : 25;
        $page = isset($validated['page']) && is_numeric($validated['page']) ? (int) $validated['page'] : 1;
        $total = (clone $query)->count();
        $rows = $query->forPage($page, $perPage)->get();

        return $this->respondSuccess([
            'contract_templates' => $rows->map(fn (object $row): array => $this->templatePayload($row))->all(),
        ], 'Islamic contract templates retrieved', meta: [
            'pagination' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => (int) ceil(max(1, $total) / $perPage),
            ],
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

        $validated = $this->validateTemplatePayload($request, false);

        try {
            $row = DB::transaction(function () use ($validated, $actor, $request): object {
                $familyCode = $this->requiredString($validated, 'family_code');
                if ($this->families->metadataFor($familyCode) === null) {
                    throw new InvalidArgumentException('Unknown Islamic product family code.');
                }

                $documentId = $this->documentIdByPublicId($validated['document_public_id'] ?? null);
                $publicId = (string) Str::ulid();
                $id = DB::table('islamic_contract_templates')->insertGetId([
                    'public_id' => $publicId,
                    'family_code' => $familyCode,
                    'language_code' => $this->requiredString($validated, 'language_code'),
                    'template_code' => $this->requiredString($validated, 'template_code'),
                    'version' => $this->requiredInt($validated, 'version'),
                    'status' => 'draft',
                    'effective_from' => $this->requiredString($validated, 'effective_from'),
                    'effective_to' => is_string($validated['effective_to'] ?? null) ? $validated['effective_to'] : null,
                    'fields_schema' => isset($validated['fields_schema']) ? json_encode($validated['fields_schema'], JSON_THROW_ON_ERROR) : null,
                    'commercial_terms_schema' => isset($validated['commercial_terms_schema']) ? json_encode($validated['commercial_terms_schema'], JSON_THROW_ON_ERROR) : null,
                    'document_id' => $documentId,
                    'legal_signoff_ref' => is_string($validated['legal_signoff_ref'] ?? null) ? $validated['legal_signoff_ref'] : null,
                    'sharia_signoff_ref' => is_string($validated['sharia_signoff_ref'] ?? null) ? $validated['sharia_signoff_ref'] : null,
                    'metadata' => isset($validated['metadata']) ? json_encode($validated['metadata'], JSON_THROW_ON_ERROR) : null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $this->approvalWorkflow->ensureWorkflow(
                    IslamicApprovalStateMachine::SUBJECT_CONTRACT_TEMPLATE,
                    $publicId,
                    $actor,
                    $request,
                );

                $row = DB::table('islamic_contract_templates')->where('id', $id)->first();
                if (! is_object($row)) {
                    throw new InvalidArgumentException('Islamic contract template could not be reloaded.');
                }

                return $row;
            });
        } catch (InvalidArgumentException $exception) {
            return $this->respondUnprocessable(errors: ['islamic_contract_template' => [$exception->getMessage()]]);
        }

        $this->securityAudit->record('islamic.contract_template.created', actor: $actor, properties: [
            'template_public_id' => $this->rowString($row, 'public_id'),
            'family_code' => $this->rowString($row, 'family_code'),
            'template_code' => $this->rowString($row, 'template_code'),
            'version' => $this->rowInt($row, 'version'),
        ], request: $request);

        return $this->respondCreated($this->templatePayload($row), 'Islamic contract template created');
    }

    public function show(Request $request, string $templatePublicId): JsonResponse
    {
        if (! $this->requirePlatformAdmin($request)) {
            return $this->respondForbidden();
        }

        $row = DB::table('islamic_contract_templates')->where('public_id', $templatePublicId)->first();
        if (! is_object($row)) {
            return $this->respondNotFound('Islamic contract template not found.');
        }

        return $this->respondSuccess($this->templatePayload($row), 'Islamic contract template retrieved');
    }

    public function updateDraft(Request $request, string $templatePublicId): JsonResponse
    {
        if (! $this->requirePlatformAdmin($request)) {
            return $this->respondForbidden();
        }

        $actor = $request->user();
        if (! $actor instanceof User) {
            return $this->respondForbidden();
        }

        $validated = $this->validateTemplatePayload($request, true);

        try {
            $row = DB::transaction(function () use ($templatePublicId, $validated): object {
                $existing = DB::table('islamic_contract_templates')->where('public_id', $templatePublicId)->lockForUpdate()->first();
                if (! is_object($existing)) {
                    throw new InvalidArgumentException('Islamic contract template not found.');
                }
                if ($this->rowString($existing, 'status') !== 'draft') {
                    throw new InvalidArgumentException('Only draft templates can be updated.');
                }

                $update = [];
                foreach (['family_code', 'language_code', 'template_code', 'legal_signoff_ref', 'sharia_signoff_ref'] as $key) {
                    if (is_string($validated[$key] ?? null) && $validated[$key] !== '') {
                        $update[$key] = $validated[$key];
                    }
                }
                foreach (['effective_from', 'effective_to'] as $key) {
                    if (array_key_exists($key, $validated)) {
                        $update[$key] = $validated[$key];
                    }
                }
                if (isset($validated['version'])) {
                    $update['version'] = $this->requiredInt($validated, 'version');
                }
                if (array_key_exists('fields_schema', $validated)) {
                    $update['fields_schema'] = is_array($validated['fields_schema']) ? json_encode($validated['fields_schema'], JSON_THROW_ON_ERROR) : null;
                }
                if (array_key_exists('commercial_terms_schema', $validated)) {
                    $update['commercial_terms_schema'] = is_array($validated['commercial_terms_schema']) ? json_encode($validated['commercial_terms_schema'], JSON_THROW_ON_ERROR) : null;
                }
                if (array_key_exists('metadata', $validated)) {
                    $update['metadata'] = is_array($validated['metadata']) ? json_encode($validated['metadata'], JSON_THROW_ON_ERROR) : null;
                }
                if (array_key_exists('document_public_id', $validated)) {
                    $update['document_id'] = $this->documentIdByPublicId($validated['document_public_id']);
                }

                if ($update !== []) {
                    $update['updated_at'] = now();
                    DB::table('islamic_contract_templates')->where('id', $this->rowInt($existing, 'id'))->update($update);
                }

                $row = DB::table('islamic_contract_templates')->where('id', $this->rowInt($existing, 'id'))->first();
                if (! is_object($row)) {
                    throw new InvalidArgumentException('Islamic contract template could not be reloaded.');
                }

                return $row;
            });
        } catch (InvalidArgumentException $exception) {
            return $this->respondUnprocessable(errors: ['islamic_contract_template' => [$exception->getMessage()]]);
        }

        $this->securityAudit->record('islamic.contract_template.updated', actor: $actor, properties: [
            'template_public_id' => $this->rowString($row, 'public_id'),
            'status' => $this->rowString($row, 'status'),
        ], request: $request);

        return $this->respondSuccess($this->templatePayload($row), 'Islamic contract template updated');
    }

    public function submit(Request $request, string $templatePublicId): JsonResponse
    {
        return $this->transition($request, $templatePublicId, IslamicApprovalStateMachine::DECISION_SUBMIT, 'submitted');
    }

    public function approve(Request $request, string $templatePublicId): JsonResponse
    {
        return $this->transition($request, $templatePublicId, IslamicApprovalStateMachine::DECISION_APPROVE, 'approved', true);
    }

    public function suspend(Request $request, string $templatePublicId): JsonResponse
    {
        return $this->transition($request, $templatePublicId, IslamicApprovalStateMachine::DECISION_SUSPEND, 'suspended');
    }

    public function revoke(Request $request, string $templatePublicId): JsonResponse
    {
        return $this->transition($request, $templatePublicId, IslamicApprovalStateMachine::DECISION_REVOKE, 'revoked');
    }

    public function archive(Request $request, string $templatePublicId): JsonResponse
    {
        return $this->transition($request, $templatePublicId, IslamicApprovalStateMachine::DECISION_ARCHIVE, 'archived');
    }

    public function retire(Request $request, string $templatePublicId): JsonResponse
    {
        if (! $this->requirePlatformAdmin($request)) {
            return $this->respondForbidden();
        }
        $actor = $request->user();
        if (! $actor instanceof User) {
            return $this->respondForbidden();
        }

        try {
            $row = DB::transaction(function () use ($templatePublicId): object {
                $template = DB::table('islamic_contract_templates')->where('public_id', $templatePublicId)->lockForUpdate()->first();
                if (! is_object($template)) {
                    throw new InvalidArgumentException('Islamic contract template not found.');
                }
                if (! in_array($this->rowString($template, 'status'), ['approved', 'suspended', 'revoked', 'expired'], true)) {
                    throw new InvalidArgumentException('Only approved/suspended/revoked/expired templates can be retired.');
                }

                DB::table('islamic_contract_templates')->where('id', $this->rowInt($template, 'id'))->update([
                    'status' => 'retired',
                    'updated_at' => now(),
                ]);

                $row = DB::table('islamic_contract_templates')->where('id', $this->rowInt($template, 'id'))->first();
                if (! is_object($row)) {
                    throw new InvalidArgumentException('Islamic contract template could not be reloaded.');
                }

                return $row;
            });
        } catch (InvalidArgumentException $exception) {
            return $this->respondUnprocessable(errors: ['islamic_contract_template' => [$exception->getMessage()]]);
        }

        $this->securityAudit->record('islamic.contract_template.status_changed', actor: $actor, properties: [
            'template_public_id' => $this->rowString($row, 'public_id'),
            'status' => 'retired',
        ], request: $request);

        return $this->respondSuccess($this->templatePayload($row), 'Islamic contract template retired');
    }

    private function transition(Request $request, string $templatePublicId, string $decision, string $status, bool $isApprove = false): JsonResponse
    {
        if (! $this->requirePlatformAdmin($request)) {
            return $this->respondForbidden();
        }
        $actor = $request->user();
        if (! $actor instanceof User) {
            return $this->respondForbidden();
        }

        try {
            $row = DB::transaction(function () use ($templatePublicId, $actor, $decision, $status, $request, $isApprove): object {
                $template = DB::table('islamic_contract_templates')->where('public_id', $templatePublicId)->lockForUpdate()->first();
                if (! is_object($template)) {
                    throw new InvalidArgumentException('Islamic contract template not found.');
                }

                if ($isApprove) {
                    if ($this->rowString($template, 'legal_signoff_ref') === '' || $this->rowString($template, 'sharia_signoff_ref') === '') {
                        throw new InvalidArgumentException('Template requires legal and Sharia sign-off references before approval.');
                    }
                    $documentId = $this->rowNullableInt($template, 'document_id');
                    if ($documentId === null) {
                        throw new InvalidArgumentException('Template requires a document attachment before approval.');
                    }
                    $document = DB::table('documents')->where('id', $documentId)->first();
                    if (! is_object($document) || $this->rowString($document, 'status') !== 'active') {
                        throw new InvalidArgumentException('Template document must be active before approval.');
                    }
                }

                $this->approvalWorkflow->ensureWorkflow(
                    IslamicApprovalStateMachine::SUBJECT_CONTRACT_TEMPLATE,
                    $templatePublicId,
                    $actor,
                    $request,
                );
                $this->approvalWorkflow->applyDecision(
                    IslamicApprovalStateMachine::SUBJECT_CONTRACT_TEMPLATE,
                    $templatePublicId,
                    $actor,
                    $decision,
                    [
                        'effective_from' => $this->nullableString(((array) $template)['effective_from'] ?? null),
                        'effective_to' => $this->nullableString(((array) $template)['effective_to'] ?? null),
                        'skip_authority_check' => true,
                    ],
                    $request,
                );

                DB::table('islamic_contract_templates')->where('id', $this->rowInt($template, 'id'))->update([
                    'status' => $status,
                    'updated_at' => now(),
                ]);

                $updated = DB::table('islamic_contract_templates')->where('id', $this->rowInt($template, 'id'))->first();
                if (! is_object($updated)) {
                    throw new InvalidArgumentException('Islamic contract template could not be reloaded.');
                }

                return $updated;
            });
        } catch (ReadinessGateFailure $failure) {
            return $this->respondUnprocessable(errors: $failure->failures);
        } catch (InvalidArgumentException $exception) {
            return $this->respondUnprocessable(errors: ['islamic_contract_template' => [$exception->getMessage()]]);
        }

        $this->securityAudit->record('islamic.contract_template.status_changed', actor: $actor, properties: [
            'template_public_id' => $this->rowString($row, 'public_id'),
            'status' => $status,
        ], request: $request);

        return $this->respondSuccess($this->templatePayload($row), 'Islamic contract template '.$status);
    }

    /**
     * @return array<string, mixed>
     */
    private function validateTemplatePayload(Request $request, bool $isUpdate): array
    {
        return Validator::make($request->all(), [
            'family_code' => [$isUpdate ? 'sometimes' : 'required', 'string', 'max:64'],
            'language_code' => [$isUpdate ? 'sometimes' : 'required', 'string', 'max:8'],
            'template_code' => [$isUpdate ? 'sometimes' : 'required', 'string', 'max:128'],
            'version' => [$isUpdate ? 'sometimes' : 'required', 'integer', 'min:1'],
            'effective_from' => [$isUpdate ? 'sometimes' : 'required', 'date'],
            'effective_to' => ['sometimes', 'nullable', 'date', 'after:effective_from'],
            'fields_schema' => ['sometimes', 'nullable', 'array'],
            'commercial_terms_schema' => ['sometimes', 'nullable', 'array'],
            'document_public_id' => ['sometimes', 'nullable', 'string', 'exists:documents,public_id'],
            'legal_signoff_ref' => ['sometimes', 'nullable', 'string', 'max:128'],
            'sharia_signoff_ref' => ['sometimes', 'nullable', 'string', 'max:128'],
            'metadata' => ['sometimes', 'nullable', 'array'],
        ])->validate();
    }

    /** @return array<string, mixed> */
    private function templatePayload(object $row): array
    {
        return [
            'public_id' => $this->rowString($row, 'public_id'),
            'family_code' => $this->rowString($row, 'family_code'),
            'language_code' => $this->rowString($row, 'language_code'),
            'template_code' => $this->rowString($row, 'template_code'),
            'version' => $this->rowInt($row, 'version'),
            'status' => $this->rowString($row, 'status'),
            'effective_from' => $this->nullableString(((array) $row)['effective_from'] ?? null),
            'effective_to' => $this->nullableString(((array) $row)['effective_to'] ?? null),
            'fields_schema' => $this->decodeJson(((array) $row)['fields_schema'] ?? null),
            'commercial_terms_schema' => $this->decodeJson(((array) $row)['commercial_terms_schema'] ?? null),
            'document_public_id' => $this->documentPublicId($this->rowNullableInt($row, 'document_id')),
            'legal_signoff_ref' => $this->nullableString(((array) $row)['legal_signoff_ref'] ?? null),
            'sharia_signoff_ref' => $this->nullableString(((array) $row)['sharia_signoff_ref'] ?? null),
            'metadata' => $this->decodeJson(((array) $row)['metadata'] ?? null),
        ];
    }

    private function documentIdByPublicId(mixed $publicId): ?int
    {
        if (! is_string($publicId) || $publicId === '') {
            return null;
        }
        $row = DB::table('documents')->where('public_id', $publicId)->first(['id']);

        return is_object($row) && is_numeric($row->id) ? (int) $row->id : null;
    }

    private function documentPublicId(?int $documentId): ?string
    {
        if ($documentId === null) {
            return null;
        }
        $row = DB::table('documents')->where('id', $documentId)->first(['public_id']);

        return is_object($row) && is_string($row->public_id) ? $row->public_id : null;
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

    private function nullableString(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_scalar($value) ? (string) $value : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function requiredString(array $payload, string $key): string
    {
        $value = $payload[$key] ?? null;
        if (! is_string($value) || $value === '') {
            throw new InvalidArgumentException(sprintf('Expected non-empty string for %s.', $key));
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function requiredInt(array $payload, string $key): int
    {
        $value = $payload[$key] ?? null;
        if (! is_int($value)) {
            throw new InvalidArgumentException(sprintf('Expected integer for %s.', $key));
        }

        return $value;
    }

    /** @return array<string, mixed>|array<int, mixed>|null */
    private function decodeJson(mixed $value): ?array
    {
        if (is_array($value)) {
            return $value;
        }
        if (! is_string($value) || $value === '') {
            return null;
        }
        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : null;
    }
}
