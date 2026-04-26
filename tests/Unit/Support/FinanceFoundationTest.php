<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\Finance\DateRange;
use App\Support\Finance\FormulaPolicyKey;
use App\Support\Finance\FormulaPolicyNotApproved;
use App\Support\Finance\FormulaPolicyRegistry;
use App\Support\Finance\JournalEntryDraft;
use App\Support\Finance\JournalEntryLineDraft;
use App\Support\Finance\LedgerLineType;
use App\Support\Finance\MoneyAmount;
use App\Support\Finance\PercentageRate;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Tests\TestCase;

final class FinanceFoundationTest extends TestCase
{
    public function test_formula_policy_gate_fails_closed_until_approved(): void
    {
        $registry = app(FormulaPolicyRegistry::class);

        $this->expectException(FormulaPolicyNotApproved::class);

        $registry->requireApproved(FormulaPolicyKey::LoanInterestMethod);
    }

    public function test_formula_policy_gate_passes_when_explicitly_approved(): void
    {
        config(['formulas.policies.loan_interest_method.approved' => true]);

        app(FormulaPolicyRegistry::class)->requireApproved(FormulaPolicyKey::LoanInterestMethod);

        self::assertTrue(app(FormulaPolicyRegistry::class)->isApproved(FormulaPolicyKey::LoanInterestMethod));
    }

    public function test_money_amount_rejects_cross_currency_arithmetic(): void
    {
        $this->expectException(InvalidArgumentException::class);

        MoneyAmount::of('1000', 'XAF')->plus(MoneyAmount::of('1000', 'EUR'));
    }

    public function test_percentage_rate_rejects_negative_values(): void
    {
        $this->expectException(InvalidArgumentException::class);

        PercentageRate::of('-1');
    }

    public function test_date_range_rejects_end_before_start(): void
    {
        $this->expectException(InvalidArgumentException::class);

        DateRange::make(
            CarbonImmutable::parse('2026-04-10'),
            CarbonImmutable::parse('2026-04-09')
        );
    }

    public function test_journal_entry_draft_requires_balanced_debits_and_credits(): void
    {
        $draft = JournalEntryDraft::make(
            JournalEntryLineDraft::make('CASH-001', LedgerLineType::Debit, MoneyAmount::of('10000')),
            JournalEntryLineDraft::make('CUSTOMER-001', LedgerLineType::Credit, MoneyAmount::of('10000')),
        );

        self::assertCount(2, $draft->lines);
    }

    public function test_journal_entry_draft_rejects_unbalanced_lines(): void
    {
        $this->expectException(InvalidArgumentException::class);

        JournalEntryDraft::make(
            JournalEntryLineDraft::make('CASH-001', LedgerLineType::Debit, MoneyAmount::of('10000')),
            JournalEntryLineDraft::make('CUSTOMER-001', LedgerLineType::Credit, MoneyAmount::of('9000')),
        );
    }
}
