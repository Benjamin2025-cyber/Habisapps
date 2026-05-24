<?php

declare(strict_types=1);

namespace Tests\Unit\Application;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ControllerRefactorArchitectureTest extends TestCase
{
    /**
     * @return array<string, array{0:string}>
     */
    public static function refactoredControllersProvider(): array
    {
        return [
            'AccountingBalanceController' => [dirname(__DIR__, 3).'/app/Http/Controllers/Api/V1/AccountingBalanceController.php'],
            'AgencyController' => [dirname(__DIR__, 3).'/app/Http/Controllers/Api/V1/AgencyController.php'],
            'BatchRunController' => [dirname(__DIR__, 3).'/app/Http/Controllers/Api/V1/BatchRunController.php'],
            'ClientController' => [dirname(__DIR__, 3).'/app/Http/Controllers/Api/V1/ClientController.php'],
            'CurrencyExchangeController' => [dirname(__DIR__, 3).'/app/Http/Controllers/Api/V1/CurrencyExchangeController.php'],
            'CustomerAccountController' => [dirname(__DIR__, 3).'/app/Http/Controllers/Api/V1/CustomerAccountController.php'],
            'DashboardController' => [dirname(__DIR__, 3).'/app/Http/Controllers/Api/V1/DashboardController.php'],
            'DelinquencyTrackingController' => [dirname(__DIR__, 3).'/app/Http/Controllers/Api/V1/DelinquencyTrackingController.php'],
            'HrController' => [dirname(__DIR__, 3).'/app/Http/Controllers/Api/V1/HrController.php'],
            'HrPayrollController' => [dirname(__DIR__, 3).'/app/Http/Controllers/Api/V1/HrPayrollController.php'],
            'IslamicFinanceController' => [dirname(__DIR__, 3).'/app/Http/Controllers/Api/V1/IslamicFinanceController.php'],
            'IslamicStandardController' => [dirname(__DIR__, 3).'/app/Http/Controllers/Api/V1/IslamicStandardController.php'],
            'IslamicRegulatorySignoffController' => [dirname(__DIR__, 3).'/app/Http/Controllers/Api/V1/IslamicRegulatorySignoffController.php'],
            'IslamicShariaAuthorityController' => [dirname(__DIR__, 3).'/app/Http/Controllers/Api/V1/IslamicShariaAuthorityController.php'],
            'JournalEntryController' => [dirname(__DIR__, 3).'/app/Http/Controllers/Api/V1/JournalEntryController.php'],
            'LoanController' => [dirname(__DIR__, 3).'/app/Http/Controllers/Api/V1/LoanController.php'],
            'LoanRecoveryController' => [dirname(__DIR__, 3).'/app/Http/Controllers/Api/V1/LoanRecoveryController.php'],
            'LoanTransferController' => [dirname(__DIR__, 3).'/app/Http/Controllers/Api/V1/LoanTransferController.php'],
            'RegulatoryReportingController' => [dirname(__DIR__, 3).'/app/Http/Controllers/Api/V1/RegulatoryReportingController.php'],
            'StaffUserController' => [dirname(__DIR__, 3).'/app/Http/Controllers/Api/V1/StaffUserController.php'],
            'TellerSessionController' => [dirname(__DIR__, 3).'/app/Http/Controllers/Api/V1/TellerSessionController.php'],
            'TellerTransactionController' => [dirname(__DIR__, 3).'/app/Http/Controllers/Api/V1/TellerTransactionController.php'],
        ];
    }

    #[DataProvider('refactoredControllersProvider')]
    public function test_refactored_controllers_stay_transport_focused(string $path): void
    {
        $source = (string) file_get_contents($path);
        $lines = file($path);
        $lineCount = is_array($lines) ? count($lines) : 0;

        self::assertLessThanOrEqual(160, $lineCount, $path.' grew too large for a transport adapter.');
        self::assertStringNotContainsString('DB::transaction', $source, $path.' contains transaction orchestration.');
        self::assertStringNotContainsString('Validator::make', $source, $path.' contains inline validation orchestration.');
        self::assertStringNotContainsString('rowString', $source, $path.' contains row parsing helpers.');
        self::assertStringNotContainsString('rowInt', $source, $path.' contains row parsing helpers.');
        self::assertMatchesRegularExpression('/private readonly .*(Workflow|ControllerAdapter) \$/', $source, $path.' is missing workflow/adapter delegation dependency.');
    }
}
