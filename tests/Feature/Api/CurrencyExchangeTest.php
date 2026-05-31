<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\LedgerAccount;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

final class CurrencyExchangeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_authorization_storage_and_subdelegate_sale_restriction(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $agency = $this->createAgency('FX01');

        $ok = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/fx-authorizations', [
                'agency_public_id' => $agency['public_id'],
                'authorization_reference' => 'COBAC-AUTH-001',
                'authorization_type' => 'credit_institution',
                'effective_from' => '2026-01-01',
                'supports_purchase' => true,
                'supports_sale' => true,
            ]);
        $this->assertJsonSuccess($ok, 201);

        $subdelegateRejection = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/fx-authorizations', [
                'agency_public_id' => $agency['public_id'],
                'authorization_reference' => 'SUB-002',
                'authorization_type' => 'sub_delegate',
                'effective_from' => '2026-01-01',
                'supports_purchase' => true,
                'supports_sale' => true,
            ]);
        $this->assertJsonError($subdelegateRejection, 422);
    }

    public function test_currency_creation(): void
    {
        $actor = $this->createUserWithRole('platform-admin');

        $response = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/currencies', [
                'code' => 'EUR',
                'name' => 'Euro',
                'minor_unit' => 2,
            ]);
        $this->assertJsonSuccess($response, 201);
        $this->assertDatabaseHas('currencies', ['code' => 'EUR', 'status' => 'active']);
    }

    public function test_rate_publication_requires_maker_checker(): void
    {
        $maker = $this->createUserWithRole('platform-admin');
        $checker = $this->createUserWithRole('platform-admin');
        $this->seedCurrency('EUR');

        $draft = $this->withApiHeaders()
            ->actingAsSanctum($maker)
            ->postJson('/api/v1/exchange-rates', [
                'base_currency' => 'XAF',
                'quote_currency' => 'EUR',
                'reference_rate' => 655.957,
                'buy_margin_rate' => 0.02,
                'sell_margin_rate' => 0.05,
                'effective_on' => '2026-05-01',
            ]);
        $this->assertJsonSuccess($draft, 201);
        $draft->assertJsonPath('data.status', 'draft');
        $ratePublicId = $this->requireStringJsonPath($draft, 'data.public_id');

        // Maker cannot approve their own draft.
        $selfApprove = $this->withApiHeaders()
            ->actingAsSanctum($maker)
            ->postJson('/api/v1/exchange-rates/'.$ratePublicId.'/approve');
        $this->assertJsonError($selfApprove, 422);

        // Checker can approve.
        $approve = $this->withApiHeaders()
            ->actingAsSanctum($checker)
            ->postJson('/api/v1/exchange-rates/'.$ratePublicId.'/approve');
        $this->assertJsonSuccess($approve);
        $approve->assertJsonPath('data.status', 'active');
    }

    public function test_second_overlapping_draft_is_rejected_when_approved(): void
    {
        $maker = $this->createUserWithRole('platform-admin');
        $checker = $this->createUserWithRole('platform-admin');
        $this->seedCurrency('EUR');

        $first = $this->withApiHeaders()
            ->actingAsSanctum($maker)
            ->postJson('/api/v1/exchange-rates', [
                'base_currency' => 'XAF',
                'quote_currency' => 'EUR',
                'reference_rate' => 655.957,
                'buy_margin_rate' => 0.02,
                'sell_margin_rate' => 0.05,
                'effective_on' => '2026-05-01',
                'effective_to' => '2026-05-31',
            ]);
        $this->assertJsonSuccess($first, 201);

        $second = $this->withApiHeaders()
            ->actingAsSanctum($maker)
            ->postJson('/api/v1/exchange-rates', [
                'base_currency' => 'XAF',
                'quote_currency' => 'EUR',
                'reference_rate' => 660.0,
                'buy_margin_rate' => 0.02,
                'sell_margin_rate' => 0.05,
                'effective_on' => '2026-05-15',
                'effective_to' => '2026-06-15',
            ]);
        $this->assertJsonSuccess($second, 201);

        $this->assertJsonSuccess($this->withApiHeaders()
            ->actingAsSanctum($checker)
            ->postJson('/api/v1/exchange-rates/'.$this->requireStringJsonPath($first, 'data.public_id').'/approve'));

        $overlap = $this->withApiHeaders()
            ->actingAsSanctum($checker)
            ->postJson('/api/v1/exchange-rates/'.$this->requireStringJsonPath($second, 'data.public_id').'/approve');
        $this->assertJsonError($overlap, 422);
    }

    public function test_overlapping_active_rate_is_rejected_at_draft_time(): void
    {
        $maker = $this->createUserWithRole('platform-admin');
        $checker = $this->createUserWithRole('platform-admin');
        $this->seedCurrency('EUR');
        $this->seedActiveRate('XAF', 'EUR', '2026-05-01', null, $maker, $checker);

        $overlap = $this->withApiHeaders()
            ->actingAsSanctum($maker)
            ->postJson('/api/v1/exchange-rates', [
                'base_currency' => 'XAF',
                'quote_currency' => 'EUR',
                'reference_rate' => 660.0,
                'buy_margin_rate' => 0.02,
                'sell_margin_rate' => 0.05,
                'effective_on' => '2026-05-15',
            ]);
        $this->assertJsonError($overlap, 422);
    }

    public function test_buy_transaction_increases_stock_and_posts_journal(): void
    {
        $context = $this->seedFullExchangeContext();
        $response = $this->withApiHeaders()
            ->actingAsSanctum($context['actor'])
            ->postJson('/api/v1/fx-tills/'.$context['till_public_id'].'/exchange-transactions', [
                'direction' => 'buy_foreign_currency',
                'foreign_currency' => 'EUR',
                'foreign_amount_minor' => 100,
                'identity_full_name' => 'Walk-In Client',
                'identity_number' => 'ID-12345',
                'identity_document_type' => 'national_id',
                'identity_issuing_country' => 'CM',
            ]);
        $this->assertJsonSuccess($response, 201);
        $response->assertJsonPath('data.transaction.direction', 'buy_foreign_currency');
        $response->assertJsonPath('data.transaction.foreign_amount_minor', 100);
        self::assertIsString($response->json('data.transaction.slip_number'));
        self::assertIsString($response->json('data.transaction.register_number'));

        $balance = DB::table('till_currency_balances')
            ->where('till_id', $context['till_id'])
            ->where('currency', 'EUR')
            ->first();
        self::assertIsObject($balance);
        self::assertSame(100, (int) $balance->current_balance_minor);

        $this->assertDatabaseHas('journal_entries', [
            'source_module' => 'fx',
            'source_type' => 'fx_buy_foreign_currency',
            'status' => 'posted',
        ]);
    }

    public function test_register_export_contains_required_posted_transaction_fields(): void
    {
        $context = $this->seedFullExchangeContext();
        $posted = $this->withApiHeaders()
            ->actingAsSanctum($context['actor'])
            ->postJson('/api/v1/fx-tills/'.$context['till_public_id'].'/exchange-transactions', [
                'direction' => 'buy_foreign_currency',
                'foreign_currency' => 'EUR',
                'foreign_amount_minor' => 100,
                'identity_full_name' => 'Walk-In Client',
                'identity_number' => 'ID-12345',
                'identity_document_type' => 'national_id',
                'identity_issuing_country' => 'CM',
                'transaction_date' => '2026-05-19',
            ]);
        $this->assertJsonSuccess($posted, 201);

        $register = $this->withApiHeaders()
            ->actingAsSanctum($context['actor'])
            ->getJson('/api/v1/fx-register?from=2026-05-01&to=2026-05-31&search=buy_foreign_currency&page=1&per_page=1');
        $this->assertJsonSuccess($register);
        $register->assertJsonPath('meta.pagination.current_page', 1);
        $register->assertJsonPath('meta.pagination.per_page', 1);
        $register->assertJsonPath('meta.pagination.total', 1);
        $register->assertJsonPath('data.entries.0.direction', 'buy_foreign_currency');
        $register->assertJsonPath('data.entries.0.client_identity_type', 'national_id');
        self::assertIsString($register->json('data.entries.0.slip_number'));
        self::assertIsString($register->json('data.entries.0.register_number'));
        self::assertIsString($register->json('data.entries.0.applied_rate'));
    }

    public function test_sell_transaction_requires_sufficient_stock(): void
    {
        $context = $this->seedFullExchangeContext();
        // No initial stock — sell should fail.
        $response = $this->withApiHeaders()
            ->actingAsSanctum($context['actor'])
            ->postJson('/api/v1/fx-tills/'.$context['till_public_id'].'/exchange-transactions', [
                'direction' => 'sell_foreign_currency',
                'foreign_currency' => 'EUR',
                'foreign_amount_minor' => 50,
                'identity_full_name' => 'Walk-In Client',
                'identity_number' => 'ID-12345',
                'identity_document_type' => 'national_id',
                'identity_issuing_country' => 'CM',
            ]);
        $this->assertJsonError($response, 422);
    }

    public function test_transaction_requires_identity_when_no_client_attached(): void
    {
        $context = $this->seedFullExchangeContext();
        $response = $this->withApiHeaders()
            ->actingAsSanctum($context['actor'])
            ->postJson('/api/v1/fx-tills/'.$context['till_public_id'].'/exchange-transactions', [
                'direction' => 'buy_foreign_currency',
                'foreign_currency' => 'EUR',
                'foreign_amount_minor' => 100,
            ]);
        $this->assertJsonError($response, 422);
    }

    public function test_reversal_restores_stock_and_posts_reversing_journal(): void
    {
        $context = $this->seedFullExchangeContext();
        $buy = $this->withApiHeaders()
            ->actingAsSanctum($context['actor'])
            ->postJson('/api/v1/fx-tills/'.$context['till_public_id'].'/exchange-transactions', [
                'direction' => 'buy_foreign_currency',
                'foreign_currency' => 'EUR',
                'foreign_amount_minor' => 100,
                'identity_full_name' => 'Walk-In Client',
                'identity_number' => 'ID-12345',
                'identity_document_type' => 'national_id',
                'identity_issuing_country' => 'CM',
            ]);
        $this->assertJsonSuccess($buy, 201);
        $txPublicId = $this->requireStringJsonPath($buy, 'data.transaction.public_id');

        $reversal = $this->withApiHeaders()
            ->actingAsSanctum($context['actor'])
            ->postJson('/api/v1/fx-transactions/'.$txPublicId.'/reversal');
        $this->assertJsonSuccess($reversal);

        $balance = DB::table('till_currency_balances')
            ->where('till_id', $context['till_id'])
            ->where('currency', 'EUR')
            ->first();
        self::assertIsObject($balance);
        self::assertSame(0, (int) $balance->current_balance_minor);

        $this->assertDatabaseHas('journal_entries', [
            'source_type' => 'fx_buy_foreign_currency_reversal',
            'status' => 'posted',
        ]);
    }

    public function test_partner_replenishment_increases_stock(): void
    {
        $context = $this->seedFullExchangeContext();
        $checker = $this->createUserWithRole('platform-admin');
        $response = $this->withApiHeaders()
            ->actingAsSanctum($context['actor'])
            ->postJson('/api/v1/fx-tills/'.$context['till_public_id'].'/stock-movements', [
                'movement_type' => 'partner_replenishment',
                'currency' => 'EUR',
                'amount_minor' => 500,
                'counterparty_name' => 'Partner Bank',
            ]);
        $this->assertJsonSuccess($response, 201);
        $response->assertJsonPath('data.status', 'pending');
        $movementPublicId = $this->requireStringJsonPath($response, 'data.public_id');

        $balanceBeforeApproval = DB::table('till_currency_balances')
            ->where('till_id', $context['till_id'])
            ->where('currency', 'EUR')
            ->first();
        self::assertNull($balanceBeforeApproval);

        $approve = $this->withApiHeaders()
            ->actingAsSanctum($checker)
            ->postJson('/api/v1/fx-stock-movements/'.$movementPublicId.'/approve');
        $this->assertJsonSuccess($approve);
        $approve->assertJsonPath('data.status', 'posted');

        $balance = DB::table('till_currency_balances')
            ->where('till_id', $context['till_id'])
            ->where('currency', 'EUR')
            ->first();
        self::assertIsObject($balance);
        self::assertSame(500, (int) $balance->current_balance_minor);
    }

    public function test_reconciliation_matching_count_closes_and_variance_blocks(): void
    {
        $context = $this->seedFullExchangeContext();
        DB::table('till_currency_balances')->insert([
            'till_id' => $context['till_id'],
            'currency' => 'EUR',
            'opening_balance_minor' => 0,
            'current_balance_minor' => 200,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Matching count → closed.
        $closing = $this->withApiHeaders()
            ->actingAsSanctum($context['actor'])
            ->postJson('/api/v1/fx-tills/'.$context['till_public_id'].'/reconciliations', [
                'business_date' => '2026-05-15',
                'currency' => 'EUR',
                'counted_minor' => 200,
            ]);
        $this->assertJsonSuccess($closing, 201);
        $closing->assertJsonPath('data.status', 'closed');

        // Variance → blocked (using next-day to keep unique key happy).
        $variance = $this->withApiHeaders()
            ->actingAsSanctum($context['actor'])
            ->postJson('/api/v1/fx-tills/'.$context['till_public_id'].'/reconciliations', [
                'business_date' => '2026-05-16',
                'currency' => 'EUR',
                'counted_minor' => 150,
            ]);
        $this->assertJsonSuccess($variance, 201);
        $variance->assertJsonPath('data.status', 'variance_blocked');
        $variance->assertJsonPath('data.variance_minor', -50);
    }

    public function test_main_till_cannot_be_used_for_exchange_operations(): void
    {
        $context = $this->seedFullExchangeContext();
        // Switch nature back to non-exchange.
        DB::table('tills')->where('id', $context['till_id'])->update(['nature' => 'counter']);

        $response = $this->withApiHeaders()
            ->actingAsSanctum($context['actor'])
            ->postJson('/api/v1/fx-tills/'.$context['till_public_id'].'/exchange-transactions', [
                'direction' => 'buy_foreign_currency',
                'foreign_currency' => 'EUR',
                'foreign_amount_minor' => 100,
                'identity_full_name' => 'Walk-In Client',
                'identity_number' => 'ID-12345',
                'identity_document_type' => 'national_id',
                'identity_issuing_country' => 'CM',
            ]);
        $this->assertJsonError($response, 422);
    }

    public function test_transaction_fails_when_authorization_is_inactive(): void
    {
        $context = $this->seedFullExchangeContext();
        DB::table('fx_authorizations')->update(['status' => 'suspended']);

        $response = $this->withApiHeaders()
            ->actingAsSanctum($context['actor'])
            ->postJson('/api/v1/fx-tills/'.$context['till_public_id'].'/exchange-transactions', [
                'direction' => 'buy_foreign_currency',
                'foreign_currency' => 'EUR',
                'foreign_amount_minor' => 100,
                'identity_full_name' => 'Walk-In Client',
                'identity_number' => 'ID-12345',
                'identity_document_type' => 'national_id',
                'identity_issuing_country' => 'CM',
            ]);
        $this->assertJsonError($response, 422);
    }

    public function test_draft_rate_cannot_be_used_for_exchange_transaction(): void
    {
        $context = $this->seedFullExchangeContext();
        // Remove the active rate seeded in context so only a draft exists.
        DB::table('exchange_rates')->update(['status' => 'draft']);

        $response = $this->withApiHeaders()
            ->actingAsSanctum($context['actor'])
            ->postJson('/api/v1/fx-tills/'.$context['till_public_id'].'/exchange-transactions', [
                'direction' => 'buy_foreign_currency',
                'foreign_currency' => 'EUR',
                'foreign_amount_minor' => 100,
                'identity_full_name' => 'Walk-In Client',
                'identity_number' => 'ID-12345',
                'identity_document_type' => 'national_id',
                'identity_issuing_country' => 'CM',
            ]);
        $this->assertJsonError($response, 422);
    }

    public function test_sell_transaction_with_sufficient_stock_decreases_balance_and_posts_journal(): void
    {
        $context = $this->seedFullExchangeContext();
        // Pre-load EUR stock directly so we have sufficient balance to sell.
        DB::table('till_currency_balances')->insert([
            'till_id' => $context['till_id'],
            'currency' => 'EUR',
            'opening_balance_minor' => 0,
            'current_balance_minor' => 300,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->withApiHeaders()
            ->actingAsSanctum($context['actor'])
            ->postJson('/api/v1/fx-tills/'.$context['till_public_id'].'/exchange-transactions', [
                'direction' => 'sell_foreign_currency',
                'foreign_currency' => 'EUR',
                'foreign_amount_minor' => 100,
                'identity_full_name' => 'Walk-In Client',
                'identity_number' => 'ID-99999',
                'identity_document_type' => 'passport',
                'identity_issuing_country' => 'CM',
            ]);
        $this->assertJsonSuccess($response, 201);
        $response->assertJsonPath('data.transaction.direction', 'sell_foreign_currency');

        $balance = DB::table('till_currency_balances')
            ->where('till_id', $context['till_id'])
            ->where('currency', 'EUR')
            ->first();
        self::assertIsObject($balance);
        self::assertSame(200, (int) $balance->current_balance_minor);

        $this->assertDatabaseHas('journal_entries', [
            'source_module' => 'fx',
            'source_type' => 'fx_sell_foreign_currency',
            'status' => 'posted',
        ]);
    }

    public function test_partner_sale_decreases_stock_after_approval(): void
    {
        $context = $this->seedFullExchangeContext();
        $checker = $this->createUserWithRole('platform-admin');

        // Pre-load EUR stock so the sale has something to draw from.
        DB::table('till_currency_balances')->insert([
            'till_id' => $context['till_id'],
            'currency' => 'EUR',
            'opening_balance_minor' => 0,
            'current_balance_minor' => 1000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->withApiHeaders()
            ->actingAsSanctum($context['actor'])
            ->postJson('/api/v1/fx-tills/'.$context['till_public_id'].'/stock-movements', [
                'movement_type' => 'partner_sale',
                'currency' => 'EUR',
                'amount_minor' => 400,
                'counterparty_name' => 'Partner Bank',
            ]);
        $this->assertJsonSuccess($response, 201);
        $response->assertJsonPath('data.status', 'pending');
        $movementPublicId = $this->requireStringJsonPath($response, 'data.public_id');

        $balanceBefore = DB::table('till_currency_balances')
            ->where('till_id', $context['till_id'])
            ->where('currency', 'EUR')
            ->value('current_balance_minor');
        if (! is_int($balanceBefore)) {
            throw new \UnexpectedValueException('Expected int balance');
        }
        self::assertSame(1000, $balanceBefore);

        $approve = $this->withApiHeaders()
            ->actingAsSanctum($checker)
            ->postJson('/api/v1/fx-stock-movements/'.$movementPublicId.'/approve');
        $this->assertJsonSuccess($approve);
        $approve->assertJsonPath('data.status', 'posted');

        $balanceAfter = DB::table('till_currency_balances')
            ->where('till_id', $context['till_id'])
            ->where('currency', 'EUR')
            ->value('current_balance_minor');
        if (! is_int($balanceAfter)) {
            throw new \UnexpectedValueException('Expected int balance');
        }
        self::assertSame(600, $balanceAfter);
    }

    public function test_stock_movement_requester_cannot_self_approve(): void
    {
        $context = $this->seedFullExchangeContext();

        $response = $this->withApiHeaders()
            ->actingAsSanctum($context['actor'])
            ->postJson('/api/v1/fx-tills/'.$context['till_public_id'].'/stock-movements', [
                'movement_type' => 'partner_replenishment',
                'currency' => 'EUR',
                'amount_minor' => 500,
                'counterparty_name' => 'Partner Bank',
            ]);
        $this->assertJsonSuccess($response, 201);
        $movementPublicId = $this->requireStringJsonPath($response, 'data.public_id');

        // Same actor tries to approve.
        $selfApprove = $this->withApiHeaders()
            ->actingAsSanctum($context['actor'])
            ->postJson('/api/v1/fx-stock-movements/'.$movementPublicId.'/approve');
        $this->assertJsonError($selfApprove, 422);
    }

    public function test_adjustment_correction_resolves_variance_blocked_reconciliation(): void
    {
        $context = $this->seedFullExchangeContext();
        $checker = $this->createUserWithRole('platform-admin');

        // Stock = 200 but count = 150 → variance_blocked.
        DB::table('till_currency_balances')->insert([
            'till_id' => $context['till_id'],
            'currency' => 'EUR',
            'opening_balance_minor' => 0,
            'current_balance_minor' => 200,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $reconciliation = $this->withApiHeaders()
            ->actingAsSanctum($context['actor'])
            ->postJson('/api/v1/fx-tills/'.$context['till_public_id'].'/reconciliations', [
                'business_date' => '2026-05-20',
                'currency' => 'EUR',
                'counted_minor' => 150,
                'notes' => 'End-of-day count',
            ]);
        $this->assertJsonSuccess($reconciliation, 201);
        $reconciliation->assertJsonPath('data.status', 'variance_blocked');
        $reconciliation->assertJsonPath('data.variance_minor', -50);

        // Submit adjustment_correction to bring stock down to match count.
        $movement = $this->withApiHeaders()
            ->actingAsSanctum($context['actor'])
            ->postJson('/api/v1/fx-tills/'.$context['till_public_id'].'/stock-movements', [
                'movement_type' => 'adjustment_correction',
                'currency' => 'EUR',
                'amount_minor' => 50,
                'notes' => 'Correction for variance on 2026-05-20',
            ]);
        $this->assertJsonSuccess($movement, 201);
        $movement->assertJsonPath('data.status', 'pending');
        $movementPublicId = $this->requireStringJsonPath($movement, 'data.public_id');

        // Checker approves the adjustment.
        $approve = $this->withApiHeaders()
            ->actingAsSanctum($checker)
            ->postJson('/api/v1/fx-stock-movements/'.$movementPublicId.'/approve');
        $this->assertJsonSuccess($approve);
        $approve->assertJsonPath('data.status', 'posted');

        // Stock is now 150 (matching the count).
        $balance = DB::table('till_currency_balances')
            ->where('till_id', $context['till_id'])
            ->where('currency', 'EUR')
            ->value('current_balance_minor');
        if (! is_int($balance)) {
            throw new \UnexpectedValueException('Expected int balance');
        }
        self::assertSame(150, $balance);
    }

    public function test_inactive_currency_is_rejected_for_rates_and_transactions(): void
    {
        $context = $this->seedFullExchangeContext();
        DB::table('currencies')->where('code', 'EUR')->update(['status' => 'inactive']);

        $rate = $this->withApiHeaders()
            ->actingAsSanctum($context['actor'])
            ->postJson('/api/v1/exchange-rates', [
                'base_currency' => 'XAF',
                'quote_currency' => 'EUR',
                'reference_rate' => 660.0,
                'buy_margin_rate' => 0.02,
                'sell_margin_rate' => 0.05,
                'effective_on' => '2026-06-01',
            ]);
        $this->assertJsonError($rate, 422);

        $transaction = $this->withApiHeaders()
            ->actingAsSanctum($context['actor'])
            ->postJson('/api/v1/fx-tills/'.$context['till_public_id'].'/exchange-transactions', [
                'direction' => 'buy_foreign_currency',
                'foreign_currency' => 'EUR',
                'foreign_amount_minor' => 100,
                'identity_full_name' => 'Walk-In Client',
                'identity_number' => 'ID-12345',
                'identity_document_type' => 'national_id',
                'identity_issuing_country' => 'CM',
            ]);
        $this->assertJsonError($transaction, 422);
    }

    /**
     * @return array{
     *     actor:User,
     *     agency_id:int,
     *     till_id:int,
     *     till_public_id:string,
     * }
     */
    private function seedFullExchangeContext(): array
    {
        $maker = $this->createUserWithRole('platform-admin');
        $checker = $this->createUserWithRole('platform-admin');
        $actor = $this->createUserWithRole('platform-admin');
        $agency = $this->createAgency('FX-FULL-'.Str::random(3));

        // Authorization
        DB::table('fx_authorizations')->insert([
            'public_id' => (string) Str::ulid(),
            'agency_id' => $agency['id'],
            'authorization_reference' => 'COBAC-AUTH-'.Str::random(4),
            'authorization_type' => 'credit_institution',
            'effective_from' => '2026-01-01',
            'effective_to' => null,
            'status' => 'active',
            'supports_purchase' => true,
            'supports_sale' => true,
            'metadata' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Currencies
        $this->seedCurrency('EUR');

        // Active rate
        $this->seedActiveRate('XAF', 'EUR', '2026-05-01', null, $maker, $checker);

        // Exchange till
        $tillLedger = $this->createLedgerAccount($agency['id']);
        $tillPublicId = (string) Str::ulid();
        $tillId = DB::table('tills')->insertGetId([
            'public_id' => $tillPublicId,
            'agency_id' => $agency['id'],
            'code' => 'FX-'.Str::random(4),
            'name' => 'FX Counter',
            'type' => 'counter',
            'status' => 'active',
            'daily_state' => 'open',
            'requires_denominations' => false,
            'currency' => 'XAF',
            'nature' => 'exchange',
            'ledger_account_id' => $tillLedger['id'],
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Operation mappings
        $debit = $this->createLedgerAccount($agency['id']);
        $credit = $this->createLedgerAccount($agency['id']);
        foreach (['fx_buy_foreign_currency', 'fx_sell_foreign_currency'] as $code) {
            $opCodeId = DB::table('operation_codes')->insertGetId([
                'public_id' => (string) Str::ulid(),
                'code' => $code,
                'label' => str_replace('_', ' ', $code),
                'module' => 'fx',
                'operation_type' => 'fx_transaction',
                'direction' => 'mixed',
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('operation_account_mappings')->insert([
                'public_id' => (string) Str::ulid(),
                'operation_code_id' => $opCodeId,
                'debit_ledger_account_id' => $debit['id'],
                'credit_ledger_account_id' => $credit['id'],
                'currency' => 'XAF',
                'status' => 'active',
                'rules' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return [
            'actor' => $actor,
            'agency_id' => $agency['id'],
            'till_id' => $tillId,
            'till_public_id' => $tillPublicId,
        ];
    }

    private function seedCurrency(string $code): void
    {
        DB::table('currencies')->insertOrIgnore([
            'code' => $code,
            'name' => $code === 'XAF' ? 'Franc CFA' : $code,
            'minor_unit' => 2,
            'is_base_currency' => $code === 'XAF',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        // Ensure XAF exists too
        DB::table('currencies')->insertOrIgnore([
            'code' => 'XAF',
            'name' => 'Franc CFA',
            'minor_unit' => 0,
            'is_base_currency' => true,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function seedActiveRate(string $base, string $quote, string $effectiveOn, ?string $effectiveTo, User $maker, User $checker): void
    {
        DB::table('exchange_rates')->insert([
            'public_id' => (string) Str::ulid(),
            'base_currency' => $base,
            'quote_currency' => $quote,
            'reference_rate' => 655.957,
            'buy_margin_rate' => 0.02,
            'sell_margin_rate' => 0.05,
            'buy_rate' => 655.957 * 0.98,
            'sell_rate' => 655.957 * 1.05,
            'effective_on' => $effectiveOn,
            'effective_to' => $effectiveTo,
            'status' => 'active',
            'created_by_user_id' => $maker->id,
            'approved_by_user_id' => $checker->id,
            'approved_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createUserWithRole(string $role): User
    {
        $user = User::factory()->createOne([
            'status' => User::STATUS_ACTIVE,
            'phone_verified_at' => now(),
        ]);
        $user->assignRole($role);

        return $user;
    }

    /**
     * @return array{id:int, public_id:string}
     */
    private function createAgency(string $code): array
    {
        $publicId = (string) Str::ulid();
        $id = DB::table('agencies')->insertGetId([
            'public_id' => $publicId,
            'code' => $code,
            'name' => 'Agency '.$code,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return ['id' => $id, 'public_id' => $publicId];
    }

    /**
     * @return array{id:int, public_id:string}
     */
    private function createLedgerAccount(int $agencyId): array
    {
        $publicId = (string) Str::ulid();
        $id = DB::table('ledger_accounts')->insertGetId([
            'public_id' => $publicId,
            'agency_id' => $agencyId,
            'code' => 'FX-LEDGER-'.Str::ulid(),
            'name' => 'FX Ledger',
            'account_class' => LedgerAccount::ACCOUNT_CLASS_ASSET,
            'normal_balance_side' => LedgerAccount::NORMAL_BALANCE_DEBIT,
            'status' => LedgerAccount::STATUS_ACTIVE,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return ['id' => $id, 'public_id' => $publicId];
    }

    private function requireStringJsonPath(TestResponse $response, string $path): string
    {
        $value = $response->json($path);
        self::assertIsString($value);

        return $value;
    }
}
