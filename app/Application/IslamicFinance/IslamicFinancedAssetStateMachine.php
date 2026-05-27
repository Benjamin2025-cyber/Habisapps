<?php

declare(strict_types=1);

namespace App\Application\IslamicFinance;

use InvalidArgumentException;

final class IslamicFinancedAssetStateMachine
{
    public const STATUS_REQUESTED = 'requested';

    public const STATUS_QUOTED = 'quoted';

    public const STATUS_PURCHASED = 'purchased';

    public const STATUS_CONTROLLED = 'controlled';

    public const STATUS_DELIVERED = 'delivered';

    public const STATUS_LEASED = 'leased';

    public const STATUS_TRANSFERRED = 'transferred';

    public const STATUS_RETURNED = 'returned';

    public const STATUS_IMPAIRED = 'impaired';

    public const STATUS_DISPOSED = 'disposed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_REQUESTED,
        self::STATUS_QUOTED,
        self::STATUS_PURCHASED,
        self::STATUS_CONTROLLED,
        self::STATUS_DELIVERED,
        self::STATUS_LEASED,
        self::STATUS_TRANSFERRED,
        self::STATUS_RETURNED,
        self::STATUS_IMPAIRED,
        self::STATUS_DISPOSED,
        self::STATUS_CANCELLED,
    ];

    public const TERMINAL_STATUSES = [
        self::STATUS_TRANSFERRED,
        self::STATUS_DISPOSED,
        self::STATUS_CANCELLED,
    ];

    /**
     * @var array<string, list<string>>
     */
    private const array TRANSITIONS = [
        self::STATUS_REQUESTED => [self::STATUS_QUOTED, self::STATUS_PURCHASED, self::STATUS_CANCELLED],
        self::STATUS_QUOTED => [self::STATUS_PURCHASED, self::STATUS_CANCELLED],
        self::STATUS_PURCHASED => [self::STATUS_CONTROLLED, self::STATUS_DELIVERED, self::STATUS_IMPAIRED, self::STATUS_CANCELLED],
        self::STATUS_CONTROLLED => [self::STATUS_DELIVERED, self::STATUS_LEASED, self::STATUS_RETURNED, self::STATUS_IMPAIRED, self::STATUS_DISPOSED],
        self::STATUS_DELIVERED => [self::STATUS_IMPAIRED, self::STATUS_DISPOSED],
        self::STATUS_LEASED => [self::STATUS_RETURNED, self::STATUS_TRANSFERRED, self::STATUS_IMPAIRED],
        self::STATUS_RETURNED => [self::STATUS_IMPAIRED, self::STATUS_DISPOSED, self::STATUS_LEASED],
        self::STATUS_IMPAIRED => [self::STATUS_DISPOSED],
        self::STATUS_TRANSFERRED => [],
        self::STATUS_DISPOSED => [],
        self::STATUS_CANCELLED => [],
    ];

    /**
     * Required evidence reference keys per target status.
     *
     * @var array<string, list<string>>
     */
    private const array EVIDENCE_REQUIREMENTS = [
        self::STATUS_QUOTED => ['supplier_pricing_ref'],
        self::STATUS_PURCHASED => ['purchase_evidence'],
        self::STATUS_CONTROLLED => ['control_evidence'],
        self::STATUS_DELIVERED => ['delivery_evidence'],
        self::STATUS_LEASED => ['lease_commencement_evidence'],
        self::STATUS_TRANSFERRED => ['transfer_evidence'],
        self::STATUS_RETURNED => ['return_evidence'],
        self::STATUS_IMPAIRED => ['impairment_evidence'],
        self::STATUS_DISPOSED => ['disposal_evidence'],
        self::STATUS_CANCELLED => ['cancellation_reason'],
    ];

    /**
     * Evidence keys whose value must reference a row in the `documents` table (by public_id).
     *
     * @var list<string>
     */
    private const array DOCUMENT_BACKED_EVIDENCE_KEYS = [
        'purchase_evidence',
        'control_evidence',
        'delivery_evidence',
        'lease_commencement_evidence',
        'transfer_evidence',
        'return_evidence',
        'impairment_evidence',
        'disposal_evidence',
    ];

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

    public const ACCEPTANCE_STATUSES = [
        self::STATUS_PURCHASED,
        self::STATUS_CONTROLLED,
        self::STATUS_LEASED,
    ];

    /**
     * Product-family-aware lifecycle states accepted for financing activation gate.
     *
     * Mourabaha: institution must own/control before sale (purchased or controlled).
     * Ijara/Ijara wa Iqtina: institution must own/control before lease, and may already be in lease.
     * Other families default to the Mourabaha rule.
     *
     * @var array<string, list<string>>
     */
    private const array ACTIVATION_GATE_BY_FAMILY = [
        'mourabaha' => [self::STATUS_PURCHASED, self::STATUS_CONTROLLED],
        'ijara' => [self::STATUS_CONTROLLED, self::STATUS_LEASED],
        'ijara_wa_iqtina' => [self::STATUS_CONTROLLED, self::STATUS_LEASED],
    ];

    /**
     * @return list<string>
     */
    public static function activationGateStatusesFor(string $productFamily): array
    {
        return self::ACTIVATION_GATE_BY_FAMILY[$productFamily]
            ?? [self::STATUS_PURCHASED, self::STATUS_CONTROLLED];
    }

    public static function requiresAssetActivationGate(string $productFamily): bool
    {
        return array_key_exists($productFamily, self::ACTIVATION_GATE_BY_FAMILY);
    }

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

    /**
     * @param  array<string, mixed>  $evidence
     */
    public static function assertEvidenceComplete(string $toStatus, array $evidence): void
    {
        foreach (self::requiredEvidenceKeys($toStatus) as $key) {
            if (! isset($evidence[$key])) {
                throw new InvalidArgumentException(sprintf('Asset transition to "%s" requires evidence key "%s".', $toStatus, $key));
            }
            $value = $evidence[$key];
            if (is_string($value) && trim($value) === '') {
                throw new InvalidArgumentException(sprintf('Asset transition to "%s" evidence key "%s" cannot be empty.', $toStatus, $key));
            }
            if ($value === null) {
                throw new InvalidArgumentException(sprintf('Asset transition to "%s" evidence key "%s" cannot be null.', $toStatus, $key));
            }
        }
    }

    public static function assertTransitionAllowed(string $from, string $to): void
    {
        if (! self::isStatus($from)) {
            throw new InvalidArgumentException(sprintf('Unknown current asset status "%s".', $from));
        }
        if (! self::isStatus($to)) {
            throw new InvalidArgumentException(sprintf('Unknown target asset status "%s".', $to));
        }
        if (self::isTerminal($from)) {
            throw new InvalidArgumentException(sprintf('Asset is in terminal status "%s" and cannot transition.', $from));
        }
        if (! self::canTransition($from, $to)) {
            throw new InvalidArgumentException(sprintf('Asset transition from "%s" to "%s" is not allowed.', $from, $to));
        }
    }
}
