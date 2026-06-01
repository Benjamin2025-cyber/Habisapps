<?php

declare(strict_types=1);

namespace Tests\Unit\Application;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class AccountingDayDateArchitectureTest extends TestCase
{
    public function test_financial_accounting_dates_do_not_default_to_wall_clock_today(): void
    {
        $violations = [];

        foreach ($this->applicationPhpFiles() as $file) {
            $contents = file($file->getPathname(), FILE_IGNORE_NEW_LINES);
            self::assertIsArray($contents);

            foreach ($contents as $index => $line) {
                foreach ($this->forbiddenWallClockAccountingDatePatterns() as $pattern => $reason) {
                    if (preg_match($pattern, $line) === 1) {
                        $violations[] = sprintf('%s:%d %s', $file->getPathname(), $index + 1, $reason);
                    }
                }
            }
        }

        self::assertSame([], $violations, implode(PHP_EOL, $violations));
    }

    /**
     * @return list<SplFileInfo>
     */
    private function applicationPhpFiles(): array
    {
        $roots = [
            __DIR__.'/../../../app/Application',
            __DIR__.'/../../../app/Http/Controllers',
        ];

        $files = [];
        foreach ($roots as $root) {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
            foreach ($iterator as $file) {
                if ($file instanceof SplFileInfo && $file->isFile() && $file->getExtension() === 'php') {
                    $files[] = $file;
                }
            }
        }

        return $files;
    }

    /**
     * @return array<string, string>
     */
    private function forbiddenWallClockAccountingDatePatterns(): array
    {
        return [
            "/'business_date'\\s*=>\\s*now\\(\\)->toDateString\\(\\)/" => 'business_date must come from AccountingDay, not wall-clock today.',
            "/'business_date'\\s*=>\\s*Carbon(?:Immutable)?::today\\(\\)/" => 'business_date must come from AccountingDay, not wall-clock today.',
            "/'transaction_date'\\s*=>\\s*now\\(\\)->toDateString\\(\\)/" => 'transaction_date must come from the accounting day/session date, not wall-clock today.',
            '/\$businessDate\s*=\s*now\(\)->toDateString\(\)/' => '$businessDate must come from AccountingDay, not wall-clock today.',
            '/\$transactionDate\s*=\s*now\(\)->toDateString\(\)/' => '$transactionDate must come from the accounting day/session date, not wall-clock today.',
        ];
    }
}
