<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\ReportDefinition;
use Illuminate\Database\Seeder;

final class StandardReportDefinitionSeeder extends Seeder
{
    /**
     * @return array<int, array<string, mixed>>
     */
    private static function seedData(): array
    {
        return [
            [
                'code' => 'trial_balance',
                'name' => 'Trial Balance',
                'report_type' => ReportDefinition::TYPE_TRIAL_BALANCE,
                'module' => 'accounting',
                'description' => 'Standard trial balance report showing ledger account balances for a given agency and currency.',
                'supported_parameters' => ['agency', 'currency'],
                'requires_agency' => true,
                'requires_currency' => true,
                'requires_period' => false,
            ],
            [
                'code' => 'general_ledger',
                'name' => 'General Ledger',
                'report_type' => ReportDefinition::TYPE_GENERAL_LEDGER,
                'module' => 'accounting',
                'description' => 'Standard general ledger report with period-filtered journal movements for a given agency and currency.',
                'supported_parameters' => ['agency', 'currency', 'period'],
                'requires_agency' => true,
                'requires_currency' => true,
                'requires_period' => true,
            ],
            [
                'code' => 'emf_trial_balance',
                'name' => 'EMF Trial Balance',
                'report_type' => ReportDefinition::TYPE_EMF_TRIAL_BALANCE,
                'module' => 'regulatory',
                'description' => 'EMF/COBAC regulatory trial balance mapped through EMF account classifications.',
                'supported_parameters' => ['agency', 'currency'],
                'requires_agency' => true,
                'requires_currency' => true,
                'requires_period' => false,
            ],
            [
                'code' => 'credit_portfolio_outstanding',
                'name' => 'Credit Portfolio Outstanding',
                'report_type' => ReportDefinition::TYPE_CREDIT_PORTFOLIO_OUTSTANDING,
                'module' => 'credit',
                'description' => 'Credit portfolio outstanding report showing loan exposure by agency and currency.',
                'supported_parameters' => ['agency', 'currency', 'period'],
                'requires_agency' => true,
                'requires_currency' => true,
                'requires_period' => true,
            ],
            [
                'code' => 'credit_par_delinquency',
                'name' => 'Credit PAR / Delinquency',
                'report_type' => ReportDefinition::TYPE_CREDIT_PAR_DELINQUENCY,
                'module' => 'credit',
                'description' => 'Credit portfolio at risk (PAR) and delinquency analysis by agency and currency.',
                'supported_parameters' => ['agency', 'currency'],
                'requires_agency' => true,
                'requires_currency' => true,
                'requires_period' => false,
            ],
            [
                'code' => 'credit_collection_performance',
                'name' => 'Credit Collection Performance',
                'report_type' => ReportDefinition::TYPE_CREDIT_COLLECTION_PERFORMANCE,
                'module' => 'credit',
                'description' => 'Credit collection performance report comparing expected vs actual collections.',
                'supported_parameters' => ['agency', 'currency', 'period'],
                'requires_agency' => true,
                'requires_currency' => true,
                'requires_period' => true,
            ],
            [
                'code' => 'credit_guarantee_release',
                'name' => 'Mainlevée de garantie',
                'report_type' => ReportDefinition::TYPE_CREDIT_GUARANTEE_RELEASE,
                'module' => 'credit',
                'description' => 'Attestation de mainlevée for a released collateral or guarantee obligation tied to a closed loan.',
                'supported_parameters' => ['agency', 'loan_public_id', 'collateral_public_id', 'guarantee_obligation_public_id'],
                'requires_agency' => true,
                'requires_currency' => false,
                'requires_period' => false,
            ],
        ];
    }

    public function run(): void
    {
        foreach (self::seedData() as $data) {
            $definition = [
                'description' => $data['description'],
                'supported_parameters' => $data['supported_parameters'],
                'requires_agency' => $data['requires_agency'],
                'requires_currency' => $data['requires_currency'],
                'requires_period' => $data['requires_period'],
            ];

            ReportDefinition::updateOrCreate(
                ['code' => $data['code'], 'version' => 1],
                [
                    'name' => $data['name'],
                    'report_type' => $data['report_type'],
                    'module' => $data['module'],
                    'status' => ReportDefinition::STATUS_ACTIVE,
                    'definition' => $definition,
                ],
            );
        }
    }
}
