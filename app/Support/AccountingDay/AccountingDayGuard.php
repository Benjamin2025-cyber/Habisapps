<?php

declare(strict_types=1);

namespace App\Support\AccountingDay;

use App\Models\AccountingDay;
use App\Models\User;
use App\Support\Security\SecurityAudit;
use App\Support\Staff\StaffAgencyScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * The single authoritative gate every registration workflow consults.
 *
 * Responsibilities:
 *  - Resolve the accounting day that governs the actor's scope.
 *  - Return the authoritative business date for event dating.
 *  - Reject registration writes when no day is open, the day is closing/closed,
 *    or a caller-supplied business date diverges from the open day.
 *
 * It fails closed: any ambiguity (no resolvable scope, no active day) blocks
 * the write rather than allowing an unattributed registration.
 */
final class AccountingDayGuard
{
    public function __construct(
        private readonly StaffAgencyScope $staffAgencyScope,
        private readonly SecurityAudit $securityAudit,
    ) {}

    /**
     * Resolve the active accounting day governing the actor's scope, or throw.
     *
     * "Active" means open, reopened, or closing. A closing day still resolves
     * here (so the lifecycle/consultation layer can inspect it) but does not
     * permit registration — use assertCanRegister() for write paths.
     */
    public function currentOpenForActor(User $actor, ?int $agencyId = null): AccountingDay
    {
        [$scopeType, $resolvedAgencyId] = $this->resolveScope($actor, $agencyId);

        $day = $this->findActiveDay($scopeType, $resolvedAgencyId);
        if ($day instanceof AccountingDay) {
            return $day;
        }

        $closed = $this->findLatestClosedDay($scopeType, $resolvedAgencyId);
        if ($closed instanceof AccountingDay) {
            throw AccountingDayException::closed($closed);
        }

        if ($this->autoOpenOnMissingEnabled()) {
            return $this->autoOpenDay($scopeType, $resolvedAgencyId, null, $actor);
        }

        throw AccountingDayException::missing($resolvedAgencyId);
    }

    /**
     * Assert the actor may register an operation now and return the governing day.
     *
     * @throws AccountingDayException
     */
    public function assertCanRegister(User $actor, string $operation, ?int $agencyId = null, ?Request $request = null): AccountingDay
    {
        [$scopeType, $resolvedAgencyId] = $this->resolveScope($actor, $agencyId);

        $day = $this->findActiveDay($scopeType, $resolvedAgencyId);

        if (! $day instanceof AccountingDay) {
            $closed = $this->findLatestClosedDay($scopeType, $resolvedAgencyId);
            if (! $closed instanceof AccountingDay && $this->autoOpenOnMissingEnabled()) {
                return $this->autoOpenDay($scopeType, $resolvedAgencyId, $request, $actor);
            }

            $exception = $closed instanceof AccountingDay ? AccountingDayException::closed($closed) : AccountingDayException::missing($resolvedAgencyId);
            $this->recordBlocked($actor, $operation, $scopeType, $resolvedAgencyId, $exception, $request);

            throw $exception;
        }

        if ($day->isClosing()) {
            $exception = AccountingDayException::closing($day);
            $this->recordBlocked($actor, $operation, $scopeType, $resolvedAgencyId, $exception, $request);

            throw $exception;
        }

        if (! $day->allowsRegistration()) {
            $exception = AccountingDayException::closed($day);
            $this->recordBlocked($actor, $operation, $scopeType, $resolvedAgencyId, $exception, $request);

            throw $exception;
        }

        return $day;
    }

    /**
     * Assert registration is allowed and return the authoritative business date.
     *
     * When a requested date is supplied it must equal the open accounting day;
     * otherwise a mismatch is raised. This blocks callers from posting to an
     * arbitrary calendar date.
     *
     * @throws AccountingDayException
     */
    public function resolveBusinessDate(User $actor, string $operation, ?int $agencyId = null, ?string $requestedDate = null, ?Request $request = null): string
    {
        $day = $this->resolveAccountingDay($actor, $operation, $agencyId, $requestedDate, $request);
        $businessDate = $day->business_date?->toDateString();

        if ($businessDate === null) {
            // Fail closed: an active day must always carry a business date.
            throw AccountingDayException::missing($agencyId);
        }

        return $businessDate;
    }

