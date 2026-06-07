<?php

declare(strict_types=1);

namespace App\Application\Reporting;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class MappingCompletenessGate
{
    public function assertReadyForPosting(string $operationCode, int $agencyId, string $currency): void
    {
        $description = $this->describe($operationCode, $agencyId, $currency);
        if ($description['ready_for_posting'] !== true) {
            throw new InvalidArgumentException($description['reason']);
        }
    }

    /**
     * @return array{
     *     operation_code:string,
     *     agency_id:int,
     *     currency:string,
     *     ready_for_posting:bool,
     *     reason:string,
     *     debit_ledger_account_id:?int,
     *     credit_ledger_account_id:?int,
     * }
     */
    public function describe(string $operationCode, int $agencyId, string $currency): array
    {
        $base = [
            'operation_code' => $operationCode,
            'agency_id' => $agencyId,
            'currency' => $currency,
            'ready_for_posting' => false,
            'reason' => '',
            'debit_ledger_account_id' => null,
            'credit_ledger_account_id' => null,
        ];

        $code = DB::table('operation_codes')->where('code', $operationCode)->first(['id', 'status']);
        if (! is_object($code)) {
            return [...$base, 'reason' => __('reporting.mapping_operation_code_does_not_exist', ['code' => $operationCode])];
        }
        if ((string) (((array) $code)['status'] ?? '') !== 'active') {
            return [...$base, 'reason' => __('reporting.mapping_operation_code_not_active', ['code' => $operationCode])];
        }

        $mapping = DB::table('operation_account_mappings')
            ->where('operation_code_id', (int) $code->id)
            ->where('status', 'active')
            ->where(function ($builder) use ($currency): void {
                $builder->where('currency', $currency)->orWhereNull('currency');
            })
            ->orderByRaw('currency IS NULL')
            ->first(['debit_ledger_account_id', 'credit_ledger_account_id']);
        if (! is_object($mapping)) {
            return [...$base, 'reason' => __('reporting.mapping_no_active_operation_mapping', ['code' => $operationCode])];
        }

        $debit = is_numeric($mapping->debit_ledger_account_id) ? (int) $mapping->debit_ledger_account_id : null;
        $credit = is_numeric($mapping->credit_ledger_account_id) ? (int) $mapping->credit_ledger_account_id : null;
        if ($debit === null && $credit === null) {
            return [
                ...$base,
                'debit_ledger_account_id' => null,
                'credit_ledger_account_id' => null,
                'reason' => 'Mapping has neither a debit nor a credit ledger account.',
            ];
        }

        foreach (array_filter([$debit, $credit], static fn ($v) => $v !== null) as $ledgerId) {
            $ledger = DB::table('ledger_accounts')->where('id', $ledgerId)->first(['status', 'agency_id']);
            if (! is_object($ledger)) {
                return [
                    ...$base,
                    'debit_ledger_account_id' => $debit,
                    'credit_ledger_account_id' => $credit,
                    'reason' => 'Mapped ledger account does not exist.',
                ];
            }
            if ((string) (((array) $ledger)['status'] ?? '') !== 'active') {
                return [
                    ...$base,
                    'debit_ledger_account_id' => $debit,
                    'credit_ledger_account_id' => $credit,
                    'reason' => 'Mapped ledger account is not active.',
                ];
            }
            if ((int) (((array) $ledger)['agency_id'] ?? 0) !== $agencyId) {
                return [
                    ...$base,
                    'debit_ledger_account_id' => $debit,
                    'credit_ledger_account_id' => $credit,
                    'reason' => 'Mapped ledger account is outside the agency.',
                ];
            }
        }

        return [
            'operation_code' => $operationCode,
            'agency_id' => $agencyId,
            'currency' => $currency,
            'ready_for_posting' => true,
            'reason' => 'ok',
            'debit_ledger_account_id' => $debit,
            'credit_ledger_account_id' => $credit,
        ];
    }
}
