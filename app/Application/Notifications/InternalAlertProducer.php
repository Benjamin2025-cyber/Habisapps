<?php

declare(strict_types=1);

namespace App\Application\Notifications;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Spatie\Permission\Models\Role;
use Throwable;

final class InternalAlertProducer
{
    public const string TEMPLATE_REPORT_DEADLINE = 'report_deadline_alert';

    public const string TEMPLATE_REPORT_FAILED = 'report_failed_alert';

    public function __construct(
        private readonly NotificationOutbox $outbox,
    ) {}

    public function produceReportDeadlineAlerts(CarbonInterface $businessDate): int
    {
        $definitions = DB::table('report_definitions')
            ->where('status', 'active')
            ->whereNotNull('definition')
            ->get();

        $created = 0;
        foreach ($definitions as $definition) {
            $schedule = $this->scheduleFor($definition);
            if ($schedule === null) {
                continue;
            }

            $nextDeadline = $this->nextDeadline($schedule, $businessDate);
            if ($nextDeadline === null) {
                continue;
            }

            $leadDays = isset($schedule['alert_lead_days']) && is_numeric($schedule['alert_lead_days'])
                ? max(0, (int) $schedule['alert_lead_days'])
                : 3;
            $daysUntil = (int) $businessDate->copy()->startOfDay()->diffInDays($nextDeadline->copy()->startOfDay(), false);
            if ($daysUntil < 0 || $daysUntil > $leadDays) {
                continue;
            }

            $roles = $this->targetRoles($schedule, 'target_roles');
            if ($roles === []) {
                continue;
            }

            foreach ($roles as $roleName) {
                $role = Role::query()->where('name', $roleName)->first();
                if (! $role instanceof Role) {
                    continue;
                }

                $idempotencyKey = sprintf(
                    'report_deadline:%s:%s:%s',
                    $this->rowString($definition, 'code'),
                    $nextDeadline->toDateString(),
                    $roleName,
                );

                $enqueued = $this->safeEnqueue(
                    templateCode: self::TEMPLATE_REPORT_DEADLINE,
                    category: 'report_deadline',
                    destination: $roleName,
                    idempotencyKey: $idempotencyKey,
                    variables: [
                        'report_name' => $this->rowString($definition, 'name'),
                        'report_code' => $this->rowString($definition, 'code'),
                        'deadline' => $nextDeadline->toDateString(),
                        'days_until' => (string) $daysUntil,
                    ],
                    recipientType: Role::class,
                    recipientId: (int) $role->id,
                    metadata: [
                        'role_name' => $roleName,
                        'report_definition_code' => $this->rowString($definition, 'code'),
                        'deadline' => $nextDeadline->toDateString(),
                        'escalated' => false,
                    ],
                );
                if ($enqueued) {
                    $created++;
                }
            }
        }

        return $created;
    }