    /**
     * Resolve the accounting day that should be linked to a new registration.
     *
     * This differs from assertCanRegister() when test/bootstrap mode reconciles
     * a requested historical date by opening a replacement accounting day.
     *
     * @throws AccountingDayException
     */
    public function resolveAccountingDay(User $actor, string $operation, ?int $agencyId = null, ?string $requestedDate = null, ?Request $request = null): AccountingDay
    {
        $day = $this->assertCanRegister($actor, $operation, $agencyId, $request);
        $businessDate = $day->business_date?->toDateString();

        if ($businessDate === null) {
            throw AccountingDayException::missing($agencyId);
        }

        if ($requestedDate !== null && $requestedDate !== '' && $requestedDate !== $businessDate) {
            if ($this->autoOpenOnMissingEnabled()) {
                return $this->reconcileAutoManagedDay($day, $requestedDate, $actor);
            }

            $exception = AccountingDayException::mismatch($day, $requestedDate);
            $this->recordBlocked($actor, $operation, $day->scope_type, $day->agency_id, $exception, $request);

            throw $exception;
        }

        return $day;
    }

    /**
     * Assert that an already-linked accounting day still allows registration.
     *
     * Used by workflows whose records inherit a day at creation (e.g. cash
     * transactions inheriting their teller session's day): the inherited day,
     * not the actor's current open day, governs whether the write is allowed.
     *
     * @throws AccountingDayException
     */
    public function assertDayAllowsRegistration(AccountingDay $day, ?User $actor = null, string $operation = 'registration', ?Request $request = null): AccountingDay
    {
        if ($day->isClosing()) {
            if ($actor instanceof User) {
                $this->recordBlocked($actor, $operation, $day->scope_type, $day->agency_id, AccountingDayException::closing($day), $request);
            }

            throw AccountingDayException::closing($day);
        }

        if (! $day->allowsRegistration()) {
            if ($actor instanceof User) {
                $this->recordBlocked($actor, $operation, $day->scope_type, $day->agency_id, AccountingDayException::closed($day), $request);
            }

            throw AccountingDayException::closed($day);
        }

        return $day;
    }

    /**
     * Resolve the (scope_type, agency_id) tuple governing this actor/operation.
     *
     * @return array{0: string, 1: int|null}
     */
    public function resolveScope(User $actor, ?int $agencyId = null): array
    {
        if ($agencyId !== null) {
            return [AccountingDay::SCOPE_AGENCY, $agencyId];
        }

        $currentAgencyId = $this->staffAgencyScope->currentAgencyId($actor);
        if ($currentAgencyId !== null) {
            return [AccountingDay::SCOPE_AGENCY, $currentAgencyId];
        }

        return [AccountingDay::SCOPE_INSTITUTION, null];
    }

    private function findActiveDay(string $scopeType, ?int $agencyId): ?AccountingDay
    {
        $query = AccountingDay::query()
            ->where('scope_type', $scopeType)
            ->whereIn('status', [
                AccountingDay::STATUS_OPEN,
                AccountingDay::STATUS_REOPENED,
                AccountingDay::STATUS_CLOSING,
            ]);

        $this->applyScope($query, $scopeType, $agencyId);

        // A partial unique index guarantees at most one active day per scope.
        return $query->orderByDesc('business_date')->first();
    }

    private function findLatestClosedDay(string $scopeType, ?int $agencyId): ?AccountingDay
    {
        $query = AccountingDay::query()
            ->where('scope_type', $scopeType)
            ->where('status', AccountingDay::STATUS_CLOSED);

        $this->applyScope($query, $scopeType, $agencyId);

        return $query->orderByDesc('business_date')->first();
    }

    /**
     * @param  Builder<AccountingDay>  $query
     */
    private function applyScope(Builder $query, string $scopeType, ?int $agencyId): void
    {
        if ($scopeType === AccountingDay::SCOPE_AGENCY) {
            $query->where('agency_id', $agencyId);
        } else {
            $query->whereNull('agency_id');
        }
    }

