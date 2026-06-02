<?php

declare(strict_types=1);

namespace App\Application\AccountingDays;

use App\Application\BatchRuns\ExecuteRegisteredBatchRun;
use App\Http\Controllers\BaseController;
use App\Http\Requests\CloseAccountingDayRequest;
use App\Http\Requests\OpenAccountingDayRequest;
use App\Http\Requests\ReopenAccountingDayRequest;
use App\Http\Resources\AccountingDayCollection;
use App\Http\Resources\AccountingDayResource;
use App\Models\AccountingCalendarDay;
use App\Models\AccountingDay;
use App\Models\Agency;
use App\Models\BatchProcedure;
use App\Models\BatchRun;
use App\Models\User;
use App\Support\AccountingDay\CloseControlService;
use App\Support\Security\SecurityAudit;
use App\Support\Staff\StaffAgencyScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class AccountingDayWorkflow extends BaseController
{
    public function __construct(
        private readonly SecurityAudit $securityAudit,
        private readonly StaffAgencyScope $staffAgencyScope,
        private readonly CloseControlService $closeControls,
        private readonly ExecuteRegisteredBatchRun $executeRegisteredBatchRun,
    ) {}

    public function index(Request $request): AccountingDayCollection|JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || $actor->cannot('viewAny', AccountingDay::class)) {
            return $this->respondForbidden();
        }

        $query = AccountingDay::query()->with(['agency', 'openedBy', 'closedBy', 'reopenedBy'])->latest('business_date');

        if (! $actor->hasRole('platform-admin')) {
            $agencyId = $this->staffAgencyScope->currentAgencyId($actor);
            if ($agencyId === null) {
                return $this->respondForbidden();
            }
            $query->where('agency_id', $agencyId);
        }

        $status = $request->query('status');
        if (is_string($status) && $status !== '') {
            $query->where('status', $status);
        }

        return new AccountingDayCollection($query->paginate(min(max($request->integer('per_page', 25), 1), 100)));
    }

    public function current(Request $request): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || $actor->cannot('viewAny', AccountingDay::class)) {
            return $this->respondForbidden();
        }

        [$scopeType, $agencyId, $error] = $this->resolveScopeForRequest($actor, $request);
        if ($error !== null) {
            return $error;
        }

        $day = $this->findActiveDay($scopeType, $agencyId)
            ?? $this->findLatestDay($scopeType, $agencyId);

        if (! $day instanceof AccountingDay) {
            return $this->respondNotFound('No accounting day is configured for your scope. The system is in consultation-only mode.');
        }

        if ($actor->cannot('view', $day)) {
            return $this->respondForbidden();
        }

        return $this->respondSuccess(
            AccountingDayResource::make($day->loadMissing(['agency', 'openedBy', 'closedBy', 'reopenedBy'])),
        );
    }

    public function show(Request $request, AccountingDay $accountingDay): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || $actor->cannot('view', $accountingDay)) {
            return $this->respondForbidden();
        }

        return $this->respondSuccess(
            AccountingDayResource::make($accountingDay->loadMissing(['agency', 'openedBy', 'closedBy', 'reopenedBy'])),
        );
    }

    public function open(OpenAccountingDayRequest $request): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User) {
            return $this->respondForbidden();
        }

        [$scopeType, $agencyId, $error] = $this->resolveScopeForRequest($actor, $request, requireForWrite: true);
        if ($error !== null) {
            return $error;
        }

        // Idempotent open: an already-active day for the scope is returned as-is.
        $existing = $this->findActiveDay($scopeType, $agencyId);
        if ($existing instanceof AccountingDay) {
            return $this->respondSuccess(
                AccountingDayResource::make($existing->loadMissing(['agency', 'openedBy', 'closedBy', 'reopenedBy'])),
                'An accounting day is already open for this scope.',
            );
        }

        $businessDate = $this->resolveBusinessDateForOpen($request, $scopeType, $agencyId);
        $holiday = $this->holidayForDate($scopeType, $agencyId, $businessDate);

        try {
            $day = DB::transaction(function () use ($scopeType, $agencyId, $businessDate, $actor, $holiday): AccountingDay {
                return AccountingDay::query()->create([
                    'public_id' => (string) Str::ulid(),
                    'scope_type' => $scopeType,
                    'agency_id' => $agencyId,
                    'business_date' => $businessDate,
                    'calendar_opened_at' => now(),
                    'status' => AccountingDay::STATUS_OPEN,
                    'is_holiday' => $holiday !== null,
                    'holiday_name' => $holiday,
                    'opened_by_user_id' => $actor->id,
                    'origin' => AccountingDay::ORIGIN_MANUAL,
                    'write_lock_version' => 0,
                ]);
            });
        } catch (QueryException $exception) {
            // Unique partial index race: another open already won.
            $existing = $this->findActiveDay($scopeType, $agencyId)
                ?? $this->findDayByDate($scopeType, $agencyId, $businessDate);
            if ($existing instanceof AccountingDay) {
                return $this->respondSuccess(
                    AccountingDayResource::make($existing->loadMissing(['agency', 'openedBy', 'closedBy', 'reopenedBy'])),
                    'An accounting day is already open for this scope.',
                );
            }

            throw $exception;
        }

        $this->securityAudit->record('accounting_day.opened', actor: $actor, subject: $day, properties: [
            'scope_type' => $scopeType,
            'agency_id_scope' => $agencyId,
            'business_date' => $businessDate,
            'previous_status' => null,
            'new_status' => AccountingDay::STATUS_OPEN,
            'is_holiday' => $holiday !== null,
        ], request: $request);

        return $this->respondCreated(
            AccountingDayResource::make($day->loadMissing(['agency', 'openedBy', 'closedBy', 'reopenedBy'])),
            'Accounting day opened successfully',
        );
    }

    public function startClose(Request $request, AccountingDay $accountingDay): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || $actor->cannot('startClose', $accountingDay)) {
            return $this->respondForbidden();
        }

        if ($accountingDay->status === AccountingDay::STATUS_CLOSING) {
            return $this->respondSuccess(
                $this->daySummaryPayload($accountingDay),
                'Accounting day is already closing.',
            );
        }

        if (! in_array($accountingDay->status, [AccountingDay::STATUS_OPEN, AccountingDay::STATUS_REOPENED], true)) {
            return $this->respondUnprocessable('Invalid accounting day transition.', [
                'code' => 'accounting_day_invalid_transition',
                'status' => $accountingDay->status,
            ]);
        }

        $previousStatus = $accountingDay->status;

        DB::transaction(function () use ($accountingDay): void {
            $accountingDay->fill([
                'status' => AccountingDay::STATUS_CLOSING,
                'write_lock_version' => $accountingDay->write_lock_version + 1,
            ])->save();
        });

        $batchSummary = $this->executeCloseControlRuns($accountingDay, $actor);
        $readiness = $this->closeControls->evaluate($accountingDay->refresh());

        $accountingDay->fill([
            'close_summary_payload' => [
                ...$readiness->toArray(),
                'close_control_batches' => $batchSummary['controls'],
                'close_control_batch_failures' => $batchSummary['failed_controls'],
                'close_control_batch_run_public_ids' => $batchSummary['run_public_ids'],
            ],
            'closing_batch_run_id' => $batchSummary['primary_run_id'],
        ])->save();

        $this->securityAudit->record('accounting_day.close_started', actor: $actor, subject: $accountingDay, properties: [
            'scope_type' => $accountingDay->scope_type,
            'agency_id_scope' => $accountingDay->agency_id,
            'business_date' => $accountingDay->business_date->toDateString(),
            'previous_status' => $previousStatus,
            'new_status' => AccountingDay::STATUS_CLOSING,
        ], request: $request);

        return $this->respondSuccess(
            $this->daySummaryPayload($accountingDay->refresh()),
            'Accounting day close started. Registrations are now blocked while controls run.',
        );
    }

    public function close(CloseAccountingDayRequest $request, AccountingDay $accountingDay): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || $actor->cannot('close', $accountingDay)) {
            return $this->respondForbidden();
        }

        if ($accountingDay->status === AccountingDay::STATUS_CLOSED) {
            return $this->respondSuccess(
                $this->daySummaryPayload($accountingDay),
                'Accounting day is already closed.',
            );
        }

        if ($accountingDay->status !== AccountingDay::STATUS_CLOSING) {
            return $this->respondUnprocessable('The accounting day must be in the closing state before it can be closed.', [
                'code' => 'accounting_day_invalid_transition',
                'status' => $accountingDay->status,
            ]);
        }

        $batchSummary = $this->executeCloseControlRuns($accountingDay, $actor);
        $batchBlocked = $batchSummary['failed_controls'] !== [];

        try {
            $result = DB::transaction(function () use ($accountingDay, $actor, $batchSummary, $batchBlocked): array {
                $query = AccountingDay::query()->whereKey($accountingDay->id);
                $query->getQuery()->lockForUpdate();
                $locked = $query->firstOrFail();

                if ($locked->status === AccountingDay::STATUS_CLOSED) {
                    return ['day' => $locked, 'readiness' => null];
                }

                $readiness = $this->closeControls->evaluate($locked);
                $closeSummary = [
                    ...$readiness->toArray(),
                    'close_control_batches' => $batchSummary['controls'],
                    'close_control_batch_failures' => $batchSummary['failed_controls'],
                    'close_control_batch_run_public_ids' => $batchSummary['run_public_ids'],
                ];

                if (! $readiness->isReady() || $batchBlocked) {
                    $blockers = array_map(
                        static fn (array $blocker): string => $blocker['control'],
                        $readiness->blockers,
                    );
                    if ($batchBlocked) {
                        $blockers = [...$blockers, ...$batchSummary['failed_controls']];
                    }
                    $locked->fill([
                        'close_summary_payload' => $closeSummary,
                        'close_failure_reason' => 'Close controls failed: '.implode(', ', $blockers),
                    ])->save();

                    return ['day' => $locked, 'readiness' => $readiness];
                }

                $locked->fill([
                    'status' => AccountingDay::STATUS_CLOSED,
                    'calendar_closed_at' => now(),
                    'closed_by_user_id' => $actor->id,
                    'close_summary_payload' => $closeSummary,
                    'close_failure_reason' => null,
                    'write_lock_version' => $locked->write_lock_version + 1,
                ])->save();

                return ['day' => $locked, 'readiness' => $readiness];
            });
        } catch (QueryException $exception) {
            throw $exception;
        }

        $day = $result['day'];
        $readiness = $result['readiness'];

        if ($readiness !== null && ! $readiness->isReady()) {
            $this->securityAudit->record('accounting_day.close_failed', actor: $actor, subject: $day, properties: [
                'business_date' => $day->business_date->toDateString(),
                'blockers' => array_map(static fn (array $b): string => $b['control'], $readiness->blockers),
            ], request: $request);

            return $this->respondUnprocessable('Accounting day cannot be closed while controls are failing.', [
                'code' => 'accounting_day_close_blocked',
                'close_summary' => $day->close_summary_payload,
            ]);
        }

        if ($batchBlocked) {
            $this->securityAudit->record('accounting_day.close_failed', actor: $actor, subject: $day, properties: [
                'business_date' => $day->business_date->toDateString(),
                'blockers' => $batchSummary['failed_controls'],
            ], request: $request);

            return $this->respondUnprocessable('Accounting day cannot be closed while required close-control batches are failing.', [
                'code' => 'accounting_day_close_blocked',
                'close_summary' => $day->close_summary_payload,
            ]);
        }

        $this->securityAudit->record('accounting_day.closed', actor: $actor, subject: $day, properties: [
            'scope_type' => $day->scope_type,
            'agency_id_scope' => $day->agency_id,
            'business_date' => $day->business_date->toDateString(),
            'previous_status' => AccountingDay::STATUS_CLOSING,
            'new_status' => AccountingDay::STATUS_CLOSED,
        ], request: $request);

        return $this->respondSuccess(
            $this->daySummaryPayload($day->refresh()),
            'Accounting day closed successfully',
        );
    }

    public function reopen(ReopenAccountingDayRequest $request, AccountingDay $accountingDay): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || $actor->cannot('reopen', $accountingDay)) {
            return $this->respondForbidden();
        }

        if ($accountingDay->status !== AccountingDay::STATUS_CLOSED) {
            return $this->respondUnprocessable('Only closed accounting days can be reopened.', [
                'code' => 'accounting_day_invalid_transition',
                'status' => $accountingDay->status,
            ]);
        }

        // Reopen must not race with a newly opened day for the same scope.
        $active = $this->findActiveDay($accountingDay->scope_type, $accountingDay->agency_id);
        if ($active instanceof AccountingDay) {
            return $this->respondUnprocessable('Another accounting day is already active for this scope; close it before reopening a prior day.', [
                'code' => 'accounting_day_active_exists',
                'active_business_date' => $active->business_date->toDateString(),
            ]);
        }

        $reason = $request->string('reason')->toString();

        DB::transaction(function () use ($accountingDay, $actor, $reason): void {
            $accountingDay->fill([
                'status' => AccountingDay::STATUS_REOPENED,
                'reopened_by_user_id' => $actor->id,
                'reopen_reason' => $reason,
                'calendar_closed_at' => null,
                'write_lock_version' => $accountingDay->write_lock_version + 1,
            ])->save();
        });

        $this->securityAudit->record('accounting_day.reopened', actor: $actor, subject: $accountingDay, properties: [
            'scope_type' => $accountingDay->scope_type,
            'agency_id_scope' => $accountingDay->agency_id,
            'business_date' => $accountingDay->business_date->toDateString(),
            'previous_status' => AccountingDay::STATUS_CLOSED,
            'new_status' => AccountingDay::STATUS_REOPENED,
            'reason' => $reason,
        ], request: $request);

        return $this->respondSuccess(
            $this->daySummaryPayload($accountingDay->refresh()),
            'Accounting day reopened successfully',
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function daySummaryPayload(AccountingDay $day): array
    {
        $resource = AccountingDayResource::make($day->loadMissing(['agency', 'openedBy', 'closedBy', 'reopenedBy']));

        return $resource->resolve();
    }

    /**
     * @return array{
     *   controls: array<int, array<string, mixed>>,
     *   failed_controls: array<int, string>,
     *   run_public_ids: array<int, string>,
     *   primary_run_id: int|null
     * }
     */
    private function executeCloseControlRuns(AccountingDay $day, User $actor): array
    {
        $controlCodes = [
            'accounting_close_verification',
            'cash_close_verification',
        ];

        $controls = [];
        $failedControls = [];
        $runPublicIds = [];
        $primaryRunId = null;

        foreach ($controlCodes as $index => $code) {
            $procedureQuery = BatchProcedure::query()
                ->where('status', BatchProcedure::STATUS_ACTIVE);
            $procedureQuery->getQuery()->whereRaw('LOWER(REPLACE(code, ?, ?)) = ?', ['-', '_', $code]);
            $procedure = $procedureQuery->first();

            if (! $procedure instanceof BatchProcedure) {
                $controls[] = [
                    'procedure_code' => $code,
                    'status' => 'missing_procedure',
                    'message' => 'No active batch procedure is configured for this close control.',
                ];
                $failedControls[] = $code;

                continue;
            }

            $run = BatchRun::query()
                ->where('batch_procedure_id', $procedure->id)
                ->where('business_date', $day->business_date->toDateString())
                ->when(
                    $day->scope_type === AccountingDay::SCOPE_AGENCY,
                    fn ($query) => $query->where('agency_id', $day->agency_id),
                    fn ($query) => $query->getQuery()->whereNull('agency_id'),
                )
                ->latest('id')
                ->first();

            if (! $run instanceof BatchRun) {
                $run = BatchRun::query()->create([
                    'public_id' => (string) Str::ulid(),
                    'batch_procedure_id' => $procedure->id,
                    'agency_id' => $day->scope_type === AccountingDay::SCOPE_AGENCY ? $day->agency_id : null,
                    'accounting_day_id' => $day->id,
                    'business_date' => $day->business_date->toDateString(),
                    'status' => BatchRun::STATUS_PENDING,
                    'operator_user_id' => $actor->id,
                    'summary_payload' => [
                        'origin' => 'accounting_day_close',
                        'accounting_day_public_id' => $day->public_id,
                    ],
                ]);
            } elseif ($run->accounting_day_id === null) {
                $run->forceFill(['accounting_day_id' => $day->id])->save();
            }

            if ($primaryRunId === null && $index === 0) {
                $primaryRunId = $run->id;
            }

            if (in_array($run->status, [BatchRun::STATUS_PENDING, BatchRun::STATUS_FAILED], true)) {
                try {
                    $run = $this->executeRegisteredBatchRun->execute($run);
                } catch (InvalidArgumentException $exception) {
                    $run->forceFill([
                        'status' => BatchRun::STATUS_FAILED,
                        'failure_reason' => $exception->getMessage(),
                        'finished_at' => now(),
                    ])->save();
                }
            }

            $runPublicIds[] = $run->public_id;
            $controls[] = [
                'procedure_code' => $code,
                'batch_run_public_id' => $run->public_id,
                'status' => $run->status,
                'summary_payload' => $run->summary_payload,
                'failure_reason' => $run->failure_reason,
            ];

            if ($run->status !== BatchRun::STATUS_SUCCEEDED) {
                $failedControls[] = $code;
            }
        }

        return [
            'controls' => $controls,
            'failed_controls' => array_values(array_unique($failedControls)),
            'run_public_ids' => $runPublicIds,
            'primary_run_id' => $primaryRunId,
        ];
    }

    /**
     * @return array{0: string, 1: int|null, 2: JsonResponse|null}
     */
    private function resolveScopeForRequest(User $actor, Request $request, bool $requireForWrite = false): array
    {
        $scope = $request->input('scope', AccountingDay::SCOPE_AGENCY);
        if ($scope === AccountingDay::SCOPE_INSTITUTION) {
            if (! $actor->hasRole('platform-admin')) {
                return ['', null, $this->respondForbidden('Institution-scoped accounting days are reserved for platform administrators.')];
            }

            return [AccountingDay::SCOPE_INSTITUTION, null, null];
        }

        $agencyPublicId = $request->input('agency_public_id');
        if (is_string($agencyPublicId) && $agencyPublicId !== '') {
            $agency = Agency::query()->where('public_id', $agencyPublicId)->first();
            if (! $agency instanceof Agency) {
                return ['', null, $this->respondUnprocessable(errors: ['agency_public_id' => ['The selected agency is invalid.']])];
            }

            if (! $actor->hasRole('platform-admin') && $this->staffAgencyScope->currentAgencyId($actor) !== $agency->id) {
                return ['', null, $this->respondForbidden('You can only manage accounting days inside your agency scope.')];
            }

            return [AccountingDay::SCOPE_AGENCY, $agency->id, null];
        }

        $agencyId = $this->staffAgencyScope->currentAgencyId($actor);
        if ($agencyId === null) {
            return ['', null, $this->respondUnprocessable(errors: ['agency_public_id' => ['An agency is required to resolve the accounting day scope.']])];
        }

        return [AccountingDay::SCOPE_AGENCY, $agencyId, null];
    }

    private function resolveBusinessDateForOpen(OpenAccountingDayRequest $request, string $scopeType, ?int $agencyId): string
    {
        $supplied = $request->input('business_date');
        if (is_string($supplied) && $supplied !== '') {
            return $supplied;
        }

        // Derive the next accounting date from the latest day and the calendar.
        $latest = $this->findLatestDay($scopeType, $agencyId);
        $candidate = $latest instanceof AccountingDay
            ? $latest->business_date->copy()->addDay()
            : Carbon::today();

        // Skip configured non-business days (holidays) for the scope.
        for ($i = 0; $i < 31; $i++) {
            $calendar = $this->calendarForDate($scopeType, $agencyId, $candidate->toDateString());
            if ($calendar instanceof AccountingCalendarDay) {
                if ($calendar->is_business_day) {
                    return $calendar->business_date !== null
                        ? Carbon::parse($calendar->business_date)->toDateString()
                        : $candidate->toDateString();
                }
                $candidate->addDay();

                continue;
            }

            return $candidate->toDateString();
        }

        return $candidate->toDateString();
    }

    private function holidayForDate(string $scopeType, ?int $agencyId, string $businessDate): ?string
    {
        $calendar = $this->calendarForDate($scopeType, $agencyId, $businessDate);
        if ($calendar instanceof AccountingCalendarDay && $calendar->is_holiday) {
            return $calendar->holiday_name ?? 'Holiday';
        }

        return null;
    }

    private function calendarForDate(string $scopeType, ?int $agencyId, string $date): ?AccountingCalendarDay
    {
        $query = AccountingCalendarDay::query()
            ->where('scope_type', $scopeType)
            ->where('calendar_date', $date);
        $scopeType === AccountingDay::SCOPE_AGENCY
            ? $query->where('agency_id', $agencyId)
            : $query->getQuery()->whereNull('agency_id');

        $agencyCalendar = $query->first();
        if ($agencyCalendar instanceof AccountingCalendarDay) {
            return $agencyCalendar;
        }

        // Institution default fallback for agency scope.
        if ($scopeType === AccountingDay::SCOPE_AGENCY) {
            $institutionQuery = AccountingCalendarDay::query()
                ->where('scope_type', AccountingCalendarDay::SCOPE_INSTITUTION)
                ->where('calendar_date', $date);
            $institutionQuery->getQuery()->whereNull('agency_id');

            return $institutionQuery->first();
        }

        return null;
    }

    private function findActiveDay(string $scopeType, ?int $agencyId): ?AccountingDay
    {
        $query = $this->scopedQuery($scopeType, $agencyId);
        $query->getQuery()
            ->whereIn('status', [
                AccountingDay::STATUS_OPEN,
                AccountingDay::STATUS_REOPENED,
                AccountingDay::STATUS_CLOSING,
            ])
            ->orderByDesc('business_date');

        return $query->first();
    }

    private function findLatestDay(string $scopeType, ?int $agencyId): ?AccountingDay
    {
        $query = $this->scopedQuery($scopeType, $agencyId);
        $query->getQuery()->orderByDesc('business_date');

        return $query->first();
    }

    private function findDayByDate(string $scopeType, ?int $agencyId, string $businessDate): ?AccountingDay
    {
        return $this->scopedQuery($scopeType, $agencyId)->where('business_date', $businessDate)->first();
    }

    /**
     * @return Builder<AccountingDay>
     */
    private function scopedQuery(string $scopeType, ?int $agencyId): Builder
    {
        $query = AccountingDay::query()->where('scope_type', $scopeType);
        $scopeType === AccountingDay::SCOPE_AGENCY
            ? $query->where('agency_id', $agencyId)
            : $query->getQuery()->whereNull('agency_id');

        return $query;
    }
}