    public function produceFailedReportAlerts(CarbonInterface $businessDate): int
    {
        $rows = DB::table('report_runs as run')
            ->join('report_definitions as def', 'def.id', '=', 'run.report_definition_id')
            ->whereIn('run.status', ['failed', 'error'])
            ->select([
                'run.public_id as run_public_id',
                'run.status as run_status',
                'def.code as definition_code',
                'def.name as definition_name',
                'def.definition as definition_json',
            ])
            ->orderBy('run.id')
            ->get();

        $created = 0;
        foreach ($rows as $row) {
            $schedule = $this->scheduleFromJson($this->rowNullableString($row, 'definition_json'));
            $roles = $this->targetRoles($schedule ?? [], 'failure_target_roles');
            if ($roles === []) {
                $roles = $this->targetRoles($schedule ?? [], 'target_roles');
            }
            if ($roles === []) {
                continue;
            }

            foreach ($roles as $roleName) {
                $role = Role::query()->where('name', $roleName)->first();
                if (! $role instanceof Role) {
                    continue;
                }

                $idempotencyKey = sprintf(
                    'report_failed:%s:%s',
                    $this->rowString($row, 'run_public_id'),
                    $roleName,
                );

                $enqueued = $this->safeEnqueue(
                    templateCode: self::TEMPLATE_REPORT_FAILED,
                    category: 'report_failed',
                    destination: $roleName,
                    idempotencyKey: $idempotencyKey,
                    variables: [
                        'report_name' => $this->rowString($row, 'definition_name'),
                        'report_code' => $this->rowString($row, 'definition_code'),
                        'run_status' => $this->rowString($row, 'run_status'),
                        'run_public_id' => $this->rowString($row, 'run_public_id'),
                    ],
                    recipientType: Role::class,
                    recipientId: (int) $role->id,
                    metadata: [
                        'role_name' => $roleName,
                        'report_run_public_id' => $this->rowString($row, 'run_public_id'),
                        'business_date' => $businessDate->toDateString(),
                        'escalated' => true,
                    ],
                );
                if ($enqueued) {
                    $created++;
                }
            }
        }

        return $created;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function scheduleFor(object $definition): ?array
    {
        return $this->scheduleFromJson($this->rowNullableString($definition, 'definition'));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function scheduleFromJson(?string $json): ?array
    {
        if ($json === null || $json === '') {
            return null;
        }
        $decoded = json_decode($json, true);
        if (! is_array($decoded)) {
            return null;
        }
        $schedule = $decoded['schedule'] ?? null;
        if (! is_array($schedule)) {
            return null;
        }

        $result = [];
        foreach ($schedule as $key => $value) {
            if (is_string($key)) {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $schedule
     */
    private function nextDeadline(array $schedule, CarbonInterface $businessDate): ?CarbonInterface
    {
        $frequency = isset($schedule['frequency']) && is_string($schedule['frequency'])
            ? $schedule['frequency']
            : null;
        $day = isset($schedule['due_day_of_month']) && is_numeric($schedule['due_day_of_month'])
            ? max(1, min(28, (int) $schedule['due_day_of_month']))
            : 10;

        return match ($frequency) {
            'monthly' => $this->nextMonthlyDeadline($businessDate, $day),
            'quarterly' => $this->nextQuarterlyDeadline($businessDate, $day),
            'annual' => $this->nextAnnualDeadline($businessDate, $day),
            default => null,
        };
    }

    private function nextMonthlyDeadline(CarbonInterface $businessDate, int $day): CarbonInterface
    {
        $candidate = $this->makeDate($businessDate->year, $businessDate->month, $day);
        if ($candidate->lessThan($businessDate->copy()->startOfDay())) {
            $candidate = $candidate->copy()->addMonth();
        }

        return $candidate;
    }

    private function nextQuarterlyDeadline(CarbonInterface $businessDate, int $day): CarbonInterface
    {
        $quarterStartMonths = [1, 4, 7, 10];
        foreach ($quarterStartMonths as $month) {
            $candidate = $this->makeDate($businessDate->year, $month, $day);
            if ($candidate->greaterThanOrEqualTo($businessDate->copy()->startOfDay())) {
                return $candidate;
            }
        }

        return $this->makeDate($businessDate->year + 1, $quarterStartMonths[0], $day);
    }

    private function nextAnnualDeadline(CarbonInterface $businessDate, int $day): CarbonInterface
    {
        $candidate = $this->makeDate($businessDate->year, 1, $day);
        if ($candidate->lessThan($businessDate->copy()->startOfDay())) {
            $candidate = $candidate->copy()->addYear();
        }

        return $candidate;
    }

    private function makeDate(int $year, int $month, int $day): Carbon
    {
        return Carbon::createFromDate($year, $month, $day)->startOfDay();
    }

    /**
     * @param  array<string, mixed>  $schedule
     * @return list<string>
     */
    private function targetRoles(array $schedule, string $key): array
    {
        $roles = $schedule[$key] ?? null;
        if (! is_array($roles)) {
            return [];
        }

        return array_values(array_filter($roles, 'is_string'));
    }

    /**
     * @param  array<string, scalar|null>  $variables
     * @param  array<string, mixed>  $metadata
     */
    private function safeEnqueue(
        string $templateCode,
        string $category,
        string $destination,
        string $idempotencyKey,
        array $variables,
        string $recipientType,
        int $recipientId,
        array $metadata,
    ): bool {
        $existsBefore = DB::table('notification_deliveries')
            ->where('idempotency_key', $idempotencyKey)
            ->exists();
        if ($existsBefore) {
            return false;
        }

        try {
            $this->outbox->enqueue(
                templateCode: $templateCode,
                category: $category,
                channel: 'in_app',
                destination: $destination,
                idempotencyKey: $idempotencyKey,
                variables: $variables,
                recipientType: $recipientType,
                recipientId: $recipientId,
                metadata: $metadata,
            );

            return true;
        } catch (InvalidArgumentException) {
            return false;
        } catch (Throwable) {
            return false;
        }
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
}
