<?php

declare(strict_types=1);

namespace App\Application\IslamicFinance;

use App\Models\LedgerAccount;
use App\Models\OperationAccountMapping;
use App\Models\User;
use App\Support\Security\SecurityAudit;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class IslamicMappingValidationService
{
    public function __construct(
        private readonly IslamicApprovalWorkflowService $approvalWorkflow,
        private readonly SecurityAudit $securityAudit,
    ) {}

    /**
     * @param array{
     *   side?: 'debit'|'credit',
     *   as_of?: CarbonInterface,
     *   lock_for_update?: bool,
     *   actor?: User|null,
     *   request?: Request|null,
     * } $context
     * @return array{
     *   mapping_public_id: string,
     *   debit_ledger_account_id: int|null,
     *   credit_ledger_account_id: int|null,
     *   currency: string|null,
     *   approval_status: string,
     *   sharia_approval_required: bool,
     *   sharia_approval_status: string,
     * }
     */
    public function resolvePostingMapping(
        string $operationCode,
        int $agencyId,
        string $currency,
        array $context = [],
    ): array {
        $side = ($context['side'] ?? 'credit') === 'debit' ? 'debit' : 'credit';
        $lock = $context['lock_for_update'] ?? false;
        $asOfDate = ($context['as_of'] ?? now())->toDateString();
        $actor = $context['actor'] ?? null;
        $request = $context['request'] ?? null;

        $ledgerJoin = $side === 'debit'
            ? ['alias' => 'debit_ledger', 'id_column' => 'map.debit_ledger_account_id']
            : ['alias' => 'credit_ledger', 'id_column' => 'map.credit_ledger_account_id'];

        $query = DB::table('operation_account_mappings as map')
            ->join('operation_codes as op', 'op.id', '=', 'map.operation_code_id')
            ->join('ledger_accounts as '.$ledgerJoin['alias'], $ledgerJoin['alias'].'.id', '=', $ledgerJoin['id_column'])
            ->where('op.code', $operationCode)
            ->where('op.module', 'islamic_finance')
            ->where('op.status', 'active')
            ->where('map.status', OperationAccountMapping::STATUS_ACTIVE)
            ->where('map.approval_status', OperationAccountMapping::APPROVAL_APPROVED)
            ->where('map.effective_from', '<=', $asOfDate)
            ->where(function ($q) use ($asOfDate): void {
                $q->whereNull('map.effective_to')->orWhere('map.effective_to', '>', $asOfDate);
            })
            ->where(function ($q) use ($agencyId): void {
                $q->whereNull('map.agency_id')->orWhere('map.agency_id', $agencyId);
            })
            ->where(function ($q) use ($currency): void {
                $q->whereNull('map.currency')->orWhere('map.currency', $currency);
            })
            ->where($ledgerJoin['alias'].'.status', LedgerAccount::STATUS_ACTIVE)
            ->where($ledgerJoin['alias'].'.agency_id', $agencyId)
            ->select([
                'map.public_id',
                'map.debit_ledger_account_id',
                'map.credit_ledger_account_id',
                'map.currency',
                'map.approval_status',
                'map.sharia_approval_required',
                'map.sharia_approval_status',
            ]);

        if ($lock) {
            $query->lockForUpdate();
        }

        /** @var Collection<int, \stdClass> $rows */
        $rows = $query->get();
        if ($rows->isEmpty()) {
            $this->recordBlockedUse(
                actor: $actor,
                request: $request,
                operationCode: $operationCode,
                reason: 'No approved, effective, agency/currency-compatible mapping is available.',
                details: ['side' => $side, 'currency' => $currency, 'agency_id' => $agencyId]
            );
            throw new InvalidArgumentException('Approved Islamic mapping is required for '.$operationCode.' ('.$side.').');
        }

        $exactCurrency = $rows->filter(fn (\stdClass $row): bool => $this->rowNullableString($row, 'currency') === $currency)->values();
        $fallbackCurrency = $rows->filter(fn (\stdClass $row): bool => $this->rowNullableString($row, 'currency') === null)->values();

        if ($exactCurrency->count() > 1 || ($exactCurrency->count() === 0 && $fallbackCurrency->count() > 1)) {
            $this->recordBlockedUse(
                actor: $actor,
                request: $request,
                operationCode: $operationCode,
                reason: 'Ambiguous mapping candidates overlap the same scope.',
                details: ['side' => $side, 'currency' => $currency, 'agency_id' => $agencyId]
            );
            throw new InvalidArgumentException('Ambiguous Islamic mapping candidates found for '.$operationCode.' ('.$side.').');
        }

        $candidate = $exactCurrency->first() ?? $fallbackCurrency->first();
        if (! is_object($candidate)) {
            $this->recordBlockedUse(
                actor: $actor,
                request: $request,
                operationCode: $operationCode,
                reason: 'No deterministic currency match found for mapping.',
                details: ['side' => $side, 'currency' => $currency, 'agency_id' => $agencyId]
            );
            throw new InvalidArgumentException('Approved Islamic mapping is required for '.$operationCode.' ('.$side.').');
        }

        $mappingPublicId = $this->rowString($candidate, 'public_id');
        $workflowUsability = $lock
            ? $this->approvalWorkflow->isUsableForNewActionsLocked(IslamicApprovalStateMachine::SUBJECT_MAPPING, $mappingPublicId)
            : $this->approvalWorkflow->isUsableForNewActions(IslamicApprovalStateMachine::SUBJECT_MAPPING, $mappingPublicId);
        if (! $workflowUsability['ok']) {
            $this->recordBlockedUse(
                actor: $actor,
                request: $request,
                operationCode: $operationCode,
                reason: 'Mapping workflow is not usable: '.implode(' ', $workflowUsability['reasons']),
                details: [
                    'mapping_public_id' => $mappingPublicId,
                    'state' => $workflowUsability['state'],
                    'side' => $side,
                ]
            );
            throw new InvalidArgumentException('Islamic mapping workflow is not approved for '.$operationCode.' ('.$side.').');
        }

        $shariaRequired = (bool) (((array) $candidate)['sharia_approval_required'] ?? false);
        $shariaStatus = $this->rowString($candidate, 'sharia_approval_status');
        if ($shariaRequired && $shariaStatus !== OperationAccountMapping::SHARIA_APPROVED) {
            $this->recordBlockedUse(
                actor: $actor,
                request: $request,
                operationCode: $operationCode,
                reason: 'Sharia approval is required but missing for mapping.',
                details: ['mapping_public_id' => $mappingPublicId, 'sharia_approval_status' => $shariaStatus]
            );
            throw new InvalidArgumentException('Islamic mapping requires Sharia approval before posting for '.$operationCode.'.');
        }

        return [
            'mapping_public_id' => $mappingPublicId,
            'debit_ledger_account_id' => $this->rowNullableInt($candidate, 'debit_ledger_account_id'),
            'credit_ledger_account_id' => $this->rowNullableInt($candidate, 'credit_ledger_account_id'),
            'currency' => $this->nullableString(((array) $candidate)['currency'] ?? null),
            'approval_status' => $this->rowString($candidate, 'approval_status'),
            'sharia_approval_required' => $shariaRequired,
            'sharia_approval_status' => $shariaStatus,
        ];
    }

    /**
     * @param array{
     *   side?: 'debit'|'credit',
     *   as_of?: CarbonInterface,
     *   lock_for_update?: bool,
     *   actor?: User|null,
     *   request?: Request|null,
     * } $context
     */
    public function assertPostingAllowed(
        string $operationCode,
        int $agencyId,
        string $currency,
        array $context = [],
    ): void {
        $this->resolvePostingMapping($operationCode, $agencyId, $currency, $context);
    }

    /**
     * @param  array<string, mixed>  $details
     */
    private function recordBlockedUse(
        ?User $actor,
        ?Request $request,
        string $operationCode,
        string $reason,
        array $details = [],
    ): void {
        $this->securityAudit->record('islamic.mapping.use_blocked', actor: $actor, properties: [
            'operation_code' => $operationCode,
            'reason' => $reason,
            'details' => $details,
        ], request: $request);
    }

    private function rowString(object $row, string $key): string
    {
        $value = ((array) $row)[$key] ?? '';

        return is_string($value) ? $value : (string) $value;
    }

    private function rowNullableInt(object $row, string $key): ?int
    {
        $value = ((array) $row)[$key] ?? null;

        return is_numeric($value) ? (int) $value : null;
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        return $value;
    }

    private function rowNullableString(object $row, string $key): ?string
    {
        return $this->nullableString(((array) $row)[$key] ?? null);
    }
}
