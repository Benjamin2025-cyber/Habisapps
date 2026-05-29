<?php

declare(strict_types=1);

namespace App\Application\IslamicFinance;

use InvalidArgumentException;

final class IslamicSalamGoodsStateMachine
{
    public const STATUS_SPECIFIED = 'specified';

    public const STATUS_PARTIALLY_DELIVERED = 'partially_delivered';

    public const STATUS_DELIVERED = 'delivered';

    public const STATUS_SUBSTITUTION_REQUESTED = 'substitution_requested';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_NON_DELIVERY = 'non_delivery';

    public const STATUS_IN_DISPUTE = 'in_dispute';

    public const STATUS_SETTLED = 'settled';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_SPECIFIED,
        self::STATUS_PARTIALLY_DELIVERED,
        self::STATUS_DELIVERED,
        self::STATUS_SUBSTITUTION_REQUESTED,
        self::STATUS_REJECTED,
        self::STATUS_NON_DELIVERY,
        self::STATUS_IN_DISPUTE,
        self::STATUS_SETTLED,
        self::STATUS_CANCELLED,
    ];

    public const TERMINAL_STATUSES = [
        self::STATUS_REJECTED,
        self::STATUS_SETTLED,
        self::STATUS_CANCELLED,
    ];

    /**
     * @var array<string, list<string>>
     */
    private const array TRANSITIONS = [
        self::STATUS_SPECIFIED => [
            self::STATUS_PARTIALLY_DELIVERED,
            self::STATUS_DELIVERED,
            self::STATUS_SUBSTITUTION_REQUESTED,
            self::STATUS_NON_DELIVERY,
            self::STATUS_IN_DISPUTE,
            self::STATUS_CANCELLED,
        ],
        self::STATUS_PARTIALLY_DELIVERED => [
            self::STATUS_DELIVERED,
            self::STATUS_SUBSTITUTION_REQUESTED,
            self::STATUS_NON_DELIVERY,
            self::STATUS_IN_DISPUTE,
            self::STATUS_SETTLED,
        ],
        self::STATUS_DELIVERED => [
            self::STATUS_SETTLED,
            self::STATUS_REJECTED,
            self::STATUS_IN_DISPUTE,
        ],
        self::STATUS_SUBSTITUTION_REQUESTED => [
            self::STATUS_PARTIALLY_DELIVERED,
            self::STATUS_DELIVERED,
            self::STATUS_REJECTED,
            self::STATUS_NON_DELIVERY,
            self::STATUS_IN_DISPUTE,
        ],
        self::STATUS_NON_DELIVERY => [
            self::STATUS_SETTLED,
            self::STATUS_REJECTED,
            self::STATUS_IN_DISPUTE,
        ],
        self::STATUS_IN_DISPUTE => [
            self::STATUS_SUBSTITUTION_REQUESTED,
            self::STATUS_NON_DELIVERY,
            self::STATUS_REJECTED,
            self::STATUS_SETTLED,
        ],
        self::STATUS_SETTLED => [],
        self::STATUS_REJECTED => [],
        self::STATUS_CANCELLED => [],
    ];

    /**
     * @var array<string, list<string>>
     */
    private const array EVIDENCE_REQUIREMENTS = [
        self::STATUS_PARTIALLY_DELIVERED => ['delivery_evidence'],
        self::STATUS_DELIVERED => ['delivery_evidence'],
        self::STATUS_SUBSTITUTION_REQUESTED => ['substitution_reason'],
        self::STATUS_REJECTED => ['rejection_reason'],
        self::STATUS_NON_DELIVERY => ['non_delivery_evidence'],
        self::STATUS_IN_DISPUTE => ['dispute_reason'],
        self::STATUS_SETTLED => ['settlement_reference'],
        self::STATUS_CANCELLED => ['cancellation_reason'],
    ];

    /**
     * @var list<string>
     */
    private const array DOCUMENT_BACKED_EVIDENCE_KEYS = [
        'delivery_evidence',
        'non_delivery_evidence',
    ];

    public const ACCEPTANCE_STATUSES = [
        self::STATUS_PARTIALLY_DELIVERED,
        self::STATUS_DELIVERED,
    ];

    public static function isStatus(string $status): bool
    {
        return in_array($status, self::STATUSES, true);
    }

    public static function isTerminal(string $status): bool
    {
        return in_array($status, self::TERMINAL_STATUSES, true);
    }

    /**
     * @return list<string>
     */
    public static function allowedNextStatuses(string $fromStatus): array
    {
        return self::TRANSITIONS[$fromStatus] ?? [];
    }

    public static function canTransition(string $from, string $to): bool
    {
        return in_array($to, self::allowedNextStatuses($from), true);
    }

    /**
     * @return list<string>
     */
    public static function requiredEvidenceKeys(string $toStatus): array
    {
        return self::EVIDENCE_REQUIREMENTS[$toStatus] ?? [];
    }

    public static function requiresAcceptanceScreening(string $toStatus): bool
    {
        return in_array($toStatus, self::ACCEPTANCE_STATUSES, true);
    }

    public static function isDocumentBackedEvidenceKey(string $key): bool
    {
        return in_array($key, self::DOCUMENT_BACKED_EVIDENCE_KEYS, true);
    }

    /**
     * @return list<string>
     */
    public static function documentBackedEvidenceKeys(): array
    {
        return self::DOCUMENT_BACKED_EVIDENCE_KEYS;
    }

    /**
     * @param  array<string, mixed>  $evidence
     */
    public static function assertEvidenceComplete(string $toStatus, array $evidence): void
    {
        foreach (self::requiredEvidenceKeys($toStatus) as $key) {
            if (! isset($evidence[$key])) {
                throw new InvalidArgumentException(sprintf('Salam goods transition to "%s" requires evidence key "%s".', $toStatus, $key));
            }
            $value = $evidence[$key];
            if (is_string($value) && trim($value) === '') {
                throw new InvalidArgumentException(sprintf('Salam goods transition to "%s" evidence key "%s" cannot be empty.', $toStatus, $key));
            }
        }
    }

    public static function assertTransitionAllowed(string $from, string $to): void
    {
        if (! self::isStatus($from)) {
            throw new InvalidArgumentException(sprintf('Unknown current Salam goods status "%s".', $from));
        }
        if (! self::isStatus($to)) {
            throw new InvalidArgumentException(sprintf('Unknown target Salam goods status "%s".', $to));
        }
        if (self::isTerminal($from)) {
            throw new InvalidArgumentException(sprintf('Salam goods are in terminal status "%s" and cannot transition.', $from));
        }
        if (! self::canTransition($from, $to)) {
            throw new InvalidArgumentException(sprintf('Salam goods transition from "%s" to "%s" is not allowed.', $from, $to));
        }
    }
}
