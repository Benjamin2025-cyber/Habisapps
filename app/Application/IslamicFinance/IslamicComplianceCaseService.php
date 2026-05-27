<?php

declare(strict_types=1);

namespace App\Application\IslamicFinance;

use App\Models\User;
use App\Support\Security\SecurityAudit;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class IslamicComplianceCaseService
{
    public const SUBJECT_PRODUCT = 'islamic_product';

    public const BLOCKER_PRODUCT_ACTIVATION = 'product_activation';

    public const DECISION_APPROVED = 'approved';

    public const DECISION_REJECTED = 'rejected';

    public const DECISION_NEEDS_INFORMATION = 'needs_information';

    public const DECISION_CONDITIONALLY_APPROVED = 'conditionally_approved';

    public const DECISION_SUSPENDED = 'suspended';

    public const DECISION_CORRECTIVE_ACTION_REQUIRED = 'corrective_action_required';

    public const DECISION_CORRECTIVE_ACTION_CLOSED = 'corrective_action_closed';

    public function __construct(
        private readonly SecurityAudit $securityAudit,
    ) {}

    /** @param array<string, mixed> $metadata */
    public function openCase(
        string $subjectType,
        string $subjectPublicId,
        string $reasonCode,
        string $riskLevel,
        string $checklistVersion,
        User $actor,
        ?int $assignedReviewerUserId = null,
        ?CarbonInterface $dueAt = null,
        string $blockingMode = 'hard',
        array $metadata = [],
    ): object {
        $this->assertSubjectType($subjectType);
        $this->assertRiskLevel($riskLevel);
        $this->assertBlockingMode($blockingMode);

        $id = DB::table('islamic_compliance_cases')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'subject_type' => $subjectType,
            'subject_public_id' => $subjectPublicId,
            'reason_code' => $reasonCode,
            'risk_level' => $riskLevel,
            'checklist_version' => $checklistVersion,
            'assigned_reviewer_user_id' => $assignedReviewerUserId,
            'due_at' => $dueAt?->toDateTimeString(),
            'status' => 'open',
            'blocking_mode' => $blockingMode,
            'created_by_user_id' => $actor->id,
            'metadata' => $metadata !== [] ? json_encode($metadata, JSON_THROW_ON_ERROR) : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $row = DB::table('islamic_compliance_cases')->where('id', $id)->first();
        if (! is_object($row)) {
            throw new InvalidArgumentException('Compliance case could not be reloaded.');
        }

        $this->securityAudit->record('islamic.compliance_case.opened', actor: $actor, properties: [
            'case_public_id' => $this->rowString($row, 'public_id'),
            'subject_type' => $subjectType,
            'subject_public_id' => $subjectPublicId,
            'reason_code' => $reasonCode,
            'risk_level' => $riskLevel,
        ]);
        if ($assignedReviewerUserId !== null) {
            $this->securityAudit->record('islamic.compliance_case.assigned', actor: $actor, properties: [
                'case_public_id' => $this->rowString($row, 'public_id'),
                'assigned_reviewer_user_id' => $assignedReviewerUserId,
                'due_at' => $dueAt?->toDateTimeString(),
            ]);
        }

        return $row;
    }

    public function addBlocker(
        string $casePublicId,
        string $blockerType,
        string $targetSubjectType,
        string $targetSubjectPublicId,
        User $actor,
    ): object {
        $this->assertBlockerType($blockerType);
        $case = $this->caseByPublicId($casePublicId, true);

        $existing = DB::table('islamic_compliance_case_blockers')
            ->where('case_id', $this->rowInt($case, 'id'))
            ->where('blocker_type', $blockerType)
            ->where('target_subject_type', $targetSubjectType)
            ->where('target_subject_public_id', $targetSubjectPublicId)
            ->where('is_active', true)
            ->first();
        if (is_object($existing)) {
            return $existing;
        }

        $id = DB::table('islamic_compliance_case_blockers')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'case_id' => $this->rowInt($case, 'id'),
            'blocker_type' => $blockerType,
            'target_subject_type' => $targetSubjectType,
            'target_subject_public_id' => $targetSubjectPublicId,
            'is_active' => true,
            'activated_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $row = DB::table('islamic_compliance_case_blockers')->where('id', $id)->first();
        if (! is_object($row)) {
            throw new InvalidArgumentException('Compliance blocker could not be reloaded.');
        }

        $this->securityAudit->record('islamic.compliance_case.blocker_activated', actor: $actor, properties: [
            'case_public_id' => $casePublicId,
            'blocker_public_id' => $this->rowString($row, 'public_id'),
            'blocker_type' => $blockerType,
            'target_subject_type' => $targetSubjectType,
            'target_subject_public_id' => $targetSubjectPublicId,
        ]);

        return $row;
    }

    /** @param array<string, mixed> $context */
    public function isConditionallyUsable(string $casePublicId, ?CarbonInterface $asOf = null, array $context = []): array
    {
        $case = $this->caseByPublicId($casePublicId);
        $asOf = $asOf ?? CarbonImmutable::now();
        $decision = DB::table('islamic_compliance_case_decisions')
            ->where('case_id', $this->rowInt($case, 'id'))
            ->orderByDesc('decided_at')
            ->orderByDesc('id')
            ->first();
        if (! is_object($decision) || $this->rowString($decision, 'decision') !== 'conditionally_approved') {
            return ['ok' => false, 'reasons' => ['Case does not have an active conditional approval decision.']];
        }

        $reasons = [];
        $effectiveFrom = $this->nullableRowString($decision, 'effective_from');
        $effectiveTo = $this->nullableRowString($decision, 'effective_to');

        if ($effectiveFrom !== null && $asOf->lt(CarbonImmutable::parse($effectiveFrom))) {
            $reasons[] = 'Conditional approval is not yet effective.';
        }
        if ($effectiveTo !== null && $asOf->gte(CarbonImmutable::parse($effectiveTo))) {
            $reasons[] = 'Conditional approval has expired.';
        }

        $conditions = $this->decodeJsonObject($this->rowAny($decision, 'conditions')) ?? [];
        $expiresOn = isset($conditions['expires_on']) && is_string($conditions['expires_on']) ? $conditions['expires_on'] : null;
        if ($expiresOn !== null && $asOf->toDateString() >= $expiresOn) {
            $reasons[] = 'Conditional approval expired on '.$expiresOn.'.';
        }

        if (isset($conditions['required_controls']) && is_array($conditions['required_controls']) && $conditions['required_controls'] !== []) {
            $provided = is_array($context['satisfied_controls'] ?? null) ? $context['satisfied_controls'] : [];
            $missing = array_values(array_diff($conditions['required_controls'], $provided));
            if ($missing !== []) {
                $reasons[] = 'Missing required controls: '.implode(', ', $missing).'.';
            }
        }

        return ['ok' => $reasons === [], 'reasons' => $reasons];
    }

    /**
     * @return list<array{case_public_id: string, blocker_public_id: string, reason_code: string, decision: ?string, blocking_mode: string}>
     */
    public function activeBlockerFailures(
        string $blockerType,
        string $targetSubjectType,
        string $targetSubjectPublicId,
        ?CarbonInterface $asOf = null,
    ): array {
        $rows = DB::table('islamic_compliance_case_blockers as b')
            ->join('islamic_compliance_cases as c', 'c.id', '=', 'b.case_id')
            ->where('b.blocker_type', $blockerType)
            ->where('b.target_subject_type', $targetSubjectType)
            ->where('b.target_subject_public_id', $targetSubjectPublicId)
            ->where('b.is_active', true)
            ->whereIn('c.status', ['open', 'in_review', 'blocked', 'resolved'])
            ->orderBy('b.id')
            ->select([
                'b.public_id as blocker_public_id',
                'c.public_id as case_public_id',
                'c.reason_code',
                'c.latest_decision',
                'c.blocking_mode',
            ])
            ->get();

        $failures = [];
        foreach ($rows as $row) {
            if (! is_object($row)) {
                continue;
            }
            if (($row->blocking_mode ?? null) !== 'hard') {
                continue;
            }
            if (($row->latest_decision ?? null) === 'conditionally_approved' && $asOf !== null) {
                $conditional = $this->isConditionallyUsable((string) $row->case_public_id, $asOf);
                if ($conditional['ok']) {
                    continue;
                }
            }

            $failures[] = [
                'case_public_id' => (string) $row->case_public_id,
                'blocker_public_id' => (string) $row->blocker_public_id,
                'reason_code' => (string) $row->reason_code,
                'decision' => is_string($row->latest_decision) ? $row->latest_decision : null,
                'blocking_mode' => (string) $row->blocking_mode,
            ];
        }

        return $failures;
    }

    /** @param array<string, mixed> $options */
    public function recordDecision(string $casePublicId, string $decision, User $actor, array $options = []): object
    {
        $case = $this->caseByPublicId($casePublicId, true);
        $this->assertDecision($decision);
        $this->assertDecisionTransition($case, $decision);

        $conditions = isset($options['conditions']) && is_array($options['conditions']) ? $options['conditions'] : null;
        $effectiveFrom = isset($options['effective_from']) && is_string($options['effective_from']) ? $options['effective_from'] : null;
        $effectiveTo = isset($options['effective_to']) && is_string($options['effective_to']) ? $options['effective_to'] : null;
        if ($effectiveFrom !== null && $effectiveTo !== null && $effectiveTo <= $effectiveFrom) {
            throw new InvalidArgumentException('effective_to must be after effective_from.');
        }
        if ($decision === 'conditionally_approved') {
            $hasExpiresOn = isset($conditions['expires_on']) && is_string($conditions['expires_on']) && $conditions['expires_on'] !== '';
            if (($conditions === null || $conditions === []) || ($effectiveTo === null && ! $hasExpiresOn)) {
                throw new InvalidArgumentException('Conditional approval requires conditions and expiry boundary.');
            }
        }

        $decidedAt = isset($options['decided_at']) && is_string($options['decided_at']) ? CarbonImmutable::parse($options['decided_at']) : CarbonImmutable::now();
        $decisionId = DB::table('islamic_compliance_case_decisions')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'case_id' => $this->rowInt($case, 'id'),
            'decision' => $decision,
            'decision_comments' => isset($options['decision_comments']) && is_string($options['decision_comments']) ? $options['decision_comments'] : null,
            'conditions' => $conditions !== null ? json_encode($conditions, JSON_THROW_ON_ERROR) : null,
            'evidence_document_id' => isset($options['evidence_document_id']) && is_numeric($options['evidence_document_id']) ? (int) $options['evidence_document_id'] : null,
            'decided_by_user_id' => $actor->id,
            'decided_at' => $decidedAt->toDateTimeString(),
            'effective_from' => $effectiveFrom,
            'effective_to' => $effectiveTo,
            'metadata' => isset($options['metadata']) && is_array($options['metadata']) ? json_encode($options['metadata'], JSON_THROW_ON_ERROR) : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $newStatus = $this->statusForDecision($decision);
        $update = [
            'status' => $newStatus,
            'latest_decision' => $decision,
            'latest_decided_at' => $decidedAt->toDateTimeString(),
            'updated_at' => now(),
        ];
        if (in_array($newStatus, ['resolved', 'archived'], true)) {
            $update['closed_at'] = now();
            $update['closed_by_user_id'] = $actor->id;
        }
        DB::table('islamic_compliance_cases')->where('id', $this->rowInt($case, 'id'))->update($update);

        if (in_array($decision, [self::DECISION_APPROVED, self::DECISION_CORRECTIVE_ACTION_CLOSED], true)) {
            $this->releaseAllActiveBlockers($this->rowInt($case, 'id'), $actor, 'Decision allows release.');
        }
        if ($decision === self::DECISION_CONDITIONALLY_APPROVED) {
            $conditional = $this->isConditionallyUsable($casePublicId, CarbonImmutable::now(), []);
            if ($conditional['ok']) {
                // Keep blocker row active for runtime reevaluation and future re-blocking after expiry.
                DB::table('islamic_compliance_cases')->where('id', $this->rowInt($case, 'id'))->update([
                    'status' => 'resolved',
                    'updated_at' => now(),
                ]);
            } else {
                DB::table('islamic_compliance_cases')->where('id', $this->rowInt($case, 'id'))->update([
                    'status' => 'blocked',
                    'updated_at' => now(),
                ]);
            }
        }
        if (in_array($decision, [self::DECISION_CORRECTIVE_ACTION_REQUIRED, self::DECISION_CORRECTIVE_ACTION_CLOSED], true)) {
            $this->securityAudit->record(
                $decision === 'corrective_action_required'
                    ? 'islamic.compliance_case.corrective_action.required'
                    : 'islamic.compliance_case.corrective_action.closed',
                actor: $actor,
                properties: ['case_public_id' => $casePublicId],
            );
        }

        $stored = DB::table('islamic_compliance_case_decisions')->where('id', $decisionId)->first();
        if (! is_object($stored)) {
            throw new InvalidArgumentException('Compliance decision could not be reloaded.');
        }

        $this->securityAudit->record('islamic.compliance_case.decision_recorded', actor: $actor, properties: [
            'case_public_id' => $casePublicId,
            'decision_public_id' => $this->rowString($stored, 'public_id'),
            'decision' => $decision,
        ]);

        return $stored;
    }

    public function releaseBlocker(string $blockerPublicId, User $actor, ?string $reason = null): void
    {
        DB::table('islamic_compliance_case_blockers')
            ->where('public_id', $blockerPublicId)
            ->where('is_active', true)
            ->update([
                'is_active' => false,
                'released_at' => now(),
                'released_by_user_id' => $actor->id,
                'release_reason' => $reason,
                'updated_at' => now(),
            ]);

        $this->securityAudit->record('islamic.compliance_case.blocker_released', actor: $actor, properties: [
            'blocker_public_id' => $blockerPublicId,
            'release_reason' => $reason,
        ]);
    }

    private function releaseAllActiveBlockers(int $caseId, User $actor, string $reason): void
    {
        $blockers = DB::table('islamic_compliance_case_blockers')
            ->where('case_id', $caseId)
            ->where('is_active', true)
            ->get(['public_id']);
        foreach ($blockers as $blocker) {
            if (! is_object($blocker) || ! is_string($blocker->public_id)) {
                continue;
            }
            $this->releaseBlocker($blocker->public_id, $actor, $reason);
        }
    }

    private function caseByPublicId(string $casePublicId, bool $forUpdate = false): object
    {
        $query = DB::table('islamic_compliance_cases')->where('public_id', $casePublicId);
        if ($forUpdate) {
            $query->lockForUpdate();
        }
        $case = $query->first();
        if (! is_object($case)) {
            throw new InvalidArgumentException('Compliance case is invalid.');
        }

        return $case;
    }

    private function statusForDecision(string $decision): string
    {
        return match ($decision) {
            self::DECISION_APPROVED, self::DECISION_CONDITIONALLY_APPROVED, self::DECISION_CORRECTIVE_ACTION_CLOSED => 'resolved',
            self::DECISION_NEEDS_INFORMATION => 'in_review',
            self::DECISION_REJECTED, self::DECISION_SUSPENDED, self::DECISION_CORRECTIVE_ACTION_REQUIRED => 'blocked',
            default => 'open',
        };
    }

    private function assertSubjectType(string $subjectType): void
    {
        $valid = [
            'islamic_product',
            'islamic_financing',
            'islamic_customer',
            'islamic_asset',
            'islamic_goods',
            'islamic_project',
            'islamic_supplier',
            'islamic_account',
            'investment_account',
            'islamic_contract',
            'islamic_transaction',
        ];
        if (! in_array($subjectType, $valid, true)) {
            throw new InvalidArgumentException('Unsupported subject type.');
        }
    }

    private function assertDecision(string $decision): void
    {
        $valid = [
            self::DECISION_APPROVED,
            self::DECISION_REJECTED,
            self::DECISION_NEEDS_INFORMATION,
            self::DECISION_CONDITIONALLY_APPROVED,
            self::DECISION_SUSPENDED,
            self::DECISION_CORRECTIVE_ACTION_REQUIRED,
            self::DECISION_CORRECTIVE_ACTION_CLOSED,
        ];
        if (! in_array($decision, $valid, true)) {
            throw new InvalidArgumentException('Unsupported decision.');
        }
    }

    private function assertDecisionTransition(object $case, string $decision): void
    {
        $latest = $this->nullableRowString($case, 'latest_decision');
        if ($decision === self::DECISION_CORRECTIVE_ACTION_CLOSED && $latest !== self::DECISION_CORRECTIVE_ACTION_REQUIRED) {
            throw new InvalidArgumentException('corrective_action_closed requires prior corrective_action_required.');
        }
    }

    private function assertBlockerType(string $blockerType): void
    {
        $valid = [
            'product_activation',
            'contract_activation',
            'supplier_use',
            'asset_acceptance',
            'goods_acceptance',
            'project_approval',
            'account_pool_assignment',
            'transaction_authorization',
        ];
        if (! in_array($blockerType, $valid, true)) {
            throw new InvalidArgumentException('Unsupported blocker type.');
        }
    }

    private function assertRiskLevel(string $riskLevel): void
    {
        if (! in_array($riskLevel, ['low', 'medium', 'high', 'critical'], true)) {
            throw new InvalidArgumentException('Unsupported risk level.');
        }
    }

    private function assertBlockingMode(string $blockingMode): void
    {
        if (! in_array($blockingMode, ['hard', 'soft'], true)) {
            throw new InvalidArgumentException('Unsupported blocking mode.');
        }
    }

    private function rowInt(object $row, string $column): int
    {
        $value = $row->{$column} ?? null;
        if (! is_numeric($value)) {
            throw new InvalidArgumentException('Expected integer for '.$column.'.');
        }

        return (int) $value;
    }

    private function rowString(object $row, string $column): string
    {
        $value = $row->{$column} ?? null;
        if (! is_string($value) || $value === '') {
            throw new InvalidArgumentException('Expected non-empty string for '.$column.'.');
        }

        return $value;
    }

    private function nullableRowString(object $row, string $column): ?string
    {
        $value = $row->{$column} ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function rowAny(object $row, string $column): mixed
    {
        return $row->{$column} ?? null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeJsonObject(mixed $value): ?array
    {
        if (is_array($value)) {
            return $value;
        }
        if (! is_string($value) || $value === '') {
            return null;
        }
        $decoded = json_decode($value, true);
        if (! is_array($decoded)) {
            return null;
        }

        return $decoded;
    }
}
