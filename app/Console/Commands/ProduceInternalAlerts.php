<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Application\Notifications\InternalAlertProducer;
use Carbon\Carbon;
use Illuminate\Console\Command;

final class ProduceInternalAlerts extends Command
{
    protected $signature = 'notifications:produce-internal-alerts {--type=all : One of all|report_deadline|report_failed} {--business-date= : Override the business date (YYYY-MM-DD), defaults to today}';

    protected $description = 'Produce internal staff notification outbox rows for report deadlines and failed report runs.';

    public function handle(InternalAlertProducer $producer): int
    {
        $businessDateOption = $this->option('business-date');
        $businessDate = is_string($businessDateOption) && $businessDateOption !== ''
            ? Carbon::parse($businessDateOption)
            : Carbon::today();

        $type = $this->option('type');
        if (! is_string($type) || $type === '') {
            $type = 'all';
        }

        $summary = [
            'report_deadline' => 0,
            'report_failed' => 0,
        ];

        if ($type === 'all' || $type === 'report_deadline') {
            $summary['report_deadline'] = $producer->produceReportDeadlineAlerts($businessDate);
        }
        if ($type === 'all' || $type === 'report_failed') {
            $summary['report_failed'] = $producer->produceFailedReportAlerts($businessDate);
        }

        $this->table(['Category', 'New outbox rows'], array_map(
            static fn (string $category, int $count): array => [$category, (string) $count],
            array_keys($summary),
            array_values($summary),
        ));

        return self::SUCCESS;
    }
}
