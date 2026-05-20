<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Insurance;

use PHPUnit\Framework\TestCase;

final class InsuranceControllerArchitectureTest extends TestCase
{
    public function test_route_controller_stays_as_transport_glue(): void
    {
        $path = dirname(__DIR__, 4).'/app/Http/Controllers/Api/V1/InsuranceController.php';
        $source = (string) file_get_contents($path);
        $lines = file($path);
        $lineCount = is_array($lines) ? count($lines) : 0;

        self::assertLessThanOrEqual(500, $lineCount);
        self::assertStringNotContainsString('DB::transaction', $source);
        self::assertStringNotContainsString('JournalEntry', $source);
        self::assertStringNotContainsString('JournalLine', $source);
        self::assertStringNotContainsString('TellerTransaction', $source);
        self::assertStringNotContainsString('rowString', $source);
        self::assertStringNotContainsString('rowInt', $source);
        self::assertStringNotContainsString('jsonOrNull', $source);
    }

    public function test_workflow_adapter_stays_as_delegation_only(): void
    {
        $path = dirname(__DIR__, 4).'/app/Application/Insurance/InsuranceWorkflowControllerAdapter.php';
        $source = (string) file_get_contents($path);
        $lines = file($path);
        $lineCount = is_array($lines) ? count($lines) : 0;

        self::assertLessThanOrEqual(400, $lineCount);
        self::assertStringNotContainsString('DB::transaction', $source);
        self::assertStringNotContainsString('Validator::make', $source);
        self::assertStringNotContainsString('JournalEntry', $source);
        self::assertStringNotContainsString('JournalLine', $source);
        self::assertStringNotContainsString('TellerTransaction', $source);
        self::assertStringNotContainsString('private function rowString', $source);
        self::assertStringNotContainsString('private function rowInt', $source);
        self::assertStringNotContainsString('private function jsonOrNull', $source);
    }
}
