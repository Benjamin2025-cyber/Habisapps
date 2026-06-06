<?php

declare(strict_types=1);

namespace Tests\Unit\Application\BatchRuns;

use App\Application\BatchRuns\BatchProcedureRegistry;
use App\Application\BatchRuns\ExecuteLoanServicingHooksBatch;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class BatchProcedureRegistryTest extends TestCase
{
    public function test_registry_catalog_includes_every_dispatchable_batch_procedure_code(): void
    {
        $codes = BatchProcedureRegistry::codes();

        $expected = [
            // Loan arrears handler.
            'loan_arrears_assessment',
            'loan_monthly_arrears_penalty',
            // Cash close handler.
            'cash_close_verification',
            'cash_daily_close',
            'agency_cash_close',
            // Accounting close handler.
            'accounting_close_verification',
            'accounting_daily_close',
            'journal_close_verification',
            // Loan servicing hook handler, including aliases.
            'loan_portfolio_report_hook',
            'credit_portfolio_report_hook',
            'portfolio_report_generation',
            'loan_servicing_notification_hook',
            'loan_notifications_hook',
            'credit_notification_hook',
        ];

        foreach ($expected as $code) {
            self::assertContains($code, $codes, "Registry is missing executable code {$code}.");
        }
    }

    public function test_registry_catalog_items_expose_required_frontend_metadata(): void
    {
        foreach (BatchProcedureRegistry::catalog() as $item) {
            self::assertArrayHasKey('code', $item);
            self::assertArrayHasKey('label', $item);
            self::assertArrayHasKey('description', $item);
            self::assertArrayHasKey('group', $item);
            self::assertArrayHasKey('default_schedule_type', $item);
            self::assertArrayHasKey('prerequisite_codes', $item);

            self::assertNotSame('', $item['code']);
            self::assertNotSame('', $item['label']);
            self::assertNotSame('', $item['description']);
            self::assertNotSame('', $item['group']);
            self::assertNotSame('', $item['default_schedule_type']);
        }
    }

    public function test_every_registry_code_maps_to_a_known_dispatch_handler(): void
    {
        $knownHandlers = [
            BatchProcedureRegistry::HANDLER_LOAN_ARREARS,
            BatchProcedureRegistry::HANDLER_CASH_CLOSE,
            BatchProcedureRegistry::HANDLER_ACCOUNTING_CLOSE,
            BatchProcedureRegistry::HANDLER_LOAN_SERVICING_HOOK,
        ];

        foreach (BatchProcedureRegistry::codes() as $code) {
            self::assertContains(
                BatchProcedureRegistry::handlerFor($code),
                $knownHandlers,
                "Code {$code} routes to an unknown handler."
            );
        }
    }

    public function test_loan_servicing_hook_runner_supports_exactly_the_registry_hook_codes(): void
    {
        $runner = new ExecuteLoanServicingHooksBatch;

        foreach (BatchProcedureRegistry::codes() as $code) {
            $isHookCode = BatchProcedureRegistry::handlerFor($code) === BatchProcedureRegistry::HANDLER_LOAN_SERVICING_HOOK;

            self::assertSame(
                $isHookCode,
                $runner->supports($code),
                "Loan servicing hook support for {$code} drifted from the registry."
            );
        }

        // A code outside the registry is never supported.
        self::assertFalse($runner->supports('totally_unregistered_code'));
    }

    public function test_loan_servicing_hook_runner_has_no_private_code_lists_to_keep_in_sync(): void
    {
        // Proves the runner no longer carries a second hardcoded, frontend-facing
        // code list: adding a handler code only touches the registry.
        $reflection = new ReflectionClass(ExecuteLoanServicingHooksBatch::class);

        $arrayConstants = array_filter(
            $reflection->getConstants(),
            static fn (mixed $value): bool => is_array($value),
        );

        self::assertSame(
            [],
            $arrayConstants,
            'Loan servicing hook runner reintroduced a hardcoded code list outside the registry: '.implode(', ', array_keys($arrayConstants)),
        );
    }

    public function test_executability_check_normalizes_hyphen_and_case_variants(): void
    {
        self::assertTrue(BatchProcedureRegistry::isExecutable('Cash-Daily-Close'));
        self::assertTrue(BatchProcedureRegistry::isExecutable('LOAN_ARREARS_ASSESSMENT'));
        self::assertFalse(BatchProcedureRegistry::isExecutable('unregistered_close'));
    }
}
