<?php

declare(strict_types=1);

namespace App\Application\IslamicFinance;

use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class IslamicTreatmentRoutingService
{
    private const EVENT_TYPES = [
        'late_payment_fee',
        'non_compliant_income_detected',
        'purification_transfer',
        'zakat_posting',
    ];

    public function __construct(
        private readonly IslamicApprovalWorkflowService $approvalWorkflow,
        private readonly IslamicMappingValidationService $mappingValidation,
    ) {}

    /**
     * @param array{
     *   agency_id?: int|null,
     *   product_family?: string|null,
     *   product_public_id?: string|null,
     *   as_of?: CarbonInterface|null,
     *   actor?: User|null,
     *   request?: Request|null,
     * } $context
     * @return array{
     *   policy: object,
     *   treatment_bucket: string,
     *   operation_code: string,
     *   debit_ledger_account_id: int,
     *   credit_ledger_account_id: int,
     *   mapping_reference: string,
     * }
     */
    public function resolve(
        string $eventType,
        int $amountMinor,
        string $currency,
        array $context = [],
        ?string $policyPublicId = null,
    ): array {
        if (! in_array($eventType, self::EVENT_TYPES, true)) {
            throw new InvalidArgumentException('Islamic treatment event type is invalid.');
        }
        if ($amountMinor <= 0) {
            throw new InvalidArgumentException('Islamic treatment amount must be positive.');
        }

        $asOf = $context['as_of'] ?? null;
        $asOfDate = $asOf?->toDateString() ?? now()->toDateString();
        $agencyId = is_int($context['agency_id'] ?? null) ? $context['agency_id'] : null;
        $policy = $this->resolvePolicy(
            policyPublicId: $policyPublicId,
            eventType: $eventType,
            asOfDate: $asOfDate,
            agencyId: $agencyId,
            productFamily: is_string($context['product_family'] ?? null) ? $context['product_family'] : null,
            productPublicId: is_string($context['product_public_id'] ?? null) ? $context['product_public_id'] : null,
        );

        $policyPublicIdResolved = $this->rowString($policy, 'public_id');
        $usability = $this->approvalWorkflow->isUsableForNewActions(
            IslamicApprovalStateMachine::SUBJECT_TREATMENT_POLICY,
            $policyPublicIdResolved,
            $asOf,
        );
        if (! $usability['ok']) {
            throw new InvalidArgumentException('Islamic treatment policy is not usable: '.implode(' ', $usability['reasons']));
        }

        $operationCode = $this->operationCodeForEvent($policy, $eventType);
        if (in_array($eventType, ['late_payment_fee', 'non_compliant_income_detected'], true) && str_contains($operationCode, 'profit')) {
            throw new InvalidArgumentException('Islamic treatment operation route cannot use ordinary profit operation codes.');
        }

        if (! is_int($agencyId)) {
            throw new InvalidArgumentException('Islamic treatment routing requires agency scope.');
        }

        $actor = $context['actor'] ?? null;
        $request = $context['request'] ?? null;
        $debitContext = [
            'side' => 'debit',
            'lock_for_update' => true,
            'actor' => $actor,
            'request' => $request,
        ];
        if ($asOf instanceof CarbonInterface) {
            $debitContext['as_of'] = $asOf;
        }
        $debit = $this->mappingValidation->resolvePostingMapping($operationCode, $agencyId, $currency, $debitContext);

        $creditContext = [
            'side' => 'credit',
            'lock_for_update' => true,
            'actor' => $actor,
            'request' => $request,
        ];
        if ($asOf instanceof CarbonInterface) {
            $creditContext['as_of'] = $asOf;
        }
        $credit = $this->mappingValidation->resolvePostingMapping($operationCode, $agencyId, $currency, $creditContext);

        $debitLedger = $debit['debit_ledger_account_id'] ?? null;
        $creditLedger = $credit['credit_ledger_account_id'] ?? null;
        if (! is_int($debitLedger) || ! is_int($creditLedger)) {
            throw new InvalidArgumentException('Islamic treatment posting route requires both debit and credit approved mappings.');
        }

        return [
            'policy' => $policy,
            'treatment_bucket' => $this->bucketForEvent($eventType),
            'operation_code' => $operationCode,
            'debit_ledger_account_id' => $debitLedger,
            'credit_ledger_account_id' => $creditLedger,
            'mapping_reference' => $debit['mapping_public_id'].'|'.$credit['mapping_public_id'],
        ];
    }

    /**
     * @param array{
     *   agency_id?: int|null,
     *   product_family?: string|null,
     *   product_public_id?: string|null,
     *   as_of?: CarbonInterface|null
     * } $context
     */
    public function resolvePolicyForEvent(
        string $eventType,
        array $context = [],
        ?string $policyPublicId = null,
    ): object {
        $asOf = $context['as_of'] ?? null;
        $asOfDate = $asOf?->toDateString() ?? now()->toDateString();

        return $this->resolvePolicy(
            policyPublicId: $policyPublicId,
            eventType: $eventType,
            asOfDate: $asOfDate,
            agencyId: is_int($context['agency_id'] ?? null) ? $context['agency_id'] : null,
            productFamily: is_string($context['product_family'] ?? null) ? $context['product_family'] : null,
            productPublicId: is_string($context['product_public_id'] ?? null) ? $context['product_public_id'] : null,
        );
    }

    private function resolvePolicy(
        ?string $policyPublicId,
        string $eventType,
        string $asOfDate,
        ?int $agencyId,
        ?string $productFamily,
        ?string $productPublicId,
    ): object {
        if (is_string($policyPublicId) && $policyPublicId !== '') {
            $policy = DB::table('islamic_treatment_policies')
                ->where('public_id', $policyPublicId)
                ->lockForUpdate()
                ->first();
            if (! is_object($policy)) {
                throw new InvalidArgumentException('Islamic treatment policy not found.');
            }

            $this->assertPolicyUsableForEvent($policy, $eventType, $asOfDate);

            return $policy;
        }

        $query = DB::table('islamic_treatment_policies')
            ->where('status', 'approved')
            ->where('effective_from', '<=', $asOfDate)
            ->where(function ($q) use ($asOfDate): void {
                $q->whereNull('effective_to')->orWhere('effective_to', '>', $asOfDate);
            })
            ->lockForUpdate()
            ->orderByDesc('version')
            ->orderByDesc('id');

        $rows = $query->get();
        if ($rows->isEmpty()) {
            throw new InvalidArgumentException('No approved Islamic treatment policy is available.');
        }

        $scored = [];
        foreach ($rows as $row) {
            $scopeType = $this->rowString($row, 'scope_type');
            $scopeValue = $this->nullableString(((array) $row)['scope_value'] ?? null);
            $score = null;
            if ($scopeType === 'product' && is_string($productPublicId) && $productPublicId !== '' && $scopeValue === $productPublicId) {
                $score = 4;
            } elseif ($scopeType === 'product_family' && is_string($productFamily) && $productFamily !== '' && $scopeValue === $productFamily) {
                $score = 3;
            } elseif ($scopeType === 'agency' && is_int($agencyId) && is_numeric(((array) $row)['agency_id'] ?? null) && (int) $row->agency_id === $agencyId) {
                $score = 2;
            } elseif ($scopeType === 'institution') {
                $score = 1;
            }
            if (is_int($score)) {
                $scored[] = ['score' => $score, 'row' => $row];
            }
        }

        if ($scored === []) {
            throw new InvalidArgumentException('No scoped Islamic treatment policy matches the event context.');
        }

        usort($scored, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);
        $bestScore = $scored[0]['score'];
        $top = array_values(array_filter($scored, static fn (array $v): bool => $v['score'] === $bestScore));
        if (count($top) > 1) {
            throw new InvalidArgumentException('Ambiguous Islamic treatment policies found for the same scope precedence.');
        }

        $policy = $top[0]['row'];
        $this->assertPolicyUsableForEvent($policy, $eventType, $asOfDate);

        return $policy;
    }

    private function assertPolicyUsableForEvent(object $policy, string $eventType, string $asOfDate): void
    {
        if ($this->rowString($policy, 'status') !== 'approved') {
            throw new InvalidArgumentException('Islamic treatment policy must be approved.');
        }
        $effectiveFrom = $this->rowString($policy, 'effective_from');
        $effectiveTo = $this->nullableString(((array) $policy)['effective_to'] ?? null);
        if ($effectiveFrom > $asOfDate || ($effectiveTo !== null && $effectiveTo <= $asOfDate)) {
            throw new InvalidArgumentException('Islamic treatment policy is not effective for the event date.');
        }

        match ($eventType) {
            'late_payment_fee' => $this->assertTrue($policy, 'charity_treatment_enabled', 'Late-payment treatment is not enabled by policy.'),
            'non_compliant_income_detected' => $this->assertTrue($policy, 'non_compliant_income_treatment_enabled', 'Non-compliant income treatment is not enabled by policy.'),
            'purification_transfer' => $this->assertPurificationEnabled($policy),
            'zakat_posting' => $this->assertTrue($policy, 'zakat_enabled', 'Zakat treatment is not enabled by policy.'),
            default => throw new InvalidArgumentException('Unsupported Islamic treatment event type.'),
        };
    }

    private function assertPurificationEnabled(object $policy): void
    {
        $mode = $this->nullableString(((array) $policy)['purification_mode'] ?? null);
        if ($mode === null) {
            throw new InvalidArgumentException('Purification mode is not configured in policy.');
        }
        $charityEnabled = (bool) (((array) $policy)['charity_treatment_enabled'] ?? false);
        $nonCompliantEnabled = (bool) (((array) $policy)['non_compliant_income_treatment_enabled'] ?? false);
        if (! $charityEnabled && ! $nonCompliantEnabled) {
            throw new InvalidArgumentException('Purification requires charity or non-compliant treatment to be enabled.');
        }
    }

    private function operationCodeForEvent(object $policy, string $eventType): string
    {
        $required = $this->decodeJsonObject(((array) $policy)['required_operation_codes'] ?? null);
        $code = is_array($required) && is_string($required[$eventType] ?? null) ? $required[$eventType] : null;
        if (! is_string($code) || $code === '') {
            throw new InvalidArgumentException('Islamic treatment policy is missing required operation code for '.$eventType.'.');
        }

        return $code;
    }

    private function bucketForEvent(string $eventType): string
    {
        return match ($eventType) {
            'late_payment_fee' => 'charity',
            'non_compliant_income_detected' => 'non_compliant_income',
            'purification_transfer' => 'purification',
            'zakat_posting' => 'zakat',
            default => 'unknown',
        };
    }

    private function assertTrue(object $row, string $key, string $message): void
    {
        if (! (bool) (((array) $row)[$key] ?? false)) {
            throw new InvalidArgumentException($message);
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeJsonObject(mixed $value): ?array
    {
        if (is_array($value)) {
            return $this->normalizeJsonObject($value);
        }
        if (! is_string($value) || $value === '') {
            return null;
        }
        $decoded = json_decode($value, true);

        return is_array($decoded) ? $this->normalizeJsonObject($decoded) : null;
    }

    private function rowString(object $row, string $key): string
    {
        $value = ((array) $row)[$key] ?? '';

        return is_string($value) ? $value : (string) $value;
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

    /**
     * @param  array<mixed, mixed>  $value
     * @return array<string, mixed>|null
     */
    private function normalizeJsonObject(array $value): ?array
    {
        $normalized = [];
        foreach ($value as $key => $item) {
            if (! is_string($key)) {
                return null;
            }
            $normalized[$key] = $item;
        }

        return $normalized;
    }
}
