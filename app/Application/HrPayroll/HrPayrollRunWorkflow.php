<?php

declare(strict_types=1);

namespace App\Application\HrPayroll;

use App\Http\Controllers\BaseController;
use App\Models\AccountingDay;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\LedgerAccount;
use App\Models\User;
use App\Support\AccountingDay\AccountingDayGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class HrPayrollRunWorkflow extends BaseController
{
    public function __construct(
        private readonly AccountingDayGuard $accountingDayGuard,
    ) {}

    public function storePayrollRun(Request $request): JsonResponse
    {
        if (! $this->requirePlatformAdmin($request)) {
            return $this->respondForbidden();
        }

        $validated = Validator::make($request->all(), [
            'agency_public_id' => ['sometimes', 'nullable', 'string', 'exists:agencies,public_id'],
            'period_starts_on' => ['required', 'date'],
            'period_ends_on' => ['required', 'date', 'after_or_equal:period_starts_on'],
            'formula_set_public_id' => ['sometimes', 'nullable', 'string', 'exists:hr_payroll_formula_sets,public_id'],
            'correction_of_run_public_id' => ['sometimes', 'nullable', 'string', 'exists:hr_payroll_runs,public_id'],
            'employees' => ['required', 'array', 'min:1'],
            'employees.*.employee_public_id' => ['required', 'string', 'exists:hr_employees,public_id'],
            'employees.*.gross_amount_minor' => ['required', 'integer', 'min:0'],
            'employees.*.sector' => ['sometimes', 'nullable', 'string', 'max:32'],
            'employees.*.absence_deduction_minor' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'employees.*.approved_leave_public_id' => ['sometimes', 'nullable', 'string', 'exists:hr_leave_requests,public_id'],
        ])->validate();

        $actor = $request->user();
        if (! $actor instanceof User) {
            return $this->respondForbidden();
        }

        try {
            $runPublicId = DB::transaction(function () use ($validated, $actor): string {
                $agencyId = $this->idByPublicId('agencies', $validated['agency_public_id'] ?? null);
                $period = (string) $validated['period_starts_on'];
                $periodEnd = (string) $validated['period_ends_on'];
                $set = $this->resolveFormulaSet($validated['formula_set_public_id'] ?? null, $period, $periodEnd);
                if (! is_object($set)) {
                    throw new InvalidArgumentException('No active payroll formula set covers this period.');
                }
                $correctionOfRunId = null;
                if (is_string($validated['correction_of_run_public_id'] ?? null) && $validated['correction_of_run_public_id'] !== '') {
                    $prior = DB::table('hr_payroll_runs')->where('public_id', $validated['correction_of_run_public_id'])->first(['id', 'status', 'agency_id']);
                    if (! is_object($prior) || $this->rowString($prior, 'status') !== 'approved') {
                        throw new InvalidArgumentException('Correction run requires an approved source payroll run.');
                    }
                    if ($agencyId !== null && $this->rowNullableInt($prior, 'agency_id') !== $agencyId) {
                        throw new InvalidArgumentException('Correction run agency must match the source payroll run.');
                    }
                    $correctionOfRunId = $this->rowInt($prior, 'id');
                    $agencyId = $this->rowNullableInt($prior, 'agency_id');
                }

                $rates = DB::table('hr_payroll_formula_rates')
                    ->where('hr_payroll_formula_set_id', $this->rowInt($set, 'id'))
                    ->get();
                $snapshot = [
                    'formula_set_public_id' => $this->rowString($set, 'public_id'),
                    'formula_set_version' => $this->rowInt($set, 'version'),
                    'formula_set_code' => $this->rowString($set, 'code'),
                    'rates' => $rates->map(fn (object $r): array => [
                        'branch' => $this->rowString($r, 'branch'),
                        'sector' => $this->rowNullableString($r, 'sector'),
                        'payer' => $this->rowString($r, 'payer'),
                        'rate' => $this->rowString($r, 'rate'),
                        'ceiling_minor' => $this->rowNullableInt($r, 'ceiling_minor'),
                    ])->all(),
                ];

                $runPublicId = (string) Str::ulid();
                $runId = DB::table('hr_payroll_runs')->insertGetId([
                    'public_id' => $runPublicId,
                    'agency_id' => $agencyId,
                    'hr_payroll_formula_set_id' => $this->rowInt($set, 'id'),
                    'formula_snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR),
                    'correction_of_run_id' => $correctionOfRunId,
                    'created_by_user_id' => $actor->id,
                    'period_key' => substr($period, 0, 7),
                    'period_starts_on' => $period,
                    'period_ends_on' => (string) $validated['period_ends_on'],
                    'status' => 'draft',
                    'gross_amount_minor' => 0,
                    'deduction_amount_minor' => 0,
                    'net_amount_minor' => 0,
                    'currency' => $this->rowString($set, 'currency'),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $totalGross = 0;
                $totalDeduction = 0;
                $totalNet = 0;
                $employeesInput = is_array($validated['employees'] ?? null) ? $validated['employees'] : [];
                foreach ($employeesInput as $entry) {
                    if (! is_array($entry)) {
                        continue;
                    }
                    $employeePublicId = is_string($entry['employee_public_id'] ?? null) ? $entry['employee_public_id'] : '';
                    $employee = DB::table('hr_employees')->where('public_id', $employeePublicId)->first(['id', 'agency_id', 'first_name', 'last_name']);
                    if (! is_object($employee)) {
                        throw new InvalidArgumentException('Employee is invalid.');
                    }
                    if ($agencyId !== null && $this->rowNullableInt($employee, 'agency_id') !== $agencyId) {
                        throw new InvalidArgumentException('Payroll employee must belong to the payroll agency.');
                    }
                    $gross = is_numeric($entry['gross_amount_minor'] ?? null) ? (int) $entry['gross_amount_minor'] : 0;
                    $sector = is_string($entry['sector'] ?? null) && $entry['sector'] !== '' ? $entry['sector'] : null;
                    $absenceDeduction = is_numeric($entry['absence_deduction_minor'] ?? null) ? (int) $entry['absence_deduction_minor'] : 0;
                    if ($absenceDeduction > 0) {
                        $leavePublicId = is_string($entry['approved_leave_public_id'] ?? null) ? $entry['approved_leave_public_id'] : '';
                        $leave = DB::table('hr_leave_requests')
                            ->where('public_id', $leavePublicId)
                            ->where('hr_employee_id', $this->rowInt($employee, 'id'))
                            ->where('status', 'approved')
                            ->first(['id']);
                        if (! is_object($leave)) {
                            throw new InvalidArgumentException('Absence deductions require an approved leave/absence record for the employee.');
                        }
                    }

                    $rateRows = array_values(array_filter($rates->all(), 'is_object'));
                    [$deduction, $lines] = $this->computeDeductions($rateRows, $gross, $sector);
                    $deduction += $absenceDeduction;
                    if ($absenceDeduction > 0) {
                        $lines[] = [
                            'line_type' => 'approved_absence_deduction',
                            'label' => 'Approved absence deduction',
                            'amount_minor' => $absenceDeduction,
                        ];
                    }
                    $net = $gross - $deduction;

                    $slipId = DB::table('hr_payroll_slips')->insertGetId([
                        'public_id' => (string) Str::ulid(),
                        'hr_payroll_run_id' => $runId,
                        'hr_employee_id' => $this->rowInt($employee, 'id'),
                        'slip_number' => 'SLP-'.Str::upper(Str::random(8)),
                        'gross_amount_minor' => $gross,
                        'deduction_amount_minor' => $deduction,
                        'net_amount_minor' => $net,
                        'currency' => $this->rowString($set, 'currency'),
                        'status' => 'draft',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    foreach ($lines as $line) {
                        DB::table('hr_payroll_lines')->insert([
                            'hr_payroll_slip_id' => $slipId,
                            'line_type' => $line['line_type'],
                            'label' => $line['label'],
                            'amount_minor' => $line['amount_minor'],
                            'currency' => $this->rowString($set, 'currency'),
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }

                    $totalGross += $gross;
                    $totalDeduction += $deduction;
                    $totalNet += $net;
                }

                DB::table('hr_payroll_runs')->where('id', $runId)->update([
                    'gross_amount_minor' => $totalGross,
                    'deduction_amount_minor' => $totalDeduction,
                    'net_amount_minor' => $totalNet,
                    'updated_at' => now(),
                ]);

                return $runPublicId;
            });
        } catch (InvalidArgumentException $exception) {
            return $this->respondUnprocessable(errors: ['hr_payroll_run' => [$exception->getMessage()]]);
        }

        $run = DB::table('hr_payroll_runs')->where('public_id', $runPublicId)->first();
        if (! is_object($run)) {
            return $this->respondUnprocessable(errors: ['hr_payroll_run' => ['Run could not be reloaded.']]);
        }

        return $this->respondCreated($this->payrollRunPayload($run), 'Payroll run draft created');
    }

    public function storeCorrectionPayrollRun(Request $request, string $runPublicId): JsonResponse
    {
        $request->merge(['correction_of_run_public_id' => $runPublicId]);

        return $this->storePayrollRun($request);
    }

    public function approvePayrollRun(Request $request, string $runPublicId): JsonResponse
    {
        if (! $this->requirePlatformAdmin($request)) {
            return $this->respondForbidden();
        }

        $actor = $request->user();
        if (! $actor instanceof User) {
            return $this->respondForbidden();
        }

        try {
            $row = DB::transaction(function () use ($runPublicId, $actor): object {
                $run = DB::table('hr_payroll_runs')->where('public_id', $runPublicId)->lockForUpdate()->first();
                if (! is_object($run)) {
                    throw new InvalidArgumentException('Payroll run is invalid.');
                }
                if ($this->rowString($run, 'status') !== 'draft') {
                    throw new InvalidArgumentException('Only draft payroll runs can be approved.');
                }
                if ($this->rowNullableInt($run, 'created_by_user_id') === $actor->id) {
                    throw new InvalidArgumentException('Maker cannot approve their own payroll run.');
                }

                $agencyId = $this->rowNullableInt($run, 'agency_id');
                if ($agencyId === null) {
                    throw new InvalidArgumentException('Payroll run requires an agency for posting.');
                }

                $accountingDay = $this->accountingDayGuard->assertCanRegister($actor, 'hr.payroll', $agencyId);
                $businessDate = $accountingDay->business_date?->toDateString();
                if ($businessDate === null) {
                    throw new InvalidArgumentException('Open accounting day is missing a business date for payroll posting.');
                }

                $mapping = $this->payrollMapping($agencyId);
                $gross = $this->rowInt($run, 'gross_amount_minor');
                $net = $this->rowInt($run, 'net_amount_minor');
                $deductions = $this->rowInt($run, 'deduction_amount_minor');
                $currency = $this->rowString($run, 'currency');

                $correctionOfRunId = $this->rowNullableInt($run, 'correction_of_run_id');
                if ($correctionOfRunId !== null) {
                    $prior = DB::table('hr_payroll_runs')->where('id', $correctionOfRunId)->lockForUpdate()->first();
                    if (! is_object($prior) || $this->rowString($prior, 'status') !== 'approved') {
                        throw new InvalidArgumentException('Correction source payroll run is invalid.');
                    }
                    if ($this->rowNullableInt($prior, 'reversal_of_run_id') !== null) {
                        throw new InvalidArgumentException('Correction source payroll run has already been reversed by another correction.');
                    }
                    $priorJournal = JournalEntry::query()->whereKey($this->rowInt($prior, 'journal_entry_id'))->first();
                    if (! $priorJournal instanceof JournalEntry) {
                        throw new InvalidArgumentException('Correction source payroll journal is missing.');
                    }
                    $this->createReversingEntry($priorJournal, $actor, 'hr-payroll-correction:'.$runPublicId, $accountingDay);
                    DB::table('hr_payroll_runs')->where('id', $this->rowInt($prior, 'id'))->update([
                        'status' => 'corrected',
                        'reversal_of_run_id' => $this->rowInt($run, 'id'),
                        'updated_at' => now(),
                    ]);
                }

                $journalEntry = JournalEntry::query()->create([
                    'public_id' => (string) Str::ulid(),
                    'reference' => 'PAYROLL-'.$this->rowString($run, 'period_key').'-'.Str::upper(Str::random(6)),
                    'business_date' => $businessDate,
                    'accounting_day_id' => $accountingDay->id,
                    'posted_at' => null,
                    'agency_id' => $agencyId,
                    'source_module' => 'hr',
                    'source_type' => 'hr_payroll_run',
                    'source_public_id' => $runPublicId,
                    'status' => JournalEntry::STATUS_DRAFT,
                    'description' => 'Payroll run '.$this->rowString($run, 'period_key'),
                    'created_by_user_id' => $actor->id,
                    'idempotency_key' => 'hr-payroll:'.$runPublicId,
                ]);

                JournalLine::query()->create([
                    'public_id' => (string) Str::ulid(),
                    'agency_id' => $agencyId,
                    'journal_entry_id' => $journalEntry->id,
                    'ledger_account_id' => $mapping['salary_expense'],
                    'debit_minor' => $gross,
                    'credit_minor' => 0,
                    'currency' => $currency,
                    'line_memo' => 'Payroll gross salary expense',
                ]);
                JournalLine::query()->create([
                    'public_id' => (string) Str::ulid(),
                    'agency_id' => $agencyId,
                    'journal_entry_id' => $journalEntry->id,
                    'ledger_account_id' => $mapping['net_payable'],
                    'debit_minor' => 0,
                    'credit_minor' => $net,
                    'currency' => $currency,
                    'line_memo' => 'Net payable to employees',
                ]);
                if ($deductions > 0) {
                    JournalLine::query()->create([
                        'public_id' => (string) Str::ulid(),
                        'agency_id' => $agencyId,
                        'journal_entry_id' => $journalEntry->id,
                        'ledger_account_id' => $mapping['deductions_payable'],
                        'debit_minor' => 0,
                        'credit_minor' => $deductions,
                        'currency' => $currency,
                        'line_memo' => 'Payroll deductions payable',
                    ]);
                }

                $this->postSystemJournal($journalEntry, $actor);

                DB::table('hr_payroll_runs')->where('id', $this->rowInt($run, 'id'))->update([
                    'status' => 'approved',
                    'approved_by_user_id' => $actor->id,
                    'approved_at' => now(),
                    'journal_entry_id' => $journalEntry->id,
                    'updated_at' => now(),
                ]);
                DB::table('hr_payroll_slips')->where('hr_payroll_run_id', $this->rowInt($run, 'id'))->update([
                    'status' => 'approved',
                    'journal_entry_id' => $journalEntry->id,
                    'updated_at' => now(),
                ]);

                $updated = DB::table('hr_payroll_runs')->where('id', $this->rowInt($run, 'id'))->first();
                if (! is_object($updated)) {
                    throw new InvalidArgumentException('Payroll run could not be reloaded.');
                }

                return $updated;
            });
        } catch (InvalidArgumentException $exception) {
            return $this->respondUnprocessable(errors: ['hr_payroll_run' => [$exception->getMessage()]]);
        }

        return $this->respondSuccess($this->payrollRunPayload($row), 'Payroll run approved and posted');
    }

    public function declarationExport(Request $request, string $runPublicId): JsonResponse
    {
        if (! $this->requirePlatformAdmin($request)) {
            return $this->respondForbidden();
        }

        $run = DB::table('hr_payroll_runs')->where('public_id', $runPublicId)->first();
        if (! is_object($run)) {
            return $this->respondNotFound('Payroll run not found.');
        }
        if ($this->rowString($run, 'status') !== 'approved') {
            return $this->respondUnprocessable(errors: ['hr_payroll_run' => ['Only approved payroll runs can produce a final declaration export.']]);
        }

        $slips = DB::table('hr_payroll_slips as slip')
            ->join('hr_employees as emp', 'emp.id', '=', 'slip.hr_employee_id')
            ->where('slip.hr_payroll_run_id', $this->rowInt($run, 'id'))
            ->select([
                'slip.slip_number as slip_number',
                'slip.gross_amount_minor as gross_amount_minor',
                'slip.deduction_amount_minor as deduction_amount_minor',
                'slip.net_amount_minor as net_amount_minor',
                'emp.employee_number as employee_number',
                'emp.identity_number as identity_number',
            ])
            ->orderBy('slip.id')
            ->get()
            ->map(fn (object $row): array => [
                'employee_number' => $this->rowString($row, 'employee_number'),
                'identity_number' => $this->rowNullableString($row, 'identity_number'),
                'slip_number' => $this->rowString($row, 'slip_number'),
                'gross_amount_minor' => $this->rowInt($row, 'gross_amount_minor'),
                'deduction_amount_minor' => $this->rowInt($row, 'deduction_amount_minor'),
                'net_amount_minor' => $this->rowInt($row, 'net_amount_minor'),
            ])
            ->all();

        $payload = [
            'period_key' => $this->rowString($run, 'period_key'),
            'currency' => $this->rowString($run, 'currency'),
            'totals' => [
                'gross_amount_minor' => $this->rowInt($run, 'gross_amount_minor'),
                'deduction_amount_minor' => $this->rowInt($run, 'deduction_amount_minor'),
                'net_amount_minor' => $this->rowInt($run, 'net_amount_minor'),
            ],
            'slips' => $slips,
        ];
        $checksum = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));

        return $this->respondSuccess([
            'source_payroll_run_public_id' => $runPublicId,
            'checksum' => $checksum,
            'export' => $payload,
        ], 'Payroll declaration export');
    }

    /**
     * @param  list<object>  $rates
     * @return array{0:int, 1:list<array{line_type:string, label:string, amount_minor:int}>}
     */
    private function computeDeductions(array $rates, int $gross, ?string $sector): array
    {
        $totalDeduction = 0;
        $lines = [];
        foreach ($rates as $rate) {
            $rateSector = $this->rowNullableString($rate, 'sector');
            if ($rateSector !== null && $rateSector !== $sector) {
                continue;
            }
            $payer = $this->rowString($rate, 'payer');
            $branch = $this->rowString($rate, 'branch');
            $ratio = (float) $this->rowString($rate, 'rate');
            $ceiling = $this->rowNullableInt($rate, 'ceiling_minor');
            $basis = $ceiling !== null ? min($gross, $ceiling) : $gross;
            $amount = (int) round($basis * $ratio);
            if ($payer === 'employee' && $amount > 0) {
                $totalDeduction += $amount;
            }
            $lines[] = [
                'line_type' => $branch.'_'.$payer,
                'label' => mb_strtoupper($branch).' ('.$payer.')',
                'amount_minor' => $amount,
            ];
        }

        return [$totalDeduction, $lines];
    }

    /**
     * @return array{salary_expense:int, net_payable:int, deductions_payable:int}
     */
    private function payrollMapping(int $agencyId): array
    {
        $codes = ['hr_salary_expense', 'hr_net_payable', 'hr_deductions_payable'];
        $resolved = [];
        foreach ($codes as $code) {
            $mapping = DB::table('operation_account_mappings as map')
                ->join('operation_codes as op', 'op.id', '=', 'map.operation_code_id')
                ->where('op.code', $code)
                ->where('op.status', 'active')
                ->where('map.status', 'active')
                ->where(function ($q): void {
                    $q->whereNull('map.currency')->orWhere('map.currency', 'XAF');
                })
                ->first(['map.debit_ledger_account_id', 'map.credit_ledger_account_id']);
            if (! is_object($mapping)) {
                throw new InvalidArgumentException('Active operation mapping is required for '.$code.'.');
            }
            $ledgerId = match ($code) {
                'hr_salary_expense' => is_numeric($mapping->debit_ledger_account_id) ? (int) $mapping->debit_ledger_account_id : null,
                default => is_numeric($mapping->credit_ledger_account_id) ? (int) $mapping->credit_ledger_account_id : null,
            };
            if ($ledgerId === null) {
                throw new InvalidArgumentException('Mapping for '.$code.' is missing required ledger.');
            }
            $ledger = LedgerAccount::query()->whereKey($ledgerId)->first();
            if (! $ledger instanceof LedgerAccount
                || $ledger->status !== LedgerAccount::STATUS_ACTIVE
                || $ledger->agency_id !== $agencyId) {
                throw new InvalidArgumentException('Mapped ledger for '.$code.' must be active and agency-scoped.');
            }
            $resolved[$code] = $ledgerId;
        }

        return [
            'salary_expense' => $resolved['hr_salary_expense'],
            'net_payable' => $resolved['hr_net_payable'],
            'deductions_payable' => $resolved['hr_deductions_payable'],
        ];
    }

    private function resolveFormulaSet(mixed $publicId, string $periodStart, string $periodEnd): ?object
    {
        if (is_string($publicId) && $publicId !== '') {
            $row = DB::table('hr_payroll_formula_sets')->where('public_id', $publicId)->first();
            if (! is_object($row) || $this->rowString($row, 'status') !== 'active') {
                return null;
            }
            if ($this->rowString($row, 'effective_from') > $periodStart) {
                return null;
            }
            $effectiveTo = $this->rowNullableString($row, 'effective_to');
            if ($effectiveTo !== null && $effectiveTo < $periodEnd) {
                return null;
            }

            return $row;
        }

        $row = DB::table('hr_payroll_formula_sets')
            ->where('status', 'active')
            ->where('effective_from', '<=', $periodStart)
            ->where(function ($q) use ($periodEnd): void {
                $q->whereNull('effective_to')->orWhere('effective_to', '>=', $periodEnd);
            })
            ->orderByDesc('version')
            ->first();

        return is_object($row) ? $row : null;
    }

    private function postSystemJournal(JournalEntry $journalEntry, User $actor): void
    {
        $journalEntry->forceFill([
            'status' => JournalEntry::STATUS_SUBMITTED,
            'submitted_at' => now(),
            'submitted_by_user_id' => $actor->id,
        ])->save();
        $journalEntry->forceFill([
            'status' => JournalEntry::STATUS_APPROVED,
            'reviewed_at' => now(),
            'reviewed_by_user_id' => $actor->id,
        ])->save();
        $journalEntry->forceFill([
            'status' => JournalEntry::STATUS_POSTED,
            'posted_at' => now(),
            'posted_by_user_id' => $actor->id,
        ])->save();
    }

    private function createReversingEntry(JournalEntry $original, User $actor, string $idempotencyKey, AccountingDay $accountingDay): JournalEntry
    {
        $reversal = JournalEntry::query()->create([
            'public_id' => (string) Str::ulid(),
            'reference' => 'REV-'.$original->reference,
            'business_date' => $accountingDay->business_date?->toDateString(),
            'accounting_day_id' => $accountingDay->id,
            'posted_at' => null,
            'agency_id' => $original->agency_id,
            'source_module' => $original->source_module,
            'source_type' => $original->source_type.'_reversal',
            'source_public_id' => $original->source_public_id,
            'status' => JournalEntry::STATUS_DRAFT,
            'description' => 'Reversal of '.$original->reference,
            'reversal_of_journal_entry_id' => $original->id,
            'created_by_user_id' => $actor->id,
            'idempotency_key' => $idempotencyKey,
        ]);

        foreach ($original->lines as $line) {
            JournalLine::query()->create([
                'public_id' => (string) Str::ulid(),
                'agency_id' => $line->agency_id,
                'journal_entry_id' => $reversal->id,
                'ledger_account_id' => $line->ledger_account_id,
                'customer_account_id' => $line->customer_account_id,
                'loan_id' => $line->loan_id,
                'debit_minor' => $line->credit_minor,
                'credit_minor' => $line->debit_minor,
                'currency' => $line->currency,
                'line_memo' => 'Reversal of '.$line->line_memo,
            ]);
        }

        $this->postSystemJournal($reversal, $actor);

        return $reversal;
    }

    /**
     * @return array<string, mixed>
     */
    private function payrollRunPayload(object $row): array
    {
        return [
            'public_id' => $this->rowString($row, 'public_id'),
            'period_key' => $this->rowString($row, 'period_key'),
            'status' => $this->rowString($row, 'status'),
            'gross_amount_minor' => $this->rowInt($row, 'gross_amount_minor'),
            'deduction_amount_minor' => $this->rowInt($row, 'deduction_amount_minor'),
            'net_amount_minor' => $this->rowInt($row, 'net_amount_minor'),
            'currency' => $this->rowString($row, 'currency'),
            'journal_entry_id' => $this->rowNullableInt($row, 'journal_entry_id'),
        ];
    }

    private function requirePlatformAdmin(Request $request): bool
    {
        $actor = $request->user();

        return $actor instanceof User && $actor->hasRole('platform-admin');
    }

    private function idByPublicId(string $table, mixed $publicId): ?int
    {
        if (! is_string($publicId) || $publicId === '') {
            return null;
        }
        $row = DB::table($table)->where('public_id', $publicId)->first(['id']);

        return is_object($row) && is_numeric($row->id) ? (int) $row->id : null;
    }

    private function rowString(object $row, string $key): string
    {
        $value = ((array) $row)[$key] ?? '';

        return is_string($value) ? $value : (string) $value;
    }

    private function rowNullableString(object $row, string $key): ?string
    {
        $value = ((array) $row)[$key] ?? null;

        return $value === null ? null : (string) $value;
    }

    private function rowInt(object $row, string $key): int
    {
        $value = ((array) $row)[$key] ?? 0;

        return is_numeric($value) ? (int) $value : 0;
    }

    private function rowNullableInt(object $row, string $key): ?int
    {
        $value = ((array) $row)[$key] ?? null;

        return is_numeric($value) ? (int) $value : null;
    }
}