    private function recordBlocked(User $actor, string $operation, string $scopeType, ?int $agencyId, AccountingDayException $exception, ?Request $request): void
    {
        $this->securityAudit->record('accounting_day.registration_blocked', actor: $actor, properties: [
            'operation' => $operation,
            'scope_type' => $scopeType,
            'agency_id_scope' => $agencyId,
            'reason_code' => $exception->errorCode,
        ], request: $request);
    }

    private function autoOpenOnMissingEnabled(): bool
    {
        return (bool) config('security.accounting_day.auto_open_on_missing', false);
    }

    private function autoOpenDay(string $scopeType, ?int $agencyId, ?Request $request, User $actor): AccountingDay
    {
        $requestedDate = null;
        if ($request instanceof Request) {
            foreach (['business_date', 'transaction_date', 'value_date', 'date'] as $candidate) {
                $value = $request->input($candidate);
                if (is_string($value) && $value !== '') {
                    $requestedDate = $value;
                    break;
                }
            }
        }

        $businessDate = $requestedDate ?? now()->toDateString();
        $existing = AccountingDay::query()
            ->where('scope_type', $scopeType)
            ->when(
                $scopeType === AccountingDay::SCOPE_AGENCY,
                fn ($query) => $query->where('agency_id', $agencyId),
                fn ($query) => $query->whereNull('agency_id'),
            )
            ->whereDate('business_date', $businessDate)
            ->whereIn('status', [AccountingDay::STATUS_OPEN, AccountingDay::STATUS_REOPENED, AccountingDay::STATUS_CLOSING])
            ->latest('id')
            ->first();

        if ($existing instanceof AccountingDay) {
            return $existing;
        }

        return AccountingDay::query()->create([
            'public_id' => (string) Str::ulid(),
            'scope_type' => $scopeType,
            'agency_id' => $scopeType === AccountingDay::SCOPE_AGENCY ? $agencyId : null,
            'business_date' => $businessDate,
            'calendar_opened_at' => now(),
            'status' => AccountingDay::STATUS_OPEN,
            'is_holiday' => false,
            'origin' => AccountingDay::ORIGIN_MIGRATION,
            'opened_by_user_id' => $actor->id,
            'write_lock_version' => 0,
        ]);
    }

    private function reconcileAutoManagedDay(AccountingDay $day, string $requestedDate, User $actor): AccountingDay
    {
        $target = AccountingDay::query()
            ->where('scope_type', $day->scope_type)
            ->when(
                $day->scope_type === AccountingDay::SCOPE_AGENCY,
                fn ($query) => $query->where('agency_id', $day->agency_id),
                fn ($query) => $query->whereNull('agency_id'),
            )
            ->whereDate('business_date', $requestedDate)
            ->whereIn('status', [AccountingDay::STATUS_OPEN, AccountingDay::STATUS_REOPENED])
            ->latest('id')
            ->first();

        if ($target instanceof AccountingDay) {
            return $target;
        }

        $day->forceFill([
            'status' => AccountingDay::STATUS_CLOSED,
            'calendar_closed_at' => now(),
            'close_summary_payload' => json_encode([
                'auto_closed_for_requested_date' => $requestedDate,
                'auto_actor_user_id' => $actor->id,
            ]),
            'write_lock_version' => $day->write_lock_version + 1,
        ])->save();

        return AccountingDay::query()->create([
            'public_id' => (string) Str::ulid(),
            'scope_type' => $day->scope_type,
            'agency_id' => $day->scope_type === AccountingDay::SCOPE_AGENCY ? $day->agency_id : null,
            'business_date' => $requestedDate,
            'calendar_opened_at' => now(),
            'status' => AccountingDay::STATUS_OPEN,
            'is_holiday' => false,
            'origin' => AccountingDay::ORIGIN_MIGRATION,
            'opened_by_user_id' => $actor->id,
            'write_lock_version' => 0,
        ]);
    }
}
