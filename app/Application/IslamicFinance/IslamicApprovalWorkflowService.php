<?php

declare(strict_types=1);

namespace App\Application\IslamicFinance;

use App\Models\User;
use App\Support\Security\SecurityAudit;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Reusable approval-state engine for IF-011.
 *
 * One workflow per (subject_type, subject_public_id). Decisions are immutable.
 * `isUsableForNewActions` is the central gate that all callers must consult before
 * permitting new use of an Islamic subject (product, template, policy, mapping, etc.).
 */
final class IslamicApprovalWorkflowService
{
    public function __construct(
        private readonly IslamicApprovalStateMachine $stateMachine,
        private readonly IslamicShariaAuthorityService $shariaAuthority,
        private readonly SecurityAudit $securityAudit,
    ) {}

    /**
     * Ensure a workflow row exists for the subject; returns the row.
     */
    public function ensureWorkflow(
        string $subjectType,
        string $subjectPublicId,
        User $actor,
        ?Request $request = null,
    ): object {
        $this->stateMachine->assertSubjectType($subjectType);
        if ($subjectPublicId === '') {
            throw new InvalidArgumentException('Subject public id is required.');
        }

        $existing = DB::table('islamic_approval_workflows')
            ->where('subject_type', $subjectType)
            ->where('subject_public_id', $subjectPublicId)
            ->first();
        if (is_object($existing)) {
            return $existing;
        }

        try {
            $id = DB::table('islamic_approval_workflows')->insertGetId([
                'public_id' => (string) Str::ulid(),
                'subject_type' => $subjectType,
                'subject_public_id' => $subjectPublicId,
                'current_state' => IslamicApprovalStateMachine::STATE_DRAFT,
                'is_blocking' => true,
                'version' => 1,
                'created_by_user_id' => $actor->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            // Concurrent caller raced ahead. The unique (subject_type, subject_public_id)
            // index guarantees exactly one workflow per subject; resolve to that row.
            $racedRow = DB::table('islamic_approval_workflows')
                ->where('subject_type', $subjectType)
                ->where('subject_public_id', $subjectPublicId)
                ->first();
            if (! is_object($racedRow)) {
                throw new InvalidArgumentException('Approval workflow could not be reloaded after race.');
            }

            return $racedRow;
        }

        $row = DB::table('islamic_approval_workflows')->where('id', $id)->first();
        if (! is_object($row)) {
            throw new InvalidArgumentException('Approval workflow could not be reloaded.');
        }

        $this->securityAudit->record('islamic.approval_workflow.created', actor: $actor, properties: [
            'workflow_public_id' => $this->rowString($row, 'public_id'),
            'subject_type' => $subjectType,
            'subject_public_id' => $subjectPublicId,
        ], request: $request);

        return $row;
    }

    /**
     * @param  array{
     *   comments?: string|null,
     *   conditions?: array<string, mixed>|null,
     *   evidence_document_public_id?: string|null,
     *   effective_from?: string|null,
     *   effective_to?: string|null,
     *   metadata?: array<string, mixed>|null,
     *   requester_user_id?: int|null,
     * } $options
     */
    public function submit(string $subjectType, string $subjectPublicId, User $actor, array $options = [], ?Request $request = null): object
    {
        return $this->applyDecision($subjectType, $subjectPublicId, $actor, IslamicApprovalStateMachine::DECISION_SUBMIT, $options, $request);
    }

    /**
     * @param  array{
     *   comments?: string|null,
     *   conditions?: array<string, mixed>|null,
     *   evidence_document_public_id?: string|null,
     *   effective_from?: string|null,
     *   effective_to?: string|null,
     *   metadata?: array<string, mixed>|null,
     *   requester_user_id?: int|null,
     * } $options
     */
    public function approve(string $subjectType, string $subjectPublicId, User $actor, array $options = [], ?Request $request = null): object
    {
        return $this->applyDecision($subjectType, $subjectPublicId, $actor, IslamicApprovalStateMachine::DECISION_APPROVE, $options, $request);
    }

    /**
     * @param  array{
     *   comments?: string|null,
     *   conditions?: array<string, mixed>|null,
     *   evidence_document_public_id?: string|null,
     *   effective_from?: string|null,
     *   effective_to?: string|null,
     *   metadata?: array<string, mixed>|null,
     *   requester_user_id?: int|null,
     * } $options
     */
    public function reject(string $subjectType, string $subjectPublicId, User $actor, array $options = [], ?Request $request = null): object
    {
        return $this->applyDecision($subjectType, $subjectPublicId, $actor, IslamicApprovalStateMachine::DECISION_REJECT, $options, $request);
    }

    /**
     * @param  array{
     *   comments?: string|null,
     *   conditions?: array<string, mixed>|null,
     *   evidence_document_public_id?: string|null,
     *   effective_from?: string|null,
     *   effective_to?: string|null,
     *   metadata?: array<string, mixed>|null,
     *   requester_user_id?: int|null,
     * } $options
     */
    public function suspend(string $subjectType, string $subjectPublicId, User $actor, array $options = [], ?Request $request = null): object
    {
        return $this->applyDecision($subjectType, $subjectPublicId, $actor, IslamicApprovalStateMachine::DECISION_SUSPEND, $options, $request);
    }

    /**
     * @param  array{
     *   comments?: string|null,
     *   conditions?: array<string, mixed>|null,
     *   evidence_document_public_id?: string|null,
     *   effective_from?: string|null,
     *   effective_to?: string|null,
     *   metadata?: array<string, mixed>|null,
     *   requester_user_id?: int|null,
     * } $options
     */
    public function revoke(string $subjectType, string $subjectPublicId, User $actor, array $options = [], ?Request $request = null): object
    {
        return $this->applyDecision($subjectType, $subjectPublicId, $actor, IslamicApprovalStateMachine::DECISION_REVOKE, $options, $request);
    }

    /**
     * @param  array{
     *   comments?: string|null,
     *   conditions?: array<string, mixed>|null,
     *   evidence_document_public_id?: string|null,
     *   effective_from?: string|null,
     *   effective_to?: string|null,
     *   metadata?: array<string, mixed>|null,
     *   requester_user_id?: int|null,
     * } $options
     */
    public function expire(string $subjectType, string $subjectPublicId, User $actor, array $options = [], ?Request $request = null): object
    {
        return $this->applyDecision($subjectType, $subjectPublicId, $actor, IslamicApprovalStateMachine::DECISION_EXPIRE, $options, $request);
    }

    /**
     * @param  array{
     *   comments?: string|null,
     *   conditions?: array<string, mixed>|null,
     *   evidence_document_public_id?: string|null,
     *   effective_from?: string|null,
     *   effective_to?: string|null,
     *   metadata?: array<string, mixed>|null,
     *   requester_user_id?: int|null,
     * } $options
     */
    public function archive(string $subjectType, string $subjectPublicId, User $actor, array $options = [], ?Request $request = null): object
    {
        return $this->applyDecision($subjectType, $subjectPublicId, $actor, IslamicApprovalStateMachine::DECISION_ARCHIVE, $options, $request);
    }

    /**
     * Central gate: is this subject usable for new actions at `asOf`?
     *
     * @return array{ok: bool, state: string, reasons: list<string>}
     */
    public function isUsableForNewActions(string $subjectType, string $subjectPublicId, ?CarbonInterface $asOf = null): array
    {
        $this->stateMachine->assertSubjectType($subjectType);
        $asOf = $asOf ?? CarbonImmutable::now();
        $asOfDate = $asOf->toDateString();

        $workflow = DB::table('islamic_approval_workflows')
            ->where('subject_type', $subjectType)
            ->where('subject_public_id', $subjectPublicId)
            ->first();
        if (! is_object($workflow)) {
            return [
                'ok' => false,
                'state' => IslamicApprovalStateMachine::STATE_DRAFT,
                'reasons' => ['No approval workflow exists for subject.'],
            ];
        }

        $state = $this->rowString($workflow, 'current_state');
        if (! $this->stateMachine->isUsableState($state)) {
            return [
                'ok' => false,
                'state' => $state,
                'reasons' => ['Subject state '.$state.' is not usable for new actions.'],
            ];
        }

        $reasons = [];

        $effectiveFrom = $this->nullableRowString($workflow, 'effective_from');
        $effectiveTo = $this->nullableRowString($workflow, 'effective_to');
        if ($effectiveFrom !== null && $effectiveFrom > $asOfDate) {
            $reasons[] = 'Approval is not yet effective (effective_from='.$effectiveFrom.').';
        }
        if ($effectiveTo !== null && $effectiveTo <= $asOfDate) {
            $reasons[] = 'Approval window has expired (effective_to='.$effectiveTo.').';
        }

        $conditionsRaw = $this->rowAny($workflow, 'conditions');
        $conditions = $this->decodeJsonObject($conditionsRaw);
        if ($conditions !== null) {
            $expiresOn = isset($conditions['expires_on']) && is_string($conditions['expires_on']) ? $conditions['expires_on'] : null;
            if ($expiresOn !== null && $expiresOn <= $asOfDate) {
                $reasons[] = 'Conditional approval expired on '.$expiresOn.'.';
            }
            // Per Phase 5 policy, every condition key the central gate cannot
            // evaluate without caller context is deny-by-default. Callers with
            // the missing context (notional, agency, controls/documents) must
            // call the future richer evaluator helper to override.
            foreach (['required_controls', 'required_documents'] as $listKey) {
                if (isset($conditions[$listKey]) && is_array($conditions[$listKey]) && $conditions[$listKey] !== []) {
                    $reasons[] = 'Conditional approval requires '.$listKey.' which cannot be evaluated by the central gate; caller must verify.';
                }
            }
            if (array_key_exists('max_notional_minor', $conditions) && $conditions['max_notional_minor'] !== null) {
                $reasons[] = 'Conditional approval enforces max_notional_minor which cannot be evaluated by the central gate; caller must verify.';
            }
            if (array_key_exists('allowed_agencies', $conditions) && is_array($conditions['allowed_agencies']) && $conditions['allowed_agencies'] !== []) {
                $reasons[] = 'Conditional approval restricts allowed_agencies which cannot be evaluated by the central gate; caller must verify.';
            }
        }

        if ($reasons !== []) {
            return ['ok' => false, 'state' => $state, 'reasons' => $reasons];
        }

        return ['ok' => true, 'state' => $state, 'reasons' => []];
    }

    /**
     * Apply a decision: lock workflow row, validate transition, write immutable
     * decision log, update workflow state.
     *
     * @param  array<string, mixed>  $options
     */
    public function applyDecision(
        string $subjectType,
        string $subjectPublicId,
        User $actor,
        string $decision,
        array $options = [],
        ?Request $request = null,
    ): object {
        $this->stateMachine->assertSubjectType($subjectType);
        $this->stateMachine->assertDecision($decision);

        $result = DB::transaction(function () use ($subjectType, $subjectPublicId, $actor, $decision, $options): array {
            $workflow = DB::table('islamic_approval_workflows')
                ->where('subject_type', $subjectType)
                ->where('subject_public_id', $subjectPublicId)
                ->lockForUpdate()
                ->first();
            if (! is_object($workflow)) {
                throw new InvalidArgumentException('Approval workflow not found for '.$subjectType.':'.$subjectPublicId.'.');
            }

            $fromState = $this->rowString($workflow, 'current_state');
            $toState = $this->stateMachine->resolveTransition($fromState, $decision);

            $comments = $this->optionString($options, 'comments');
            $conditions = $this->optionArrayOrNull($options, 'conditions');
            $effectiveFrom = $this->optionString($options, 'effective_from');
            $effectiveTo = $this->optionString($options, 'effective_to');
            $metadata = $this->optionArrayOrNull($options, 'metadata');
            $evidenceDocumentId = $this->resolveEvidenceDocumentId($options);
            $requesterUserId = $this->optionNullableInt($options, 'requester_user_id');

            if ($effectiveFrom !== null && $effectiveTo !== null && $effectiveTo <= $effectiveFrom) {
                throw new InvalidArgumentException('effective_to must be after effective_from.');
            }

            $skipAuthority = (bool) ($options['skip_authority_check'] ?? false);
            if ($decision === IslamicApprovalStateMachine::DECISION_APPROVE && $this->stateMachine->isMaterialSubject($subjectType) && ! $skipAuthority) {
                $failures = $this->shariaAuthority->activeMandateFailures(
                    $actor,
                    IslamicShariaAuthorityService::DECISION_TYPE_APPROVAL_WORKFLOW,
                    ['subject_type' => $subjectType],
                    null,
                    $requesterUserId,
                );
                if ($failures !== []) {
                    throw new ReadinessGateFailure(['islamic_sharia_authority' => $failures]);
                }
            }

            if ($decision === IslamicApprovalStateMachine::DECISION_APPROVE && $conditions !== null) {
                $this->assertConditionsShape($conditions);
                $hasExpiresOn = isset($conditions['expires_on']) && is_string($conditions['expires_on']) && $conditions['expires_on'] !== '';
                if ($effectiveTo === null && ! $hasExpiresOn) {
                    throw new InvalidArgumentException('Conditional approval requires effective_to or conditions.expires_on.');
                }
            }

            DB::table('islamic_approval_decisions')->insert([
                'public_id' => (string) Str::ulid(),
                'workflow_id' => $this->rowInt($workflow, 'id'),
                'from_state' => $fromState,
                'to_state' => $toState,
                'decision' => $decision,
                'decision_comments' => $comments,
                'conditions' => $conditions !== null ? json_encode($conditions, JSON_THROW_ON_ERROR) : null,
                'evidence_document_id' => $evidenceDocumentId,
                'decided_by_user_id' => $actor->id,
                'decided_at' => now(),
                'effective_from' => $effectiveFrom,
                'effective_to' => $effectiveTo,
                'metadata' => $metadata !== null ? json_encode($metadata, JSON_THROW_ON_ERROR) : null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $workflowUpdate = [
                'current_state' => $toState,
                'updated_by_user_id' => $actor->id,
                'version' => $this->rowInt($workflow, 'version') + 1,
                'updated_at' => now(),
            ];
            if ($decision === IslamicApprovalStateMachine::DECISION_APPROVE) {
                $workflowUpdate['effective_from'] = $effectiveFrom;
                $workflowUpdate['effective_to'] = $effectiveTo;
                $workflowUpdate['conditions'] = $conditions !== null ? json_encode($conditions, JSON_THROW_ON_ERROR) : null;
            }

            DB::table('islamic_approval_workflows')->where('id', $this->rowInt($workflow, 'id'))->update($workflowUpdate);
            $updated = DB::table('islamic_approval_workflows')->where('id', $this->rowInt($workflow, 'id'))->first();
            if (! is_object($updated)) {
                throw new InvalidArgumentException('Approval workflow could not be reloaded.');
            }

            return ['row' => $updated, 'from' => $fromState, 'to' => $toState];
        });

        $row = $result['row'];
        $this->securityAudit->record($this->eventNameFor($decision), actor: $actor, properties: [
            'workflow_public_id' => $this->rowString($row, 'public_id'),
            'subject_type' => $subjectType,
            'subject_public_id' => $subjectPublicId,
            'from_state' => $result['from'],
            'to_state' => $result['to'],
            'conditions' => $this->decodeJsonObject($this->rowAny($row, 'conditions')),
            'effective_from' => $this->nullableRowString($row, 'effective_from'),
            'effective_to' => $this->nullableRowString($row, 'effective_to'),
        ], request: $request);

        return $row;
    }

    public function workflowFor(string $subjectType, string $subjectPublicId): ?object
    {
        $this->stateMachine->assertSubjectType($subjectType);
        $row = DB::table('islamic_approval_workflows')
            ->where('subject_type', $subjectType)
            ->where('subject_public_id', $subjectPublicId)
            ->first();

        return is_object($row) ? $row : null;
    }

    /**
     * Lock-aware usability check, intended for use inside a caller transaction.
     * Acquires SELECT ... FOR UPDATE on the workflow row so the state cannot
     * race ahead of an origination decision committed in the same transaction.
     *
     * @return array{ok: bool, state: string, reasons: list<string>}
     */
    public function isUsableForNewActionsLocked(string $subjectType, string $subjectPublicId, ?CarbonInterface $asOf = null): array
    {
        $this->stateMachine->assertSubjectType($subjectType);

        $locked = DB::table('islamic_approval_workflows')
            ->where('subject_type', $subjectType)
            ->where('subject_public_id', $subjectPublicId)
            ->lockForUpdate()
            ->first();
        if (! is_object($locked)) {
            return [
                'ok' => false,
                'state' => IslamicApprovalStateMachine::STATE_DRAFT,
                'reasons' => ['No approval workflow exists for subject.'],
            ];
        }

        return $this->isUsableForNewActions($subjectType, $subjectPublicId, $asOf);
    }

    public function latestDecisionFor(int $workflowId): ?object
    {
        $row = DB::table('islamic_approval_decisions')
            ->where('workflow_id', $workflowId)
            ->orderByDesc('id')
            ->first();

        return is_object($row) ? $row : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function payloadFor(object $workflow): array
    {
        $latest = $this->latestDecisionFor($this->rowInt($workflow, 'id'));
        $conditions = $this->decodeJsonObject($this->rowAny($workflow, 'conditions'));

        return [
            'public_id' => $this->rowString($workflow, 'public_id'),
            'subject_type' => $this->rowString($workflow, 'subject_type'),
            'subject_public_id' => $this->rowString($workflow, 'subject_public_id'),
            'current_state' => $this->rowString($workflow, 'current_state'),
            'effective_from' => $this->nullableRowString($workflow, 'effective_from'),
            'effective_to' => $this->nullableRowString($workflow, 'effective_to'),
            'is_blocking' => (bool) (((array) $workflow)['is_blocking'] ?? true),
            'version' => $this->rowInt($workflow, 'version'),
            'conditions' => $conditions,
            'latest_decision' => $latest === null ? null : [
                'public_id' => $this->rowString($latest, 'public_id'),
                'from_state' => $this->rowString($latest, 'from_state'),
                'to_state' => $this->rowString($latest, 'to_state'),
                'decision' => $this->rowString($latest, 'decision'),
                'decided_at' => $this->nullableRowString($latest, 'decided_at'),
                'comments' => $this->nullableRowString($latest, 'decision_comments'),
            ],
        ];
    }

    /**
     * Enforce the canonical condition shape so the central gate's deny-by-default
     * branches are reachable. Without this, a caller submitting
     * `required_controls: "quarterly"` (string) would silently slip past the
     * `is_array(...)` check inside `isUsableForNewActions` and become
     * unenforceable metadata.
     *
     * @param  array<string, mixed>  $conditions
     */
    private function assertConditionsShape(array $conditions): void
    {
        $allowed = ['required_controls', 'required_documents', 'max_notional_minor', 'allowed_agencies', 'expires_on'];
        foreach (array_keys($conditions) as $key) {
            if (! in_array($key, $allowed, true)) {
                throw new InvalidArgumentException('Unknown approval condition key: '.$key.'.');
            }
        }

        foreach (['required_controls', 'required_documents'] as $listKey) {
            if (! array_key_exists($listKey, $conditions)) {
                continue;
            }
            $value = $conditions[$listKey];
            if (! is_array($value)) {
                throw new InvalidArgumentException($listKey.' must be a list of strings.');
            }
            foreach ($value as $item) {
                if (! is_string($item) || $item === '') {
                    throw new InvalidArgumentException($listKey.' must be a list of non-empty strings.');
                }
            }
        }

        if (array_key_exists('max_notional_minor', $conditions)) {
            $value = $conditions['max_notional_minor'];
            if ($value !== null && (! is_int($value) || $value < 0)) {
                throw new InvalidArgumentException('max_notional_minor must be a non-negative integer.');
            }
        }

        if (array_key_exists('allowed_agencies', $conditions)) {
            $value = $conditions['allowed_agencies'];
            if ($value !== null) {
                if (! is_array($value)) {
                    throw new InvalidArgumentException('allowed_agencies must be a list of strings.');
                }
                foreach ($value as $item) {
                    if (! is_string($item) || $item === '') {
                        throw new InvalidArgumentException('allowed_agencies must be a list of non-empty strings.');
                    }
                }
            }
        }

        if (array_key_exists('expires_on', $conditions)) {
            $value = $conditions['expires_on'];
            if (! is_string($value) || $value === '') {
                throw new InvalidArgumentException('expires_on must be a non-empty date string.');
            }
            // Use strict round-trip parsing so values like "2026-13-99" (which PHP
            // would silently roll over to 2027-01-08) are rejected.
            $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
            if ($parsed === false || $parsed->format('Y-m-d') !== $value) {
                throw new InvalidArgumentException('expires_on must be a valid Y-m-d date.');
            }
        }
    }

    private function eventNameFor(string $decision): string
    {
        return match ($decision) {
            IslamicApprovalStateMachine::DECISION_SUBMIT => 'islamic.approval.submitted',
            IslamicApprovalStateMachine::DECISION_APPROVE => 'islamic.approval.approved',
            IslamicApprovalStateMachine::DECISION_REJECT => 'islamic.approval.rejected',
            IslamicApprovalStateMachine::DECISION_SUSPEND => 'islamic.approval.suspended',
            IslamicApprovalStateMachine::DECISION_REVOKE => 'islamic.approval.revoked',
            IslamicApprovalStateMachine::DECISION_EXPIRE => 'islamic.approval.expired',
            IslamicApprovalStateMachine::DECISION_ARCHIVE => 'islamic.approval.archived',
            IslamicApprovalStateMachine::DECISION_RESTORE_TO_DRAFT => 'islamic.approval.restored_to_draft',
            default => 'islamic.approval.transition',
        };
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function optionString(array $options, string $key): ?string
    {
        if (! array_key_exists($key, $options)) {
            return null;
        }
        $value = $options[$key];
        if ($value === null || $value === '') {
            return null;
        }

        return is_string($value) ? $value : (is_scalar($value) ? (string) $value : null);
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function optionNullableInt(array $options, string $key): ?int
    {
        if (! array_key_exists($key, $options)) {
            return null;
        }
        $value = $options[$key];

        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>|null
     */
    private function optionArrayOrNull(array $options, string $key): ?array
    {
        if (! array_key_exists($key, $options)) {
            return null;
        }
        $value = $options[$key];
        if (! is_array($value) || $value === []) {
            return null;
        }

        $result = [];
        foreach ($value as $k => $v) {
            $result[(string) $k] = $v;
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function resolveEvidenceDocumentId(array $options): ?int
    {
        $publicId = $this->optionString($options, 'evidence_document_public_id');
        if ($publicId === null) {
            return null;
        }
        $row = DB::table('documents')->where('public_id', $publicId)->first(['id', 'status']);
        if (! is_object($row)) {
            throw new InvalidArgumentException('Evidence document not found.');
        }
        $status = is_string($row->status) ? $row->status : '';
        if ($status !== 'active') {
            throw new InvalidArgumentException('Evidence document must be active.');
        }

        return is_numeric($row->id) ? (int) $row->id : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeJsonObject(mixed $value): ?array
    {
        if (! is_string($value) || $value === '') {
            return null;
        }
        $decoded = json_decode($value, true);
        if (! is_array($decoded)) {
            return null;
        }

        $result = [];
        foreach ($decoded as $k => $v) {
            $result[(string) $k] = $v;
        }

        return $result;
    }

    private function rowString(object $row, string $key): string
    {
        $value = ((array) $row)[$key] ?? '';

        return is_string($value) ? $value : (string) $value;
    }

    private function nullableRowString(object $row, string $key): ?string
    {
        $value = ((array) $row)[$key] ?? null;
        if ($value === null || $value === '') {
            return null;
        }

        return is_string($value) ? $value : (string) $value;
    }

    private function rowInt(object $row, string $key): int
    {
        $value = ((array) $row)[$key] ?? 0;

        return is_numeric($value) ? (int) $value : 0;
    }

    private function rowAny(object $row, string $key): mixed
    {
        return ((array) $row)[$key] ?? null;
    }
}
