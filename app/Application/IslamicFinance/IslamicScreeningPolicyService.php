<?php

declare(strict_types=1);

namespace App\Application\IslamicFinance;

use App\Models\User;
use App\Support\Security\SecurityAudit;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class IslamicScreeningPolicyService
{
    /** @var array<string,string> */
    private const array CONTEXT_BLOCKER_MAP = [
        'product_approval' => 'product_activation',
        'contract_approval' => 'contract_activation',
        'supplier_use' => 'supplier_use',
        'asset_acceptance' => 'asset_acceptance',
        'goods_acceptance' => 'goods_acceptance',
        'project_approval' => 'project_approval',
        'account_pool_assignment' => 'account_pool_assignment',
    ];

    public function __construct(
        private readonly IslamicComplianceCaseService $complianceCases,
        private readonly IslamicApprovalWorkflowService $approvalWorkflows,
        private readonly SecurityAudit $securityAudit,
    ) {}

    public function resolveActivePolicy(string $scopeType, ?string $scopeValue, ?CarbonInterface $asOf = null, ?string $agencyScopeValue = null): ?object
    {
        $asOf = $asOf ?? CarbonImmutable::now();
        $asOfDate = $asOf->toDateString();
        $candidates = [];

        if ($scopeType === 'product_family' && is_string($scopeValue) && $scopeValue !== '') {
            $row = $this->pickActiveByScope('product_family', $scopeValue, $asOfDate);
            if (is_object($row)) {
                $candidates[] = ['rank' => 3, 'row' => $row];
            }
        }
        $agencyScope = is_string($agencyScopeValue) && $agencyScopeValue !== '' ? $agencyScopeValue : $scopeValue;
        if (is_string($agencyScope) && $agencyScope !== '') {
            $row = $this->pickActiveByScope('agency', $agencyScope, $asOfDate);
            if (is_object($row)) {
                $candidates[] = ['rank' => 2, 'row' => $row];
            }
        }
        $institution = $this->pickActiveByScope('institution', null, $asOfDate);
        if (is_object($institution)) {
            $candidates[] = ['rank' => 1, 'row' => $institution];
        }

        if ($candidates === []) {
            return null;
        }

        usort($candidates, static function (array $a, array $b): int {
            if ($a['rank'] !== $b['rank']) {
                return $b['rank'] <=> $a['rank'];
            }

            return ((int) (($b['row']->version ?? 0))) <=> ((int) (($a['row']->version ?? 0)));
        });

        foreach ($candidates as $candidate) {
            $row = $candidate['row'];
            if (! is_string($row->public_id ?? null)) {
                continue;
            }
            $workflow = $this->approvalWorkflows->workflowFor(
                IslamicApprovalStateMachine::SUBJECT_SCREENING_POLICY,
                $row->public_id,
            );
            // Backward compatibility for legacy policies that predate IF-011
            // workflow rows: treat workflow as not-yet-adopted and allow the
            // existing status/effective-window gate to decide.
            if (! is_object($workflow)) {
                return $row;
            }
            $workflowUsability = $this->approvalWorkflows->isUsableForNewActions(
                IslamicApprovalStateMachine::SUBJECT_SCREENING_POLICY,
                $row->public_id,
                $asOf,
            );
            if ($workflowUsability['ok'] === true) {
                return $row;
            }
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $facts
     * @return array<string,mixed>
     */
    public function evaluate(string $subjectType, string $subjectPublicId, string $contextType, array $facts, ?User $actor = null, bool $strictPolicy = false, ?string $overrideExceptionSubjectPublicId = null): array
    {
        $this->assertKnownContextType($contextType);
        $asOf = CarbonImmutable::now();
        $scopeType = is_string($facts['scope_type'] ?? null) ? $facts['scope_type'] : 'institution';
        $scopeValue = is_string($facts['scope_value'] ?? null) ? $facts['scope_value'] : null;
        $agencyScopeValue = is_string($facts['agency_scope_value'] ?? null) ? $facts['agency_scope_value'] : null;

        $policy = $this->resolveActivePolicy($scopeType, $scopeValue, $asOf, $agencyScopeValue);
        if (! is_object($policy)) {
            if ($strictPolicy) {
                return $this->persistResult($subjectType, $subjectPublicId, $contextType, null, 'fail', [], 'No active screening policy for strict context.', null, $actor);
            }

            return $this->persistResult($subjectType, $subjectPublicId, $contextType, null, 'not_applicable', [], null, null, $actor);
        }

        $overrideAllowed = false;
        if ($overrideExceptionSubjectPublicId !== null && $overrideExceptionSubjectPublicId !== '') {
            $override = $this->approvalWorkflows->isUsableForNewActions(IslamicApprovalStateMachine::SUBJECT_EXCEPTION, $overrideExceptionSubjectPublicId, $asOf);
            if (! $override['ok']) {
                $this->securityAudit->record('islamic.screening.override_denied', actor: $actor, properties: [
                    'subject_type' => $subjectType,
                    'subject_public_id' => $subjectPublicId,
                    'context_type' => $contextType,
                    'override_exception_subject_public_id' => $overrideExceptionSubjectPublicId,
                    'reasons' => $override['reasons'],
                ]);
                throw new InvalidArgumentException('Manual override requires approved exception workflow.');
            }
            $this->securityAudit->record('islamic.screening.override_approved', actor: $actor, properties: [
                'subject_type' => $subjectType,
                'subject_public_id' => $subjectPublicId,
                'context_type' => $contextType,
                'override_exception_subject_public_id' => $overrideExceptionSubjectPublicId,
            ]);
            $overrideAllowed = true;
        }

        $rules = DB::table('islamic_screening_policy_rules')
            ->where('policy_id', $this->rowInt($policy, 'id'))
            ->where('is_active', true)
            ->orderBy('priority')
            ->orderBy('id')
            ->get();

        $matched = [];
        $final = 'pass';
        $blockReason = null;
        foreach ($rules as $rule) {
            if (! $this->ruleMatches($rule, $facts)) {
                continue;
            }
            $ruleAction = $this->rowString($rule, 'action');
            $ruleType = $this->rowString($rule, 'rule_type');
            $ruleMatchKey = $this->rowString($rule, 'match_key');
            $matched[] = [
                'rule_public_id' => $this->rowString($rule, 'public_id'),
                'rule_type' => $ruleType,
                'match_key' => $ruleMatchKey,
                'action' => $ruleAction,
                'priority' => $this->rowInt($rule, 'priority'),
            ];
            if ($ruleAction === 'block') {
                $final = 'fail';
                $blockReason = 'Matched prohibited screening rule '.$ruleType.':'.$ruleMatchKey.'.';
                break;
            }
            if ($ruleAction === 'manual_review' && $final === 'pass') {
                $final = 'manual_review';
            }
        }

        if ($overrideAllowed && in_array($final, ['fail', 'manual_review'], true)) {
            $blockReason = 'Override approved through exception workflow '.$overrideExceptionSubjectPublicId.'.';
            $final = 'pass';
        }

        $reviewCasePublicId = null;
        if ($final === 'manual_review') {
            if (! $actor instanceof User) {
                throw new InvalidArgumentException('Manual review routing requires an authenticated actor.');
            }
            $reasonCode = 'screening_restricted_match';
            $existingCase = DB::table('islamic_compliance_cases')
                ->where('subject_type', $subjectType)
                ->where('subject_public_id', $subjectPublicId)
                ->where('reason_code', $reasonCode)
                ->whereIn('status', ['open', 'in_review', 'blocked', 'resolved'])
                ->orderByDesc('id')
                ->first();
            if (is_object($existingCase) && is_string($existingCase->public_id ?? null)) {
                $reviewCasePublicId = $existingCase->public_id;
            } else {
                try {
                    $case = $this->complianceCases->openCase(
                        subjectType: $subjectType,
                        subjectPublicId: $subjectPublicId,
                        reasonCode: $reasonCode,
                        riskLevel: 'high',
                        checklistVersion: 'screening-v1',
                        actor: $actor,
                        assignedReviewerUserId: null,
                        dueAt: null,
                        blockingMode: 'hard',
                        metadata: ['context_type' => $contextType],
                    );
                    $reviewCasePublicId = $this->rowString($case, 'public_id');
                } catch (UniqueConstraintViolationException) {
                    $racedCase = DB::table('islamic_compliance_cases')
                        ->where('subject_type', $subjectType)
                        ->where('subject_public_id', $subjectPublicId)
                        ->where('reason_code', $reasonCode)
                        ->whereIn('status', ['open', 'in_review', 'blocked', 'resolved'])
                        ->orderByDesc('id')
                        ->first();
                    if (! is_object($racedCase) || ! is_string($racedCase->public_id ?? null)) {
                        throw new InvalidArgumentException('Restricted-match compliance case could not be reloaded after race.');
                    }
                    $reviewCasePublicId = $racedCase->public_id;
                }
            }
            $this->complianceCases->addBlocker(
                casePublicId: $reviewCasePublicId,
                blockerType: $this->blockerTypeForContext($contextType),
                targetSubjectType: $subjectType,
                targetSubjectPublicId: $subjectPublicId,
                actor: $actor,
            );
            $this->securityAudit->record('islamic.screening.manual_review_routed', actor: $actor, properties: [
                'subject_type' => $subjectType,
                'subject_public_id' => $subjectPublicId,
                'context_type' => $contextType,
                'review_case_public_id' => $reviewCasePublicId,
            ]);
        }

        if ($final === 'fail') {
            $this->securityAudit->record('islamic.screening.blocked', actor: $actor, properties: [
                'subject_type' => $subjectType,
                'subject_public_id' => $subjectPublicId,
                'context_type' => $contextType,
                'block_reason' => $blockReason,
            ]);
        }

        return $this->persistResult(
            subjectType: $subjectType,
            subjectPublicId: $subjectPublicId,
            contextType: $contextType,
            policy: $policy,
            result: $final,
            matchedRules: $matched,
            blockReason: $blockReason,
            reviewCasePublicId: $reviewCasePublicId,
            actor: $actor,
        );
    }

    /**
     * @param  array<string,mixed>  $facts
     * @return array<string,mixed>
     */
    public function evaluateForAction(string $subjectType, string $subjectPublicId, string $contextType, array $facts, ?User $actor = null, bool $strictPolicy = true): array
    {
        $result = $this->evaluate(
            subjectType: $subjectType,
            subjectPublicId: $subjectPublicId,
            contextType: $contextType,
            facts: $facts,
            actor: $actor,
            strictPolicy: $strictPolicy,
        );
        $resultValue = is_string($result['result'] ?? null) ? $result['result'] : 'unknown';
        if ($resultValue !== 'pass') {
            throw new InvalidArgumentException('Screening result must be pass before action. Received '.$resultValue.'.');
        }

        return $result;
    }

    /**
     * @param  list<array<string,mixed>>  $matchedRules
     * @return array<string,mixed>
     */
    private function persistResult(
        string $subjectType,
        string $subjectPublicId,
        string $contextType,
        ?object $policy,
        string $result,
        array $matchedRules,
        ?string $blockReason,
        ?string $reviewCasePublicId,
        ?User $actor,
    ): array {
        $snapshot = $policy === null ? ['policy' => null, 'rules' => []] : [
            'policy' => [
                'public_id' => $this->rowString($policy, 'public_id'),
                'code' => $this->rowString($policy, 'code'),
                'name' => $this->rowString($policy, 'name'),
                'version' => $this->rowInt($policy, 'version'),
                'scope_type' => $this->rowString($policy, 'scope_type'),
                'scope_value' => $this->rowNullableString($policy, 'scope_value'),
                'effective_from' => $this->rowNullableString($policy, 'effective_from'),
                'effective_to' => $this->rowNullableString($policy, 'effective_to'),
            ],
            'rules' => DB::table('islamic_screening_policy_rules')
                ->where('policy_id', $this->rowInt($policy, 'id'))
                ->orderBy('priority')
                ->orderBy('id')
                ->get(['public_id', 'rule_type', 'match_key', 'match_operator', 'risk_level', 'action', 'priority', 'is_active', 'metadata'])
                ->map(fn (object $rule): array => [
                    'public_id' => $this->rowString($rule, 'public_id'),
                    'rule_type' => $this->rowString($rule, 'rule_type'),
                    'match_key' => $this->rowString($rule, 'match_key'),
                    'match_operator' => $this->rowString($rule, 'match_operator'),
                    'risk_level' => $this->rowNullableString($rule, 'risk_level'),
                    'action' => $this->rowString($rule, 'action'),
                    'priority' => $this->rowInt($rule, 'priority'),
                    'is_active' => $this->rowBool($rule, 'is_active'),
                ])->all(),
        ];
        $policyPublicId = is_object($policy) ? $this->rowString($policy, 'public_id') : 'none';
        $policyVersion = is_object($policy) ? $this->rowInt($policy, 'version') : 0;

        $id = DB::table('islamic_screening_results')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'subject_type' => $subjectType,
            'subject_public_id' => $subjectPublicId,
            'context_type' => $contextType,
            'policy_public_id' => $policyPublicId,
            'policy_version' => $policyVersion,
            'policy_snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR),
            'result' => $result,
            'matched_rules' => $matchedRules !== [] ? json_encode($matchedRules, JSON_THROW_ON_ERROR) : null,
            'block_reason' => $blockReason,
            'review_case_public_id' => $reviewCasePublicId,
            'evaluated_at' => now(),
            'evaluated_by_user_id' => $actor?->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $stored = DB::table('islamic_screening_results')->where('id', $id)->first();
        if (! is_object($stored)) {
            throw new InvalidArgumentException('Screening result could not be reloaded.');
        }

        $this->securityAudit->record('islamic.screening.evaluated', actor: $actor, properties: [
            'result_public_id' => $this->rowString($stored, 'public_id'),
            'subject_type' => $subjectType,
            'subject_public_id' => $subjectPublicId,
            'context_type' => $contextType,
            'result' => $result,
            'policy_public_id' => $policyPublicId,
            'policy_version' => $policyVersion,
        ]);

        return [
            'public_id' => $this->rowString($stored, 'public_id'),
            'result' => $result,
            'policy_public_id' => $policyPublicId,
            'policy_version' => $policyVersion,
            'policy_snapshot' => $snapshot,
            'matched_rules' => $matchedRules,
            'block_reason' => $blockReason,
            'review_case_public_id' => $reviewCasePublicId,
        ];
    }

    private function pickActiveByScope(string $scopeType, ?string $scopeValue, string $asOfDate): ?object
    {
        $query = DB::table('islamic_screening_policies')
            ->where('scope_type', $scopeType)
            ->where('status', 'active')
            ->where(function ($q) use ($asOfDate): void {
                $q->whereNull('effective_from')->orWhere('effective_from', '<=', $asOfDate);
            })
            ->where(function ($q) use ($asOfDate): void {
                $q->whereNull('effective_to')->orWhere('effective_to', '>', $asOfDate);
            });
        if ($scopeType === 'institution') {
            $query->whereNull('scope_value');
        } else {
            $query->where('scope_value', $scopeValue);
        }

        return $query->orderByDesc('version')->orderByDesc('id')->first();
    }

    /** @param array<string,mixed> $facts */
    private function ruleMatches(object $rule, array $facts): bool
    {
        $ruleType = $this->rowString($rule, 'rule_type');
        $operator = $this->rowString($rule, 'match_operator');
        if ($operator === '') {
            $operator = 'equals';
        }
        $key = $this->rowString($rule, 'match_key');
        $haystack = $this->factValuesByRuleType($ruleType, $facts);
        foreach ($haystack as $value) {
            if ($this->matches($operator, $value, $key)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $facts
     * @return list<string>
     */
    private function factValuesByRuleType(string $ruleType, array $facts): array
    {
        $map = [
            'prohibited_sector' => 'sector_codes',
            'restricted_sector' => 'sector_codes',
            'prohibited_goods' => 'goods_codes',
            'restricted_goods' => 'goods_codes',
            'supplier_flag' => 'supplier_flags',
            'customer_business_flag' => 'customer_business_flags',
            'source_of_funds_flag' => 'source_of_funds_flags',
            'use_of_funds_flag' => 'use_of_funds_flags',
            'escalation_rule' => 'escalation_flags',
        ];
        $factKey = $map[$ruleType] ?? null;
        if ($factKey === null) {
            return [];
        }
        $values = $facts[$factKey] ?? [];
        if (is_string($values) && $values !== '') {
            return [$values];
        }
        if (! is_array($values)) {
            return [];
        }
        $result = [];
        foreach ($values as $value) {
            if (is_scalar($value) && (string) $value !== '') {
                $result[] = (string) $value;
            }
        }

        return $result;
    }

    private function matches(string $operator, string $value, string $matchKey): bool
    {
        return match ($operator) {
            'equals' => $value === $matchKey,
            'contains' => str_contains($value, $matchKey),
            'starts_with' => str_starts_with($value, $matchKey),
            'regex' => @preg_match($matchKey, $value) === 1,
            default => false,
        };
    }

    /**
     * @return list<string>
     */
    public static function allowedContextTypes(): array
    {
        return array_keys(self::CONTEXT_BLOCKER_MAP);
    }

    private function blockerTypeForContext(string $contextType): string
    {
        $this->assertKnownContextType($contextType);

        return self::CONTEXT_BLOCKER_MAP[$contextType];
    }

    private function assertKnownContextType(string $contextType): void
    {
        if (! array_key_exists($contextType, self::CONTEXT_BLOCKER_MAP)) {
            throw new InvalidArgumentException('Unknown screening context type: '.$contextType.'.');
        }
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

    private function rowBool(object $row, string $key): bool
    {
        return (bool) (((array) $row)[$key] ?? false);
    }

    private function rowNullableString(object $row, string $key): ?string
    {
        $value = ((array) $row)[$key] ?? null;
        if ($value === null || $value === '') {
            return null;
        }
        if (is_string($value)) {
            return $value;
        }
        if (is_numeric($value) || is_bool($value)) {
            return (string) $value;
        }

        return null;
    }
}
