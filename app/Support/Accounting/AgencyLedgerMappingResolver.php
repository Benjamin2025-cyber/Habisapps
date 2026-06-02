<?php

declare(strict_types=1);

namespace App\Support\Accounting;

use App\Models\LedgerAccount;
use App\Models\OperationAccountMapping;
use App\Models\OperationCode;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * FBI2-031 — single source of truth for resolving agency-scoped posting ledgers
 * through approved operation-account mappings.
 *
 * A mapping is usable for an (operation code, agency, currency, leg) request when
 * all of the following hold:
 *   - the operation code is active;
 *   - the mapping status is active;
 *   - the mapping approval_status is approved;
 *   - today is inside the effective date window (null bounds are open);
 *   - the mapping currency is null (any) or matches the requested currency;
 *   - the mapping agency_id is null (global) or matches the requested agency;
 *   - the required leg ledger(s) are active and belong to the requested agency.
 *
 * The resolver is shared by loan disbursement, setup-charge collection,
 * insurance-premium collection, and FX so the rules cannot drift across postings.
 */
final class AgencyLedgerMappingResolver
{
    public const string LEG_DEBIT = 'debit';

    public const string LEG_CREDIT = 'credit';

    public const string LEG_BOTH = 'both';

    public const string READY = 'ready';

    public const string MISSING = 'missing';

    public const string INACTIVE = 'inactive';

    public const string UNAPPROVED = 'unapproved';

    public const string EXPIRED = 'expired';

    public const string CROSS_AGENCY = 'cross_agency';

    public const string OVERLAPPING = 'overlapping';

    public function creditLedgerId(string $code, string $module, int $agencyId, string $currency): int
    {
        $resolution = $this->resolve($code, $module, $agencyId, $currency, self::LEG_CREDIT);
        $this->assertReady($code, $resolution);

        $ledgerId = $resolution['credit_ledger_account_id'];
        if (! is_int($ledgerId)) {
            throw new InvalidArgumentException($this->message($code, self::CROSS_AGENCY));
        }

        return $ledgerId;
    }

    public function debitLedgerId(string $code, string $module, int $agencyId, string $currency): int
    {
        $resolution = $this->resolve($code, $module, $agencyId, $currency, self::LEG_DEBIT);
        $this->assertReady($code, $resolution);

        $ledgerId = $resolution['debit_ledger_account_id'];
        if (! is_int($ledgerId)) {
            throw new InvalidArgumentException($this->message($code, self::CROSS_AGENCY));
        }

        return $ledgerId;
    }

    /**
     * @return array{0: int, 1: int} [debitLedgerId, creditLedgerId]
     */
    public function bothLegs(string $code, string $module, int $agencyId, string $currency): array
    {
        $resolution = $this->resolve($code, $module, $agencyId, $currency, self::LEG_BOTH);
        $this->assertReady($code, $resolution);

        $debit = $resolution['debit_ledger_account_id'];
        $credit = $resolution['credit_ledger_account_id'];
        if (! is_int($debit) || ! is_int($credit)) {
            throw new InvalidArgumentException($this->message($code, self::CROSS_AGENCY));
        }

        return [$debit, $credit];
    }

    /**
     * Non-throwing evaluation used by the readiness endpoint.
     *
     * @return array{
     *     status: string,
     *     debit_ledger_account_id: int|null,
     *     credit_ledger_account_id: int|null,
     *     candidate_count: int,
     *     usable_count: int,
     * }
     */
    public function resolve(string $code, string $module, int $agencyId, string $currency, string $leg): array
    {
        $today = now()->toDateString();
        $currency = strtoupper($currency);

        $candidates = DB::table('operation_account_mappings as m')
            ->join('operation_codes as oc', 'oc.id', '=', 'm.operation_code_id')
            ->leftJoin('ledger_accounts as dl', 'dl.id', '=', 'm.debit_ledger_account_id')
            ->leftJoin('ledger_accounts as cl', 'cl.id', '=', 'm.credit_ledger_account_id')
            ->where('oc.code', $code)
            ->where('oc.module', $module)
            ->where(function ($query) use ($agencyId): void {
                $query->whereNull('m.agency_id')->orWhere('m.agency_id', $agencyId);
            })
            ->where(function ($query) use ($currency): void {
                $query->whereNull('m.currency')->orWhere('m.currency', $currency);
            })
            // Prefer agency-specific then currency-specific then most recent.
            ->orderByRaw('m.agency_id IS NULL')
            ->orderByRaw('m.currency IS NULL')
            ->orderByRaw('m.effective_from IS NULL')
            ->orderByDesc('m.effective_from')
            ->orderByDesc('m.id')
            ->get([
                'm.debit_ledger_account_id',
                'm.credit_ledger_account_id',
                'm.status',
                'm.approval_status',
                'm.effective_from',
                'm.effective_to',
                'oc.status as operation_status',
                'dl.agency_id as debit_agency_id',
                'dl.status as debit_status',
                'cl.agency_id as credit_agency_id',
                'cl.status as credit_status',
            ]);

        $usable = [];
        foreach ($candidates as $row) {
            if ($this->rowIsUsable($row, $agencyId, $today, $leg)) {
                $usable[] = $row;
            }
        }

        if ($usable === []) {
            return [
                'status' => $this->classifyFailure($candidates->all(), $agencyId, $today, $leg),
                'debit_ledger_account_id' => null,
                'credit_ledger_account_id' => null,
                'candidate_count' => $candidates->count(),
                'usable_count' => 0,
            ];
        }

        $selected = $usable[0];

        return [
            'status' => count($usable) > 1 ? self::OVERLAPPING : self::READY,
            'debit_ledger_account_id' => $this->intOrNull($this->prop($selected, 'debit_ledger_account_id')),
            'credit_ledger_account_id' => $this->intOrNull($this->prop($selected, 'credit_ledger_account_id')),
            'candidate_count' => $candidates->count(),
            'usable_count' => count($usable),
        ];
    }

