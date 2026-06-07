<?php

declare(strict_types=1);

namespace App\Application\IslamicFinance;

use InvalidArgumentException;

final class IslamicApprovalStateMachine
{
    public const STATE_DRAFT = 'draft';

    public const STATE_SUBMITTED = 'submitted';

    public const STATE_APPROVED = 'approved';

    public const STATE_REJECTED = 'rejected';

    public const STATE_SUSPENDED = 'suspended';

    public const STATE_REVOKED = 'revoked';

    public const STATE_EXPIRED = 'expired';

    public const STATE_ARCHIVED = 'archived';

    public const STATES = [
        self::STATE_DRAFT,
        self::STATE_SUBMITTED,
        self::STATE_APPROVED,
        self::STATE_REJECTED,
        self::STATE_SUSPENDED,
        self::STATE_REVOKED,
        self::STATE_EXPIRED,
        self::STATE_ARCHIVED,
    ];

    public const DECISION_SUBMIT = 'submit';

    public const DECISION_APPROVE = 'approve';

    public const DECISION_REJECT = 'reject';

    public const DECISION_SUSPEND = 'suspend';

    public const DECISION_REVOKE = 'revoke';

    public const DECISION_EXPIRE = 'expire';

    public const DECISION_ARCHIVE = 'archive';

    public const DECISION_RESTORE_TO_DRAFT = 'restore_to_draft';

    public const DECISIONS = [
        self::DECISION_SUBMIT,
        self::DECISION_APPROVE,
        self::DECISION_REJECT,
        self::DECISION_SUSPEND,
        self::DECISION_REVOKE,
        self::DECISION_EXPIRE,
        self::DECISION_ARCHIVE,
        self::DECISION_RESTORE_TO_DRAFT,
    ];

    public const SUBJECT_PRODUCT = 'islamic_product';

    public const SUBJECT_CONTRACT_TEMPLATE = 'islamic_contract_template';

    public const SUBJECT_SCREENING_POLICY = 'islamic_screening_policy';

    public const SUBJECT_EXCEPTION = 'islamic_exception';

    public const SUBJECT_MAPPING = 'islamic_mapping';

    public const SUBJECT_TREATMENT_POLICY = 'islamic_treatment_policy';

    public const SUBJECT_CORRECTIVE_ACTION = 'islamic_corrective_action';

    public const SUBJECT_TYPES = [
        self::SUBJECT_PRODUCT,
        self::SUBJECT_CONTRACT_TEMPLATE,
        self::SUBJECT_SCREENING_POLICY,
        self::SUBJECT_EXCEPTION,
        self::SUBJECT_MAPPING,
        self::SUBJECT_TREATMENT_POLICY,
        self::SUBJECT_CORRECTIVE_ACTION,
    ];

    /**
     * Subject types that count as material decisions and require IF-010
     * authority membership for the actor and forbid self-approval.
     */
    public const MATERIAL_SUBJECT_TYPES = [
        self::SUBJECT_PRODUCT,
        self::SUBJECT_SCREENING_POLICY,
        self::SUBJECT_EXCEPTION,
        self::SUBJECT_CORRECTIVE_ACTION,
    ];

    /**
     * Canonical transition table: from_state => decision => to_state.
     *
     * @var array<string, array<string, string>>
     */
    private const TRANSITIONS = [
        self::STATE_DRAFT => [
            self::DECISION_SUBMIT => self::STATE_SUBMITTED,
            self::DECISION_ARCHIVE => self::STATE_ARCHIVED,
        ],
        self::STATE_SUBMITTED => [
            self::DECISION_APPROVE => self::STATE_APPROVED,
            self::DECISION_REJECT => self::STATE_REJECTED,
        ],
        self::STATE_APPROVED => [
            self::DECISION_SUSPEND => self::STATE_SUSPENDED,
            self::DECISION_REVOKE => self::STATE_REVOKED,
            self::DECISION_EXPIRE => self::STATE_EXPIRED,
            self::DECISION_ARCHIVE => self::STATE_ARCHIVED,
        ],
        self::STATE_REJECTED => [
            self::DECISION_RESTORE_TO_DRAFT => self::STATE_DRAFT,
            self::DECISION_ARCHIVE => self::STATE_ARCHIVED,
        ],
        self::STATE_SUSPENDED => [
            self::DECISION_APPROVE => self::STATE_APPROVED,
            self::DECISION_REVOKE => self::STATE_REVOKED,
            self::DECISION_ARCHIVE => self::STATE_ARCHIVED,
        ],
        self::STATE_REVOKED => [
            self::DECISION_ARCHIVE => self::STATE_ARCHIVED,
        ],
        self::STATE_EXPIRED => [
            self::DECISION_APPROVE => self::STATE_APPROVED,
            self::DECISION_ARCHIVE => self::STATE_ARCHIVED,
        ],
        self::STATE_ARCHIVED => [],
    ];

    /**
     * States from which new origination/use is forbidden.
     */
    public const NON_USABLE_STATES = [
        self::STATE_DRAFT,
        self::STATE_SUBMITTED,
        self::STATE_REJECTED,
        self::STATE_SUSPENDED,
        self::STATE_REVOKED,
        self::STATE_EXPIRED,
        self::STATE_ARCHIVED,
    ];

    public function assertState(string $state): void
    {
        if (! in_array($state, self::STATES, true)) {
            throw new InvalidArgumentException(__('islamic_governance.approval_unknown_state', ['state' => $state]));
        }
    }

    public function assertDecision(string $decision): void
    {
        if (! in_array($decision, self::DECISIONS, true)) {
            throw new InvalidArgumentException(__('islamic_governance.approval_unknown_decision', ['decision' => $decision]));
        }
    }

    public function assertSubjectType(string $subjectType): void
    {
        if (! in_array($subjectType, self::SUBJECT_TYPES, true)) {
            throw new InvalidArgumentException(__('islamic_governance.approval_unknown_subject_type', ['subject_type' => $subjectType]));
        }
    }

    public function isMaterialSubject(string $subjectType): bool
    {
        return in_array($subjectType, self::MATERIAL_SUBJECT_TYPES, true);
    }

    /**
     * Resolves the destination state for a (fromState, decision) pair.
     * Throws if the transition is not allowed.
     */
    public function resolveTransition(string $fromState, string $decision): string
    {
        $this->assertState($fromState);
        $this->assertDecision($decision);

        $candidate = self::TRANSITIONS[$fromState][$decision] ?? null;
        if (! is_string($candidate)) {
            throw new InvalidArgumentException(
                __('islamic_governance.approval_decision_not_allowed_from_state', ['decision' => $decision, 'from_state' => $fromState])
            );
        }

        return $candidate;
    }

    public function isUsableState(string $state): bool
    {
        return $state === self::STATE_APPROVED;
    }
}
