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

final class IslamicScreeningPolicyWorkflow extends BaseController
{
    public function __construct(
        private readonly IslamicApprovalWorkflowService $approvalWorkflows,
        private readonly IslamicScreeningPolicyService $screening,
        private readonly SecurityAudit $securityAudit,
    ) {}

    public function index(Request $request): JsonResponse
    {
        if (! $this->requirePlatformAdmin($request)) {
            return $this->respondForbidden();
        }

        $query = DB::table('islamic_screening_policies')->orderByDesc('id');
        if (is_string($request->query('status')) && $request->query('status') !== '') {
            $query->where('status', $request->query('status'));
        }

        $search = $request->query('search');
        if (is_string($search) && trim($search) !== '') {
            $term = trim($search);
            $query->where(function ($builder) use ($term): void {
                $builder->where('public_id', 'ilike', '%'.$term.'%')
                    ->orWhere('code', 'ilike', '%'.$term.'%')
                    ->orWhere('name', 'ilike', '%'.$term.'%')
                    ->orWhere('scope_type', 'ilike', '%'.$term.'%')
                    ->orWhere('scope_value', 'ilike', '%'.$term.'%')
                    ->orWhere('status', 'ilike', '%'.$term.'%');
            });
        }

        $perPage = min(max($request->integer('per_page', 25), 1), 100);
        $page = max($request->integer('page', 1), 1);
        $total = (clone $query)->count();
        $rows = $query->forPage($page, $perPage)->get();

        return $this->respondSuccess([
            'screening_policies' => $rows->map(fn (object $row): array => $this->policyPayload($row))->all(),
        ], 'Screening policies retrieved', meta: [
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

        $validated = Validator::make($request->all(), [
            'code' => ['required', 'string', 'max:64'],
            'name' => ['required', 'string', 'max:191'],
            'scope_type' => ['required', Rule::in(['institution', 'agency', 'product_family'])],
            'scope_value' => ['sometimes', 'nullable', 'string', 'max:128'],
            'effective_from' => ['sometimes', 'nullable', 'date'],
            'effective_to' => ['sometimes', 'nullable', 'date', 'after:effective_from'],
            'description' => ['sometimes', 'nullable', 'string'],
            'metadata' => ['sometimes', 'nullable', 'array'],
        ])->validate();

        $versionRaw = DB::table('islamic_screening_policies')->where('code', (string) $validated['code'])->max('version');
        $version = is_numeric($versionRaw) ? (int) $versionRaw : 0;
        $id = DB::table('islamic_screening_policies')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'code' => (string) $validated['code'],
            'name' => (string) $validated['name'],
            'version' => $version + 1,
            'scope_type' => (string) $validated['scope_type'],
            'scope_value' => is_string($validated['scope_value'] ?? null) ? $validated['scope_value'] : null,
            'status' => 'draft',
            'effective_from' => is_string($validated['effective_from'] ?? null) ? $validated['effective_from'] : null,
            'effective_to' => is_string($validated['effective_to'] ?? null) ? $validated['effective_to'] : null,
            'description' => is_string($validated['description'] ?? null) ? $validated['description'] : null,
            'document_id' => null,
            'created_by_user_id' => $actor->id,
            'metadata' => is_array($validated['metadata'] ?? null) ? json_encode($validated['metadata'], JSON_THROW_ON_ERROR) : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $row = DB::table('islamic_screening_policies')->where('id', $id)->first();
        if (! is_object($row)) {
            return $this->respondUnprocessable(errors: ['islamic_screening_policy' => [__('Policy could not be reloaded.')]]);
        }

        $this->approvalWorkflows->ensureWorkflow(
            IslamicApprovalStateMachine::SUBJECT_SCREENING_POLICY,
            (string) $row->public_id,
            $actor,
            $request,
        );
        $this->securityAudit->record('islamic.screening_policy.created', actor: $actor, properties: [
            'policy_public_id' => (string) $row->public_id,
            'code' => (string) $row->code,
            'version' => (int) $row->version,
        ], request: $request);

        return $this->respondCreated($this->policyPayload($row), 'Islamic screening policy created');
    }

    public function show(Request $request, string $policyPublicId): JsonResponse
    {
        if (! $this->requirePlatformAdmin($request)) {
            return $this->respondForbidden();
        }
        $row = DB::table('islamic_screening_policies')->where('public_id', $policyPublicId)->first();
        if (! is_object($row)) {
            return $this->respondNotFound('Screening policy not found.');
        }

        return $this->respondSuccess($this->policyPayload($row), 'Screening policy retrieved');
    }

    public function updateDraft(Request $request, string $policyPublicId): JsonResponse
    {
        if (! $this->requirePlatformAdmin($request)) {
            return $this->respondForbidden();
        }
        $row = DB::table('islamic_screening_policies')->where('public_id', $policyPublicId)->first();
        if (! is_object($row)) {
            return $this->respondNotFound('Screening policy not found.');
        }
        if ((string) $row->status !== 'draft') {
            return $this->respondUnprocessable(errors: ['islamic_screening_policy' => [__('Only draft policies can be updated.')]]);
        }
        $validated = Validator::make($request->all(), [
            'name' => ['sometimes', 'string', 'max:191'],
            'scope_value' => ['sometimes', 'nullable', 'string', 'max:128'],
            'effective_from' => ['sometimes', 'nullable', 'date'],
            'effective_to' => ['sometimes', 'nullable', 'date', 'after:effective_from'],
            'description' => ['sometimes', 'nullable', 'string'],
            'metadata' => ['sometimes', 'nullable', 'array'],
        ])->validate();

        $updates = ['updated_at' => now()];
        foreach (['name', 'scope_value', 'effective_from', 'effective_to', 'description'] as $column) {
            if (array_key_exists($column, $validated)) {
                $updates[$column] = $validated[$column];
            }
        }
        if (array_key_exists('metadata', $validated)) {
            $updates['metadata'] = is_array($validated['metadata']) ? json_encode($validated['metadata'], JSON_THROW_ON_ERROR) : null;
        }
        DB::table('islamic_screening_policies')->where('id', (int) $row->id)->update($updates);
        $updated = DB::table('islamic_screening_policies')->where('id', (int) $row->id)->first();
        if (! is_object($updated)) {
            return $this->respondUnprocessable(errors: ['islamic_screening_policy' => [__('Policy could not be reloaded after update.')]]);
        }

        $actor = $request->user();
        if ($actor instanceof User) {
            $this->securityAudit->record('islamic.screening_policy.updated', actor: $actor, properties: [
                'policy_public_id' => (string) $updated->public_id,
            ], request: $request);
        }

        return $this->respondSuccess($this->policyPayload($updated), 'Screening policy updated');
    }

    public function storeRule(Request $request, string $policyPublicId): JsonResponse
    {
        return $this->upsertRule($request, $policyPublicId, null);
    }

    public function updateRule(Request $request, string $policyPublicId, string $rulePublicId): JsonResponse
    {
        return $this->upsertRule($request, $policyPublicId, $rulePublicId);
    }

    public function deleteRule(Request $request, string $policyPublicId, string $rulePublicId): JsonResponse
    {
        if (! $this->requirePlatformAdmin($request)) {
            return $this->respondForbidden();
        }
        $policy = DB::table('islamic_screening_policies')->where('public_id', $policyPublicId)->first();
        if (! is_object($policy)) {
            return $this->respondNotFound('Screening policy not found.');
        }
        DB::table('islamic_screening_policy_rules')
            ->where('policy_id', (int) $policy->id)
            ->where('public_id', $rulePublicId)
            ->delete();

        return $this->respondSuccess(['public_id' => $rulePublicId], 'Rule deleted');
    }

    public function activate(Request $request, string $policyPublicId): JsonResponse
    {
        return $this->transition($request, $policyPublicId, 'active');
    }

    public function suspend(Request $request, string $policyPublicId): JsonResponse
    {
        return $this->transition($request, $policyPublicId, 'suspended');
    }

    public function revoke(Request $request, string $policyPublicId): JsonResponse
    {
        return $this->transition($request, $policyPublicId, 'revoked');
    }

    public function archive(Request $request, string $policyPublicId): JsonResponse
    {
        return $this->transition($request, $policyPublicId, 'archived');
    }

    public function evaluate(Request $request): JsonResponse
    {
        if (! $this->requirePlatformAdmin($request)) {
            return $this->respondForbidden();
        }
        $validated = Validator::make($request->all(), [
            'subject_type' => ['required', 'string', Rule::in([
                'islamic_product',
                'islamic_financing',
                'islamic_supplier',
                'islamic_asset',
                'islamic_goods',
                'islamic_project',
                'investment_account',
            ])],
            'subject_public_id' => ['required', 'string', 'max:64'],
            'context_type' => ['required', 'string', Rule::in(IslamicScreeningPolicyService::allowedContextTypes())],
            'facts' => ['required', 'array'],
            'strict_policy' => ['sometimes', 'boolean'],
            'override_exception_subject_public_id' => ['sometimes', 'nullable', 'string', 'max:64'],
        ])->validate();
        $actor = $request->user();
        try {
            $result = $this->screening->evaluate(
                subjectType: (string) $validated['subject_type'],
                subjectPublicId: (string) $validated['subject_public_id'],
                contextType: (string) $validated['context_type'],
                facts: (array) $validated['facts'],
                actor: $actor instanceof User ? $actor : null,
                strictPolicy: (bool) ($validated['strict_policy'] ?? false),
                overrideExceptionSubjectPublicId: is_string($validated['override_exception_subject_public_id'] ?? null) ? $validated['override_exception_subject_public_id'] : null,
            );
        } catch (InvalidArgumentException $exception) {
            return $this->respondUnprocessable(errors: ['islamic_screening' => [$exception->getMessage()]]);
        }

        return $this->respondSuccess($result, 'Screening evaluation completed');
    }

    public function listResults(Request $request): JsonResponse
    {
        if (! $this->requirePlatformAdmin($request)) {
            return $this->respondForbidden();
        }
        $query = DB::table('islamic_screening_results')->orderByDesc('id');
        if (is_string($request->query('subject_type')) && $request->query('subject_type') !== '') {
            $query->where('subject_type', $request->query('subject_type'));
        }
        if (is_string($request->query('subject_public_id')) && $request->query('subject_public_id') !== '') {
            $query->where('subject_public_id', $request->query('subject_public_id'));
        }
        if (is_string($request->query('context_type')) && $request->query('context_type') !== '') {
            $query->where('context_type', $request->query('context_type'));
        }
        if (is_string($request->query('result')) && $request->query('result') !== '') {
            $query->where('result', $request->query('result'));
        }

        $search = $request->query('search');
        if (is_string($search) && trim($search) !== '') {
            $term = trim($search);
            $query->where(function ($builder) use ($term): void {
                $builder->where('public_id', 'ilike', '%'.$term.'%')
                    ->orWhere('subject_type', 'ilike', '%'.$term.'%')
                    ->orWhere('subject_public_id', 'ilike', '%'.$term.'%')
                    ->orWhere('context_type', 'ilike', '%'.$term.'%')
                    ->orWhere('policy_public_id', 'ilike', '%'.$term.'%')
                    ->orWhere('result', 'ilike', '%'.$term.'%')
                    ->orWhere('block_reason', 'ilike', '%'.$term.'%');
            });
        }

        $perPage = min(max($request->integer('per_page', 25), 1), 100);
        $page = max($request->integer('page', 1), 1);
        $total = (clone $query)->count();
        $rows = $query->forPage($page, $perPage)->get();

        return $this->respondSuccess([
            'screening_results' => $rows->map(fn (object $row): array => $this->resultPayload($row))->all(),
        ], 'Screening results retrieved', meta: [
            'pagination' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => (int) ceil(max(1, $total) / $perPage),
            ],
        ]);
    }

    public function showResult(Request $request, string $resultPublicId): JsonResponse
    {
        if (! $this->requirePlatformAdmin($request)) {
            return $this->respondForbidden();
        }
        $row = DB::table('islamic_screening_results')->where('public_id', $resultPublicId)->first();
        if (! is_object($row)) {
            return $this->respondNotFound('Screening result not found.');
        }

        return $this->respondSuccess($this->resultPayload($row), 'Screening result retrieved');
    }

    private function transition(Request $request, string $policyPublicId, string $status): JsonResponse
    {
        if (! $this->requirePlatformAdmin($request)) {
            return $this->respondForbidden();
        }
        $actor = $request->user();
        if (! $actor instanceof User) {
            return $this->respondForbidden();
        }
        $policy = DB::table('islamic_screening_policies')->where('public_id', $policyPublicId)->first();
        if (! is_object($policy)) {
            return $this->respondNotFound('Screening policy not found.');
        }

        if ($status === 'active') {
            $ruleCount = DB::table('islamic_screening_policy_rules')->where('policy_id', (int) $policy->id)->where('is_active', true)->count();
            if ($ruleCount < 1) {
                return $this->respondUnprocessable(errors: ['islamic_screening_policy' => [__('At least one active rule is required before activation.')]]);
            }
            $workflow = $this->approvalWorkflows->workflowFor(IslamicApprovalStateMachine::SUBJECT_SCREENING_POLICY, $policyPublicId);
            $state = is_object($workflow) && is_string($workflow->current_state ?? null) ? $workflow->current_state : null;
            if ($state !== IslamicApprovalStateMachine::STATE_APPROVED) {
                return $this->respondUnprocessable(errors: ['islamic_screening_policy' => [__('Screening policy approval workflow must be approved before activation.')]]);
            }
            $effectiveFrom = is_string($policy->effective_from ?? null) ? $policy->effective_from : null;
            $effectiveTo = is_string($policy->effective_to ?? null) ? $policy->effective_to : null;
            $today = now()->toDateString();
            if (($effectiveFrom !== null && $effectiveFrom > $today) || ($effectiveTo !== null && $effectiveTo <= $today)) {
                return $this->respondUnprocessable(errors: ['islamic_screening_policy' => [__('Policy effective window is not active.')]]);
            }
        }

        DB::table('islamic_screening_policies')->where('id', (int) $policy->id)->update([
            'status' => $status,
            'updated_at' => now(),
        ]);
        $updated = DB::table('islamic_screening_policies')->where('id', (int) $policy->id)->first();
        if (! is_object($updated)) {
            throw new InvalidArgumentException('Screening policy could not be reloaded.');
        }
        $this->securityAudit->record('islamic.screening_policy.'.$status, actor: $actor, properties: [
            'policy_public_id' => (string) $updated->public_id,
        ], request: $request);

        return $this->respondSuccess($this->policyPayload($updated), 'Screening policy updated');
    }

    private function requirePlatformAdmin(Request $request): bool
    {
        $actor = $request->user();

        return $actor instanceof User && $actor->hasRole('platform-admin');
    }

    private function upsertRule(Request $request, string $policyPublicId, ?string $rulePublicId): JsonResponse
    {
        if (! $this->requirePlatformAdmin($request)) {
            return $this->respondForbidden();
        }
        $policy = DB::table('islamic_screening_policies')->where('public_id', $policyPublicId)->first();
        if (! is_object($policy)) {
            return $this->respondNotFound('Screening policy not found.');
        }
        $validated = Validator::make($request->all(), [
            'rule_type' => ['required', Rule::in([
                'prohibited_sector',
                'restricted_sector',
                'prohibited_goods',
                'restricted_goods',
                'supplier_flag',
                'customer_business_flag',
                'source_of_funds_flag',
                'use_of_funds_flag',
                'escalation_rule',
            ])],
            'match_key' => ['required', 'string', 'max:128'],
            'match_operator' => ['sometimes', Rule::in(['equals', 'contains', 'starts_with', 'regex'])],
            'risk_level' => ['sometimes', 'nullable', Rule::in(['low', 'medium', 'high', 'critical'])],
            'action' => ['required', Rule::in(['block', 'manual_review', 'allow_with_note'])],
            'priority' => ['sometimes', 'integer', 'min:1'],
            'is_active' => ['sometimes', 'boolean'],
            'metadata' => ['sometimes', 'nullable', 'array'],
        ])->validate();
        if (($validated['match_operator'] ?? 'equals') === 'regex' && @preg_match((string) $validated['match_key'], '') === false) {
            return $this->respondUnprocessable(errors: ['islamic_screening_policy_rule' => [__('Invalid regex pattern in match_key.')]]);
        }

        if ($rulePublicId === null) {
            $id = DB::table('islamic_screening_policy_rules')->insertGetId([
                'public_id' => (string) Str::ulid(),
                'policy_id' => (int) $policy->id,
                'rule_type' => (string) $validated['rule_type'],
                'match_key' => (string) $validated['match_key'],
                'match_operator' => is_string($validated['match_operator'] ?? null) ? $validated['match_operator'] : 'equals',
                'risk_level' => is_string($validated['risk_level'] ?? null) ? $validated['risk_level'] : null,
                'action' => (string) $validated['action'],
                'priority' => is_numeric($validated['priority'] ?? null) ? (int) $validated['priority'] : 100,
                'is_active' => array_key_exists('is_active', $validated) ? (bool) $validated['is_active'] : true,
                'metadata' => is_array($validated['metadata'] ?? null) ? json_encode($validated['metadata'], JSON_THROW_ON_ERROR) : null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } else {
            DB::table('islamic_screening_policy_rules')
                ->where('policy_id', (int) $policy->id)
                ->where('public_id', $rulePublicId)
                ->update([
                    'rule_type' => (string) $validated['rule_type'],
                    'match_key' => (string) $validated['match_key'],
                    'match_operator' => is_string($validated['match_operator'] ?? null) ? $validated['match_operator'] : 'equals',
                    'risk_level' => is_string($validated['risk_level'] ?? null) ? $validated['risk_level'] : null,
                    'action' => (string) $validated['action'],
                    'priority' => is_numeric($validated['priority'] ?? null) ? (int) $validated['priority'] : 100,
                    'is_active' => array_key_exists('is_active', $validated) ? (bool) $validated['is_active'] : true,
                    'metadata' => is_array($validated['metadata'] ?? null) ? json_encode($validated['metadata'], JSON_THROW_ON_ERROR) : null,
                    'updated_at' => now(),
                ]);
            $id = DB::table('islamic_screening_policy_rules')
                ->where('policy_id', (int) $policy->id)
                ->where('public_id', $rulePublicId)
                ->value('id');
        }
        $ruleId = is_numeric($id) ? (int) $id : null;
        if ($ruleId === null) {
            return $this->respondUnprocessable(errors: ['islamic_screening_policy_rule' => [__('Rule id is invalid after save.')]]);
        }
        $rule = DB::table('islamic_screening_policy_rules')->where('id', $ruleId)->first();
        if (! is_object($rule)) {
            return $this->respondUnprocessable(errors: ['islamic_screening_policy_rule' => [__('Rule could not be reloaded.')]]);
        }

        return $this->respondSuccess([
            'public_id' => (string) $rule->public_id,
            'rule_type' => (string) $rule->rule_type,
            'match_key' => (string) $rule->match_key,
            'match_operator' => (string) $rule->match_operator,
            'risk_level' => is_string($rule->risk_level ?? null) ? $rule->risk_level : null,
            'action' => (string) $rule->action,
            'priority' => (int) $rule->priority,
            'is_active' => (bool) $rule->is_active,
        ], 'Screening policy rule saved');
    }

    /** @return array<string,mixed> */
    private function policyPayload(object $row): array
    {
        return [
            'public_id' => $this->rowString($row, 'public_id'),
            'code' => $this->rowString($row, 'code'),
            'name' => $this->rowString($row, 'name'),
            'version' => $this->rowInt($row, 'version'),
            'scope_type' => $this->rowString($row, 'scope_type'),
            'scope_value' => $this->nullableString(((array) $row)['scope_value'] ?? null),
            'status' => $this->rowString($row, 'status'),
            'effective_from' => $this->nullableString(((array) $row)['effective_from'] ?? null),
            'effective_to' => $this->nullableString(((array) $row)['effective_to'] ?? null),
            'description' => $this->nullableString(((array) $row)['description'] ?? null),
        ];
    }

    /** @return array<string,mixed> */
    private function resultPayload(object $row): array
    {
        $snapshotRaw = $this->nullableString(((array) $row)['policy_snapshot'] ?? null);
        $snapshot = $snapshotRaw !== null ? json_decode($snapshotRaw, true) : null;
        $matchedRulesRaw = $this->nullableString(((array) $row)['matched_rules'] ?? null);

        return [
            'public_id' => $this->rowString($row, 'public_id'),
            'subject_type' => $this->rowString($row, 'subject_type'),
            'subject_public_id' => $this->rowString($row, 'subject_public_id'),
            'context_type' => $this->rowString($row, 'context_type'),
            'result' => $this->rowString($row, 'result'),
            'policy_public_id' => $this->rowString($row, 'policy_public_id'),
            'policy_version' => $this->rowInt($row, 'policy_version'),
            'policy_snapshot' => is_array($snapshot) ? $snapshot : null,
            'policy_snapshot_checksum' => hash('sha256', $snapshotRaw ?? ''),
            'matched_rules' => is_string($matchedRulesRaw) ? json_decode($matchedRulesRaw, true) : null,
            'block_reason' => $this->nullableString(((array) $row)['block_reason'] ?? null),
            'review_case_public_id' => $this->nullableString(((array) $row)['review_case_public_id'] ?? null),
            'evaluated_at' => $this->nullableString(((array) $row)['evaluated_at'] ?? null),
        ];
    }

    private function rowString(object $row, string $key): string
    {
        $value = ((array) $row)[$key] ?? '';

        return is_string($value) ? $value : (is_numeric($value) || is_bool($value) ? (string) $value : '');
    }

    private function rowInt(object $row, string $key): int
    {
        $value = ((array) $row)[$key] ?? null;

        return is_numeric($value) ? (int) $value : 0;
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_string($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value) || is_bool($value)) {
            return (string) $value;
        }

        return null;
    }
}
