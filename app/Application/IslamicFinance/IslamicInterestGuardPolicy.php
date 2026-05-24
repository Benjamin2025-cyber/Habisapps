<?php

declare(strict_types=1);

namespace App\Application\IslamicFinance;

use InvalidArgumentException;

final class IslamicInterestGuardPolicy
{
    /** @var list<string> */
    public const array ALLOWED_LATE_PAYMENT_TREATMENTS = [
        'approved_fee',
        'charity',
        'cost_recovery',
        'corrective_treatment',
    ];

    /** @var list<string> */
    private const array FORBIDDEN_SEMANTIC_TOKENS = [
        'interest',
        'apr',
        'compound_interest',
        'late_interest',
        'capitalized_interest',
        'interest_revenue',
        'interest_receivable',
        'interest_income',
        'loan_repayment_interest',
    ];

    /** @var list<string> */
    private const array ALLOWED_STATEMENT_LABELS = [
        'profit',
        'fees',
        'rent',
        'sale_receivable',
    ];

    /**
     * @param array<string,mixed> $rules
     */
    public function assertNoConventionalInterestBinding(array $rules): void
    {
        if ($this->containsForbiddenSemantics($rules)) {
            throw new InvalidArgumentException('Islamic product cannot bind conventional interest semantics.');
        }
    }

    public function assertIslamicMappingAllowed(string $mappingCode): void
    {
        if ($this->tokenForbidden($mappingCode)) {
            throw new InvalidArgumentException('Islamic posting cannot use conventional interest mapping: '.$mappingCode.'.');
        }
    }

    /**
     * @param list<string> $labels
     */
    public function assertStatementTerminologyAllowed(array $labels): void
    {
        foreach ($labels as $label) {
            $normalized = $this->normalizeToken($label);
            if ($normalized === '') {
                continue;
            }
            if ($this->tokenForbidden($normalized)) {
                throw new InvalidArgumentException('Islamic statements cannot use forbidden interest terminology: '.$label.'.');
            }
            if (! in_array($normalized, self::ALLOWED_STATEMENT_LABELS, true)) {
                throw new InvalidArgumentException('Islamic statement label is not approved: '.$label.'.');
            }
        }
    }

    public function assertLatePaymentTreatmentAllowed(?string $treatment): void
    {
        if ($treatment === null || $treatment === '') {
            return;
        }
        if (! in_array($treatment, self::ALLOWED_LATE_PAYMENT_TREATMENTS, true)) {
            throw new InvalidArgumentException('Forbidden Islamic late-payment treatment: '.$treatment.'.');
        }
    }

    /**
     * @param array<string,mixed> $rules
     */
    private function containsForbiddenSemantics(array $rules): bool
    {
        foreach ($rules as $key => $value) {
            if (is_string($key) && $this->tokenForbidden($key)) {
                return true;
            }
            if (is_string($value) && $this->tokenForbidden($value)) {
                return true;
            }
            if (is_array($value) && $this->containsForbiddenSemantics($value)) {
                return true;
            }
        }

        return false;
    }

    private function tokenForbidden(string $token): bool
    {
        $normalized = $this->normalizeToken($token);
        if ($normalized === '') {
            return false;
        }

        foreach (self::FORBIDDEN_SEMANTIC_TOKENS as $forbidden) {
            if (str_contains($normalized, $forbidden)) {
                return true;
            }
        }

        return false;
    }

    private function normalizeToken(string $value): string
    {
        return strtolower(trim((string) preg_replace('/[^a-z0-9]+/i', '_', $value)));
    }
}

