<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\Finance\Contracts\LoanInterestEngine;
use App\Support\Finance\DateRange;
use App\Support\Finance\FormulaEngineKey;
use App\Support\Finance\FormulaEngineManager;
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

        $registry->requireApproved(FormulaPolicyKey::PenaltiesAndArrears);
    }

    public function test_formula_policy_gate_passes_when_explicitly_approved(): void
    {
        config(['formulas.policies.penalties_and_arrears.approved' => true]);

        app(FormulaPolicyRegistry::class)->requireApproved(FormulaPolicyKey::PenaltiesAndArrears);

        self::assertTrue(app(FormulaPolicyRegistry::class)->isApproved(FormulaPolicyKey::PenaltiesAndArrears));
    }

    public function test_money_amount_rejects_cross_currency_arithmetic(): void
    {
        $this->expectException(InvalidArgumentException::class);

        MoneyAmount::of('1000', 'XAF')->plus(MoneyAmount::of('1000', 'EUR'));
    }

    public function test_money_amount_uses_configured_account_scale_for_xaf_ledger_amounts(): void
    {
        config(['money.default_scale' => 2]);

        $installment = MoneyAmount::of('833.33', 'XAF');

        self::assertSame('833.33', $installment->amount());
        self::assertSame('83333', $installment->minorAmount());
        self::assertSame('850.00', MoneyAmount::ofMinor(85000, 'XAF')->amount());
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

    public function test_default_formula_engine_fails_closed_until_stakeholder_policy_is_approved(): void
    {
        config(['formulas.policies.loan_interest_method.approved' => false]);

        $engine = app(FormulaEngineManager::class)->engine(FormulaEngineKey::LoanInterest);

        self::assertSame(FormulaEngineKey::LoanInterest, $engine->key());

        $this->expectException(FormulaPolicyNotApproved::class);

        if ($engine instanceof LoanInterestEngine) {
            $engine->calculate(
                MoneyAmount::of('100000'),
                PercentageRate::of('10'),
                DateRange::make(CarbonImmutable::parse('2026-04-01'), CarbonImmutable::parse('2026-04-30'))
            );
        }
    }

    public function test_formula_engine_can_be_swapped_by_configuration_after_policy_approval(): void
    {
        config([
            'formulas.policies.loan_interest_method.approved' => true,
            'formulas.engines.loan_interest' => 'test_loan_interest',
            'formulas.drivers.test_loan_interest' => TestLoanInterestEngine::class,
        ]);

        $engine = app(FormulaEngineManager::class)->engine(FormulaEngineKey::LoanInterest);

        self::assertInstanceOf(TestLoanInterestEngine::class, $engine);

        $interest = $engine->calculate(
            MoneyAmount::of('100000'),
            PercentageRate::of('10'),
            DateRange::make(CarbonImmutable::parse('2026-04-01'), CarbonImmutable::parse('2026-04-30'))
        );

        self::assertSame('1234.00', $interest->amount());
    }
}

final class TestLoanInterestEngine implements LoanInterestEngine
{
    public function key(): FormulaEngineKey
    {
        return FormulaEngineKey::LoanInterest;
    }

    public function requiredPolicy(): FormulaPolicyKey
    {
        return FormulaPolicyKey::LoanInterestMethod;
    }

    public function calculate(MoneyAmount $principal, PercentageRate $rate, DateRange $period): MoneyAmount
    {
        return MoneyAmount::of('1234', $principal->currency());
    }
}