    private function rowIsUsable(object $row, int $agencyId, string $today, string $leg): bool
    {
        if ($this->stringValue($this->prop($row, 'operation_status')) !== OperationCode::STATUS_ACTIVE) {
            return false;
        }
        if ($this->stringValue($this->prop($row, 'status')) !== OperationAccountMapping::STATUS_ACTIVE) {
            return false;
        }
        if ($this->stringValue($this->prop($row, 'approval_status')) !== OperationAccountMapping::APPROVAL_APPROVED) {
            return false;
        }
        if (! $this->effectiveWindowOpen($row, $today)) {
            return false;
        }

        return $this->legLedgersValid($row, $agencyId, $leg);
    }

    private function effectiveWindowOpen(object $row, string $today): bool
    {
        $from = $this->dateString($this->prop($row, 'effective_from'));
        $to = $this->dateString($this->prop($row, 'effective_to'));

        if ($from !== null && $from > $today) {
            return false;
        }

        if ($to !== null && $to < $today) {
            return false;
        }

        return true;
    }

    private function legLedgersValid(object $row, int $agencyId, string $leg): bool
    {
        if ($leg === self::LEG_DEBIT || $leg === self::LEG_BOTH) {
            if (! $this->ledgerValid($this->prop($row, 'debit_ledger_account_id'), $this->prop($row, 'debit_agency_id'), $this->prop($row, 'debit_status'), $agencyId)) {
                return false;
            }
        }

        if ($leg === self::LEG_CREDIT || $leg === self::LEG_BOTH) {
            if (! $this->ledgerValid($this->prop($row, 'credit_ledger_account_id'), $this->prop($row, 'credit_agency_id'), $this->prop($row, 'credit_status'), $agencyId)) {
                return false;
            }
        }

        return true;
    }

    private function ledgerValid(mixed $ledgerId, mixed $ledgerAgencyId, mixed $ledgerStatus, int $agencyId): bool
    {
        return is_numeric($ledgerId)
            && is_numeric($ledgerAgencyId)
            && (int) $ledgerAgencyId === $agencyId
            && $this->stringValue($ledgerStatus) === LedgerAccount::STATUS_ACTIVE;
    }

    /**
     * Classify why no usable mapping was found, preferring the most advanced
     * blocker among the candidates so the readiness endpoint and 422 messages
     * are specific.
     *
     * @param  array<int, object>  $candidates
     */
    private function classifyFailure(array $candidates, int $agencyId, string $today, string $leg): string
    {
        if ($candidates === []) {
            return self::MISSING;
        }

        $best = self::MISSING;
        $rank = [
            self::MISSING => 0,
            self::INACTIVE => 1,
            self::UNAPPROVED => 2,
            self::EXPIRED => 3,
            self::CROSS_AGENCY => 4,
        ];

        foreach ($candidates as $row) {
            $reason = $this->candidateFailureReason($row, $agencyId, $today, $leg);
            if ($rank[$reason] > $rank[$best]) {
                $best = $reason;
            }
        }

        return $best;
    }

    private function candidateFailureReason(object $row, int $agencyId, string $today, string $leg): string
    {
        $operationActive = $this->stringValue($this->prop($row, 'operation_status')) === OperationCode::STATUS_ACTIVE;
        $statusActive = $this->stringValue($this->prop($row, 'status')) === OperationAccountMapping::STATUS_ACTIVE;
        if (! $operationActive || ! $statusActive) {
            return self::INACTIVE;
        }

        if ($this->stringValue($this->prop($row, 'approval_status')) !== OperationAccountMapping::APPROVAL_APPROVED) {
            return self::UNAPPROVED;
        }

        if (! $this->effectiveWindowOpen($row, $today)) {
            return self::EXPIRED;
        }

        if (! $this->legLedgersValid($row, $agencyId, $leg)) {
            return self::CROSS_AGENCY;
        }

        return self::MISSING;
    }

    /**
     * @param  array{status: string}  $resolution
     */
    private function assertReady(string $code, array $resolution): void
    {
        if ($resolution['status'] === self::READY || $resolution['status'] === self::OVERLAPPING) {
            return;
        }

        throw new InvalidArgumentException($this->message($code, $resolution['status']));
    }

    private function message(string $code, string $status): string
    {
        return match ($status) {
            self::INACTIVE => 'The '.$code.' ledger mapping for this agency and currency is inactive.',
            self::UNAPPROVED => 'The '.$code.' ledger mapping for this agency and currency is not approved.',
            self::EXPIRED => 'The '.$code.' ledger mapping for this agency and currency is outside its effective date window.',
            self::CROSS_AGENCY => 'The '.$code.' ledger mapping points to an inactive or cross-agency ledger account.',
            default => 'No active approved '.$code.' ledger mapping is configured for this agency and currency.',
        };
    }

    private function prop(object $row, string $key): mixed
    {
        return ((array) $row)[$key] ?? null;
    }

    private function intOrNull(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private function stringValue(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    private function dateString(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        return substr($value, 0, 10);
    }
}
