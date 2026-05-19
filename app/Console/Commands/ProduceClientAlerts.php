<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Application\Notifications\ClientAlertProducer;
use Carbon\Carbon;
use Illuminate\Console\Command;

final class ProduceClientAlerts extends Command
{
    protected $signature = 'notifications:produce-client-alerts {--type=all : One of all|loan_due|loan_overdue|insurance_premium_due|claim_decision} {--business-date= : Override the business date (YYYY-MM-DD), defaults to today}';

    protected $description = 'Produce client-facing notification outbox rows for loan and insurance events.';

    public function handle(ClientAlertProducer $producer): int
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
            'loan_due' => 0,
            'loan_overdue' => 0,
            'insurance_premium_due' => 0,
            'insurance_claim_decision' => 0,
        ];

        if ($type === 'all' || $type === 'loan_due') {
            $summary['loan_due'] = $producer->produceLoanDueAlerts($businessDate);
        }
        if ($type === 'all' || $type === 'loan_overdue') {
            $summary['loan_overdue'] = $producer->produceLoanOverdueAlerts($businessDate);
        }
        if ($type === 'all' || $type === 'insurance_premium_due') {
            $summary['insurance_premium_due'] = $producer->produceInsurancePremiumDueAlerts($businessDate);
        }
        if ($type === 'all' || $type === 'claim_decision') {
            $summary['insurance_claim_decision'] = $producer->produceInsuranceClaimDecisionAlerts($businessDate);
        }

        $this->table(['Category', 'New outbox rows'], array_map(
            static fn (string $category, int $count): array => [$category, (string) $count],
            array_keys($summary),
            array_values($summary),
        ));

        return self::SUCCESS;
    }
}
