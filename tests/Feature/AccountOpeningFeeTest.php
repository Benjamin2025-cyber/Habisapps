<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\LedgerAccount;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The accounting team's opening-fee rule: every first transaction on a customer
 * account sweeps the account's opening fee straight to the income account —
 * 7611 « Produits sur opérations d'ouverture de comptes », one sub-account per
 * account type — with nobody posting it by hand.
 *
 * The sweep is the customer's own ledger debited and the mapped income account
 * credited, in a system journal posted inside the same write as the triggering
 * transaction, stamped on the account so it happens exactly once.
 */
final class AccountOpeningFeeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    /**
     * The core flow: deposit 10 000 on an account whose product charges 500,
     * and a second, separate journal appears — 500 debited from the customer's
     * ledger, 500 credited to the 7611 income account, both posted, with the
     * account stamped as charged. The receipt exposes it for printing.
     */
    public function test_first_deposit_sweeps_the_product_opening_fee_to_the_income_account(): void
    {
        $setup = $this->setUpAccountWithFee(openingFeeMinor: 500);
        $actor = $setup['actor'];
        $token = 'Bearer '.$actor->createToken('opening-fee-deposit')->plainTextToken;

        $deposit = $this->withApiHeaders(['Authorization' => $token])
            ->postJson('/api/v1/teller-sessions/'.$setup['sessionPublicId'].'/deposits', [
                'customer_account_public_id' => $setup['customerAccount']['public_id'],
                'amount_minor' => 10000,
                'currency' => 'XAF',
                'idempotency_key' => 'opening-fee-deposit-001',
            ]);
        $this->assertJsonSuccess($deposit, 201);
        $deposit->assertJsonPath('data.opening_fee.amount_minor', 500);
        $deposit->assertJsonPath('data.opening_fee.currency', 'XAF');
        $feeJournalPublicId = $this->requireStringJsonPath($deposit, 'data.opening_fee.journal_entry_public_id');

        // The sweep is its own posted journal, distinct from the deposit's.
        $feeJournalId = DB::table('journal_entries')->where('public_id', $feeJournalPublicId)->value('id');
        self::assertIsInt($feeJournalId);
        $feeJournal = DB::table('journal_entries')->where('id', $feeJournalId)->first(['status', 'source_type', 'reference', 'agency_id']);
        self::assertIsObject($feeJournal);
        self::assertSame('posted', $feeJournal->status);
        self::assertSame('account_opening_fee', $feeJournal->source_type);
        self::assertSame($setup['agency']['id'], (int) $feeJournal->agency_id);
        // The fee journal hangs off the triggering voucher's own number, so it
        // follows the guichet nomenclature: AOF-REC-G000-2026-09-000001. G000
        // because this fixture's agency code carries no digits.
        self::assertStringStartsWith('AOF-REC-G', (string) $feeJournal->reference);

        $this->assertDatabaseHas('journal_lines', [
            'journal_entry_id' => $feeJournalId,
            'ledger_account_id' => $setup['customerLedger']['id'],
            'customer_account_id' => $setup['customerAccount']['id'],
            'debit_minor' => 500,
            'credit_minor' => 0,
        ]);
        $this->assertDatabaseHas('journal_lines', [
            'journal_entry_id' => $feeJournalId,
            'ledger_account_id' => $setup['incomeLedger']['id'],
            'debit_minor' => 0,
            'credit_minor' => 500,
        ]);
        self::assertSame(500, (int) DB::table('journal_lines')->where('journal_entry_id', $feeJournalId)->sum('debit_minor'));
        self::assertSame(500, (int) DB::table('journal_lines')->where('journal_entry_id', $feeJournalId)->sum('credit_minor'));

        // The stamp is what makes the sweep once-per-account.
        $stamp = DB::table('customer_accounts')->where('id', $setup['customerAccount']['id'])->value('opening_fee_collected_at');
        self::assertNotNull($stamp);

        // The sweep shows up in the account's statement, since it debits the
        // customer's own ledger.
        $statement = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('opening-fee-statement')->plainTextToken])
            ->getJson('/api/v1/customer-accounts/'.$setup['customerAccount']['public_id'].'/statement');
        $this->assertJsonSuccess($statement);
        $closingBalance = $statement->json('data.statement.closing_balance_minor');
        self::assertIsInt($closingBalance);
        self::assertSame(10000 - 500, $closingBalance);
    }

    /**
     * Exactly once: a second deposit after the sweep produces no new fee
     * journal, and neither does an idempotent replay of the first deposit —
     * though the replay still *describes* the sweep, so a reprinted receipt
     * carries the fee line the customer was charged.
     */
    public function test_opening_fee_is_swept_exactly_once_per_account(): void
    {
        $setup = $this->setUpAccountWithFee(openingFeeMinor: 500);
        $actor = $setup['actor'];
        $token = 'Bearer '.$actor->createToken('opening-fee-once')->plainTextToken;

        $first = $this->withApiHeaders(['Authorization' => $token])
            ->postJson('/api/v1/teller-sessions/'.$setup['sessionPublicId'].'/deposits', [
                'customer_account_public_id' => $setup['customerAccount']['public_id'],
                'amount_minor' => 10000,
                'currency' => 'XAF',
                'idempotency_key' => 'opening-fee-once-first',
            ]);
        $this->assertJsonSuccess($first, 201);
        self::assertNotNull($first->json('data.opening_fee'));

        $replay = $this->withApiHeaders(['Authorization' => $token])
            ->postJson('/api/v1/teller-sessions/'.$setup['sessionPublicId'].'/deposits', [
                'customer_account_public_id' => $setup['customerAccount']['public_id'],
                'amount_minor' => 10000,
                'currency' => 'XAF',
                'idempotency_key' => 'opening-fee-once-first',
            ]);
        $this->assertJsonSuccess($replay, 201);
        $replay->assertJsonPath('data.opening_fee.amount_minor', 500);
        $replay->assertJsonPath(
            'data.opening_fee.journal_entry_public_id',
            $first->json('data.opening_fee.journal_entry_public_id'),
        );

        $second = $this->withApiHeaders(['Authorization' => $token])
            ->postJson('/api/v1/teller-sessions/'.$setup['sessionPublicId'].'/deposits', [
                'customer_account_public_id' => $setup['customerAccount']['public_id'],
                'amount_minor' => 2000,
                'currency' => 'XAF',
                'idempotency_key' => 'opening-fee-once-second',
            ]);
        $this->assertJsonSuccess($second, 201);
        self::assertNull($second->json('data.opening_fee'));

        self::assertSame(
            1,
            DB::table('journal_entries')->where('source_type', 'account_opening_fee')->count(),
        );
    }

    /**
     * A withdrawal can be the account's first transaction. The withdrawal's own
     * available-balance check runs against the balance planted by a manual
     * journal, then the sweep takes its fee from what remains.
     */
    public function test_first_withdrawal_on_a_funded_account_sweeps_the_opening_fee(): void
    {
        $setup = $this->setUpAccountWithFee(openingFeeMinor: 500);
        $actor = $setup['actor'];
        $token = 'Bearer '.$actor->createToken('opening-fee-withdrawal')->plainTextToken;

        // Fund the account by hand — a manual posted journal — so that no teller
        // transaction has ever touched it and the withdrawal *is* the first one.
        $this->plantCustomerAccountBalance($setup, 20000);

        $withdrawal = $this->withApiHeaders(['Authorization' => $token])
            ->postJson('/api/v1/teller-sessions/'.$setup['sessionPublicId'].'/withdrawals', [
                'customer_account_public_id' => $setup['customerAccount']['public_id'],
                'amount_minor' => 3000,
                'currency' => 'XAF',
                'signature_public_id' => $setup['signature']['public_id'],
                'signature_verification_method' => 'visual_match',
                'idempotency_key' => 'opening-fee-withdrawal-001',
            ]);
        $this->assertJsonSuccess($withdrawal, 201);
        $withdrawal->assertJsonPath('data.opening_fee.amount_minor', 500);

        self::assertSame(
            1,
            DB::table('journal_entries')->where('source_type', 'account_opening_fee')->count(),
        );
        self::assertNotNull(DB::table('customer_accounts')->where('id', $setup['customerAccount']['id'])->value('opening_fee_collected_at'));
    }

    /**
     * A first transaction too small to carry the fee defers the sweep: the
     * account is not driven negative, and the next transaction — the account's
     * second — collects the fee, because the stamp was never set.
     */
    public function test_opening_fee_defers_when_the_first_transaction_cannot_carry_it(): void
    {
        $setup = $this->setUpAccountWithFee(openingFeeMinor: 5000);
        $actor = $setup['actor'];
        $token = 'Bearer '.$actor->createToken('opening-fee-defer')->plainTextToken;

        $thin = $this->withApiHeaders(['Authorization' => $token])
            ->postJson('/api/v1/teller-sessions/'.$setup['sessionPublicId'].'/deposits', [
                'customer_account_public_id' => $setup['customerAccount']['public_id'],
                'amount_minor' => 1000,
                'currency' => 'XAF',
                'idempotency_key' => 'opening-fee-defer-first',
            ]);
        $this->assertJsonSuccess($thin, 201);
        self::assertNull($thin->json('data.opening_fee'));
        self::assertNull(DB::table('customer_accounts')->where('id', $setup['customerAccount']['id'])->value('opening_fee_collected_at'));
        self::assertSame(0, DB::table('journal_entries')->where('source_type', 'account_opening_fee')->count());

        $funded = $this->withApiHeaders(['Authorization' => $token])
            ->postJson('/api/v1/teller-sessions/'.$setup['sessionPublicId'].'/deposits', [
                'customer_account_public_id' => $setup['customerAccount']['public_id'],
                'amount_minor' => 20000,
                'currency' => 'XAF',
                'idempotency_key' => 'opening-fee-defer-second',
            ]);
        $this->assertJsonSuccess($funded, 201);
        self::assertNotNull($funded->json('data.opening_fee'));
        $funded->assertJsonPath('data.opening_fee.amount_minor', 5000);
        self::assertSame(1, DB::table('journal_entries')->where('source_type', 'account_opening_fee')->count());
    }

    /**
     * A reçu de caisse names the account and the holder, so the transaction
     * carries both — on the posting response and on the session's operation
     * list, which is where a reprint comes from. The counter cannot resolve
     * either itself: a teller does not hold `accounts.view`.
     */
    public function test_teller_transaction_carries_the_account_number_and_holder(): void
    {
        $setup = $this->setUpAccountWithFee(openingFeeMinor: 0);
        $actor = $setup['actor'];
        $token = 'Bearer '.$actor->createToken('receipt-identity')->plainTextToken;

        $deposit = $this->withApiHeaders(['Authorization' => $token])
            ->postJson('/api/v1/teller-sessions/'.$setup['sessionPublicId'].'/deposits', [
                'customer_account_public_id' => $setup['customerAccount']['public_id'],
                'amount_minor' => 10000,
                'currency' => 'XAF',
                'idempotency_key' => 'receipt-identity-001',
            ]);
        $this->assertJsonSuccess($deposit, 201);
        $deposit->assertJsonPath('data.teller_transaction.customer_account_number', 'AOF-ACC-001');
        $deposit->assertJsonPath('data.teller_transaction.client_display_name', 'DEPOSITOR Aof');

        // And on the list the reprint reads from.
        $list = $this->withApiHeaders(['Authorization' => $token])
            ->getJson('/api/v1/teller-transactions?filter[teller_session_public_id]='.$setup['sessionPublicId']);
        $this->assertJsonSuccess($list);
        $list->assertJsonPath('data.teller_transactions.0.customer_account_number', 'AOF-ACC-001');
        $list->assertJsonPath('data.teller_transactions.0.client_display_name', 'DEPOSITOR Aof');
    }

    /**
     * The account sheet announces the fee before it is taken and stops
     * announcing it afterwards, so the aperçu the teller shows the customer
     * matches what the receipt will say.
     */
    public function test_account_resource_announces_the_pending_opening_fee(): void
    {
        $setup = $this->setUpAccountWithFee(openingFeeMinor: 500);
        $actor = $setup['actor'];
        $url = '/api/v1/customer-accounts/'.$setup['customerAccount']['public_id'];

        $before = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('aof-sheet-before')->plainTextToken])
            ->getJson($url);
        $this->assertJsonSuccess($before);
        $before->assertJsonPath('data.pending_opening_fee_minor', 500);
        $before->assertJsonPath('data.opening_fee_collected_at', null);

        $deposit = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('aof-sheet-deposit')->plainTextToken])
            ->postJson('/api/v1/teller-sessions/'.$setup['sessionPublicId'].'/deposits', [
                'customer_account_public_id' => $setup['customerAccount']['public_id'],
                'amount_minor' => 10000,
                'currency' => 'XAF',
                'idempotency_key' => 'opening-fee-sheet-001',
            ]);
        $this->assertJsonSuccess($deposit, 201);

        $after = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('aof-sheet-after')->plainTextToken])
            ->getJson($url);
        $this->assertJsonSuccess($after);
        $after->assertJsonPath('data.pending_opening_fee_minor', 0);
        self::assertNotNull($after->json('data.opening_fee_collected_at'));
    }

    /**
     * Held money is not the institution's to bill against. An account whose
     * whole balance sits under an active hold — a guarantee deposit, a
     * litigation block — defers the fee instead of leaving the hold unbacked,
     * which is what a withdrawal of the same francs would have been refused for.
     */
    public function test_opening_fee_does_not_spend_held_or_unavailable_money(): void
    {
        $setup = $this->setUpAccountWithFee(openingFeeMinor: 500);
        $actor = $setup['actor'];
        $token = 'Bearer '.$actor->createToken('opening-fee-hold')->plainTextToken;

        // 10 000 deposited, 9 600 of it held: 400 left, not enough for the 500.
        $this->placeHold($setup, 9600);

        $deposit = $this->withApiHeaders(['Authorization' => $token])
            ->postJson('/api/v1/teller-sessions/'.$setup['sessionPublicId'].'/deposits', [
                'customer_account_public_id' => $setup['customerAccount']['public_id'],
                'amount_minor' => 10000,
                'currency' => 'XAF',
                'idempotency_key' => 'opening-fee-hold-001',
            ]);
        $this->assertJsonSuccess($deposit, 201);
        self::assertNull($deposit->json('data.opening_fee'));
        self::assertSame(0, DB::table('journal_entries')->where('source_type', 'account_opening_fee')->count());
        self::assertNull(DB::table('customer_accounts')->where('id', $setup['customerAccount']['id'])->value('opening_fee_collected_at'));

        // Funding the account past the hold lets the deferred fee through.
        $second = $this->withApiHeaders(['Authorization' => $token])
            ->postJson('/api/v1/teller-sessions/'.$setup['sessionPublicId'].'/deposits', [
                'customer_account_public_id' => $setup['customerAccount']['public_id'],
                'amount_minor' => 5000,
                'currency' => 'XAF',
                'idempotency_key' => 'opening-fee-hold-002',
            ]);
        $this->assertJsonSuccess($second, 201);
        $second->assertJsonPath('data.opening_fee.amount_minor', 500);
    }

    /**
     * The sweep is the one movement on the account nobody asked for, so it
     * carries its own audit entry rather than hiding inside the deposit's.
     */
    public function test_opening_fee_sweep_is_audited_in_its_own_right(): void
    {
        $setup = $this->setUpAccountWithFee(openingFeeMinor: 500);
        $actor = $setup['actor'];

        $deposit = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('opening-fee-audit')->plainTextToken])
            ->postJson('/api/v1/teller-sessions/'.$setup['sessionPublicId'].'/deposits', [
                'customer_account_public_id' => $setup['customerAccount']['public_id'],
                'amount_minor' => 10000,
                'currency' => 'XAF',
                'idempotency_key' => 'opening-fee-audit-001',
            ]);
        $this->assertJsonSuccess($deposit, 201);

        $event = DB::table('activity_log')->where('event', 'account.opening_fee.swept')->first(['properties']);
        self::assertIsObject($event);
        $properties = json_decode((string) $event->properties, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($properties);
        self::assertSame(500, $properties['amount_minor'] ?? null);
        self::assertSame($setup['customerAccount']['public_id'], $properties['customer_account_public_id'] ?? null);
        self::assertSame(
            $deposit->json('data.opening_fee.journal_entry_public_id'),
            $properties['journal_entry_public_id'] ?? null,
        );

        // A deposit that sweeps nothing records nothing.
        $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('opening-fee-audit-2')->plainTextToken])
            ->postJson('/api/v1/teller-sessions/'.$setup['sessionPublicId'].'/deposits', [
                'customer_account_public_id' => $setup['customerAccount']['public_id'],
                'amount_minor' => 2000,
                'currency' => 'XAF',
                'idempotency_key' => 'opening-fee-audit-002',
            ]);
        self::assertSame(1, DB::table('activity_log')->where('event', 'account.opening_fee.swept')->count());
    }

    /**
     * Switching a fee on does not bill the book that is already open.
     *
     * Every account opened before the tariff existed has never been charged, so
     * a sweep reading the product's current figure would have taken the new fee
     * out of every one of them on their next deposit — the whole customer base,
     * silently, the day the accounting team first used the field. The account
     * carries the tariff of the day it was opened instead.
     */
    public function test_switching_a_fee_on_does_not_bill_accounts_opened_before_it(): void
    {
        // An account opened under a tariff that charged nothing.
        $setup = $this->setUpAccountWithFee(openingFeeMinor: 0);
        $actor = $setup['actor'];

        // The accounting team now sets 5 000 on the product.
        DB::table('account_products')->where('id', $setup['product']['id'])->update(['opening_fee_minor' => 5000]);

        $account = $this->withApiHeaders()->actingAsSanctum($actor)
            ->getJson('/api/v1/customer-accounts/'.$setup['customerAccount']['public_id']);
        $this->assertJsonSuccess($account);
        $account->assertJsonPath('data.pending_opening_fee_minor', 0);

        $deposit = $this->withApiHeaders()->actingAsSanctum($actor)
            ->postJson('/api/v1/teller-sessions/'.$setup['sessionPublicId'].'/deposits', [
                'customer_account_public_id' => $setup['customerAccount']['public_id'],
                'amount_minor' => 10000,
                'currency' => 'XAF',
                'idempotency_key' => 'opening-fee-retro-001',
            ]);
        $this->assertJsonSuccess($deposit, 201);
        self::assertNull($deposit->json('data.opening_fee'));
        self::assertSame(0, DB::table('journal_entries')->where('source_type', 'account_opening_fee')->count());

        // An account opened after the change does owe it — the tariff applies
        // from the day it is set, not before and not never.
        $fresh = $this->withApiHeaders()->actingAsSanctum($actor)
            ->postJson('/api/v1/customer-accounts', [
                'client_public_id' => $this->clientPublicIdOf($setup['customerAccount']['id']),
                'agency_public_id' => $setup['agency']['public_id'],
                'account_product_public_id' => $setup['product']['public_id'],
                'account_number' => 'AOF-ACC-NEW',
                'account_title' => 'Aof Depositor',
                'currency' => 'XAF',
                'opened_on' => now()->toDateString(),
            ]);
        $this->assertJsonSuccess($fresh, 201);
        $fresh->assertJsonPath('data.pending_opening_fee_minor', 5000);

        // And moving an account onto another product — the correction of a
        // wrong pick at opening — carries that product's tariff across, while
        // the fee is still owed.
        $plainProduct = $this->createAccountProduct($setup['agency']['id'], 0, 'EP-AOF-PLAIN');
        $moved = $this->withApiHeaders()->actingAsSanctum($actor)
            ->patchJson('/api/v1/customer-accounts/'.$this->requireStringJsonPath($fresh, 'data.public_id'), [
                'account_product_public_id' => $plainProduct['public_id'],
            ]);
        $this->assertJsonSuccess($moved);
        $moved->assertJsonPath('data.pending_opening_fee_minor', 0);
    }

    /**
     * No fee configured, nothing to sweep: a plain product leaves every deposit
     * and the journal set untouched by the sweep.
     */
    public function test_account_product_without_opening_fee_leaves_no_sweep(): void
    {
        $setup = $this->setUpAccountWithFee(openingFeeMinor: 0);
        $actor = $setup['actor'];
        $token = 'Bearer '.$actor->createToken('opening-fee-none')->plainTextToken;

        $deposit = $this->withApiHeaders(['Authorization' => $token])
            ->postJson('/api/v1/teller-sessions/'.$setup['sessionPublicId'].'/deposits', [
                'customer_account_public_id' => $setup['customerAccount']['public_id'],
                'amount_minor' => 10000,
                'currency' => 'XAF',
                'idempotency_key' => 'opening-fee-none-first',
            ]);
        $this->assertJsonSuccess($deposit, 201);
        self::assertNull($deposit->json('data.opening_fee'));
        self::assertNull(DB::table('customer_accounts')->where('id', $setup['customerAccount']['id'])->value('opening_fee_collected_at'));
        self::assertSame(0, DB::table('journal_entries')->where('source_type', 'account_opening_fee')->count());
    }

    /**
     * « 7611 — pouvant avoir plusieurs sous-comptes selon les types de comptes
     * proposés par la structure ». A product naming its own sub-account is
     * credited there and not to the agency's default mapping, which is the only
     * way one institution can split the fee by account type.
     */
    public function test_product_sub_account_takes_precedence_over_the_agency_mapping(): void
    {
        $setup = $this->setUpAccountWithFee(openingFeeMinor: 500);
        $actor = $setup['actor'];

        // A second 7611 sub-account, pinned on the product — « Épargne » does
        // not credit the same income account as « Compte courant ».
        $savingsIncome = $this->createLedgerAccount($setup['agency']['id'], 'AOF-7611-SAVINGS', LedgerAccount::ACCOUNT_CLASS_PRODUITS);
        DB::table('account_products')
            ->where('id', $setup['product']['id'])
            ->update(['opening_fee_ledger_account_id' => $savingsIncome['id']]);

        $deposit = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('aof-sub')->plainTextToken])
            ->postJson('/api/v1/teller-sessions/'.$setup['sessionPublicId'].'/deposits', [
                'customer_account_public_id' => $setup['customerAccount']['public_id'],
                'amount_minor' => 10000,
                'currency' => 'XAF',
                'idempotency_key' => 'opening-fee-sub-001',
            ]);
        $this->assertJsonSuccess($deposit, 201);
        $feeJournalPublicId = $this->requireStringJsonPath($deposit, 'data.opening_fee.journal_entry_public_id');
        $feeJournalId = DB::table('journal_entries')->where('public_id', $feeJournalPublicId)->value('id');
        self::assertIsInt($feeJournalId);

        $this->assertDatabaseHas('journal_lines', [
            'journal_entry_id' => $feeJournalId,
            'ledger_account_id' => $savingsIncome['id'],
            'credit_minor' => 500,
        ]);
        $this->assertDatabaseMissing('journal_lines', [
            'journal_entry_id' => $feeJournalId,
            'ledger_account_id' => $setup['incomeLedger']['id'],
        ]);
    }

    /**
     * A product sub-account that has since been deactivated refuses the deposit
     * rather than quietly falling back to the agency mapping: the institution
     * chose where this product's fee lands, and posting it elsewhere without
     * saying so is how a 7611 breakdown stops reconciling.
     */
    public function test_inactive_product_sub_account_refuses_the_deposit(): void
    {
        $setup = $this->setUpAccountWithFee(openingFeeMinor: 500);
        $actor = $setup['actor'];

        $retired = $this->createLedgerAccount($setup['agency']['id'], 'AOF-7611-RETIRED', LedgerAccount::ACCOUNT_CLASS_PRODUITS);
        DB::table('ledger_accounts')->where('id', $retired['id'])->update(['status' => 'inactive']);
        DB::table('account_products')
            ->where('id', $setup['product']['id'])
            ->update(['opening_fee_ledger_account_id' => $retired['id']]);

        $deposit = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('aof-sub-inactive')->plainTextToken])
            ->postJson('/api/v1/teller-sessions/'.$setup['sessionPublicId'].'/deposits', [
                'customer_account_public_id' => $setup['customerAccount']['public_id'],
                'amount_minor' => 10000,
                'currency' => 'XAF',
                'idempotency_key' => 'opening-fee-sub-inactive-001',
            ]);
        $deposit->assertStatus(422);
        $deposit->assertJsonValidationErrors(['operation_account_mapping']);
        self::assertSame(0, DB::table('teller_transactions')->count());
    }

    /**
     * The product screen round-trips the sub-account, and refuses one belonging
     * to another agency — the same rule the sweep enforces at the counter.
     */
    public function test_account_product_accepts_and_scopes_its_opening_fee_sub_account(): void
    {
        $setup = $this->setUpAccountWithFee(openingFeeMinor: 0);
        // The product catalogue is administered, not worked at the counter.
        $actor = $this->createUserWithRole('platform-admin');
        $income = $this->createLedgerAccount($setup['agency']['id'], 'AOF-7611-CURRENT', LedgerAccount::ACCOUNT_CLASS_PRODUITS);

        $created = $this->withApiHeaders()->actingAsSanctum($actor)
            ->postJson('/api/v1/account-products', [
                'agency_public_id' => $setup['agency']['public_id'],
                'code' => 'CC-AOF',
                'name' => 'Compte courant avec frais',
                'account_family' => 'current',
                'currency' => 'XAF',
                'opening_fee_minor' => 250,
                'opening_fee_ledger_account_public_id' => $income['public_id'],
            ]);
        $this->assertJsonSuccess($created, 201);
        $created->assertJsonPath('data.opening_fee_minor', 250);
        $created->assertJsonPath('data.opening_fee_ledger_account_public_id', $income['public_id']);
        $productPublicId = $this->requireStringJsonPath($created, 'data.public_id');

        // Another agency's income account is refused on the field itself.
        $otherAgency = $this->createAgency('AOF-OTHER');
        $foreignIncome = $this->createLedgerAccount($otherAgency['id'], 'AOF-7611-FOREIGN', LedgerAccount::ACCOUNT_CLASS_PRODUITS);
        $rejected = $this->withApiHeaders()->actingAsSanctum($actor)
            ->patchJson('/api/v1/account-products/'.$productPublicId, [
                'opening_fee_ledger_account_public_id' => $foreignIncome['public_id'],
            ]);
        $rejected->assertStatus(422);
        $rejected->assertJsonValidationErrors(['opening_fee_ledger_account_public_id']);

        // Clearing the box returns the product to the agency mapping.
        $cleared = $this->withApiHeaders()->actingAsSanctum($actor)
            ->patchJson('/api/v1/account-products/'.$productPublicId, [
                'opening_fee_ledger_account_public_id' => null,
            ]);
        $this->assertJsonSuccess($cleared);
        $cleared->assertJsonPath('data.opening_fee_ledger_account_public_id', null);
    }

    /**
     * Fail closed: a pending fee with no resolvable 7611 mapping refuses the
     * deposit. A fee that silently vanished would be worse than a blocked
     * deposit, because the counter would never learn the mapping is missing.
     */
    public function test_deposit_with_pending_fee_and_no_mapping_is_refused(): void
    {
        $setup = $this->setUpAccountWithFee(openingFeeMinor: 500, withMapping: false);
        $actor = $setup['actor'];
        $token = 'Bearer '.$actor->createToken('opening-fee-no-mapping')->plainTextToken;

        $deposit = $this->withApiHeaders(['Authorization' => $token])
            ->postJson('/api/v1/teller-sessions/'.$setup['sessionPublicId'].'/deposits', [
                'customer_account_public_id' => $setup['customerAccount']['public_id'],
                'amount_minor' => 10000,
                'currency' => 'XAF',
                'idempotency_key' => 'opening-fee-no-mapping-first',
            ]);
        $deposit->assertStatus(422);
        $deposit->assertJsonValidationErrors(['operation_account_mapping']);
        self::assertSame(0, DB::table('teller_transactions')->count());

        // Once the mapping exists, the same deposit goes through and the sweep
        // happens — the refusal was about the mapping, not the account.
        $this->createOpeningFeeMapping($setup['agency']['id'], $setup['incomeLedger']['id']);
        $retry = $this->withApiHeaders(['Authorization' => $token])
            ->postJson('/api/v1/teller-sessions/'.$setup['sessionPublicId'].'/deposits', [
                'customer_account_public_id' => $setup['customerAccount']['public_id'],
                'amount_minor' => 10000,
                'currency' => 'XAF',
                'idempotency_key' => 'opening-fee-no-mapping-first',
            ]);
        $this->assertJsonSuccess($retry, 201);
        self::assertNotNull($retry->json('data.opening_fee'));
    }

    /**
     * Reversing the first deposit annuls the sweep with it, and re-arms the fee.
     *
     * Keeping the fee was the original rule — it is owed for opening, not per
     * operation — and it could not survive contact with the database: the fee
     * comes out of the deposit's own money, so undoing the deposit left the
     * account at minus the fee and the non-overdraft trigger refused the line.
     * Every first deposit that charged a fee was unreversible.
     *
     * So the annulment is complete. The fee is not lost: the account has no
     * first transaction any more, so the next real one collects it.
     */
    public function test_reversing_the_first_deposit_annuls_the_sweep_and_rearms_the_fee(): void
    {
        $setup = $this->setUpAccountWithFee(openingFeeMinor: 500);
        $actor = $setup['actor'];
        $token = 'Bearer '.$actor->createToken('opening-fee-reversal')->plainTextToken;

        $deposit = $this->withApiHeaders(['Authorization' => $token])
            ->postJson('/api/v1/teller-sessions/'.$setup['sessionPublicId'].'/deposits', [
                'customer_account_public_id' => $setup['customerAccount']['public_id'],
                'amount_minor' => 10000,
                'currency' => 'XAF',
                'idempotency_key' => 'opening-fee-reversal-first',
            ]);
        $this->assertJsonSuccess($deposit, 201);
        self::assertNotNull($deposit->json('data.opening_fee'));
        $transactionPublicId = $this->requireStringJsonPath($deposit, 'data.teller_transaction.public_id');

        $reversal = $this->withApiHeaders(['Authorization' => $token])
            ->postJson('/api/v1/teller-transactions/'.$transactionPublicId.'/reverse');
        $this->assertJsonSuccess($reversal, 201);

        $next = $this->withApiHeaders(['Authorization' => $token])
            ->postJson('/api/v1/teller-sessions/'.$setup['sessionPublicId'].'/deposits', [
                'customer_account_public_id' => $setup['customerAccount']['public_id'],
                'amount_minor' => 10000,
                'currency' => 'XAF',
                'idempotency_key' => 'opening-fee-reversal-second',
            ]);
        $this->assertJsonSuccess($next, 201);
        // The next deposit is the account's first again, so it sweeps the fee.
        $next->assertJsonPath('data.opening_fee.amount_minor', 500);
        // Three entries carry the fee source type: the first sweep, the
        // contre-passation that annulled it, and the second sweep. A reversal
        // copies the source type of the entry it cancels.
        self::assertSame(3, DB::table('journal_entries')->where('source_type', 'account_opening_fee')->count());
        self::assertSame(1, DB::table('journal_entries')
            ->where('source_type', 'account_opening_fee')
            ->whereNotNull('reversal_of_journal_entry_id')
            ->count());
        self::assertNotNull(DB::table('customer_accounts')->where('id', $setup['customerAccount']['id'])->value('opening_fee_collected_at'));
    }

    /**
     * A contre-passation nets to nil, it does not bill the customer twice.
     *
     * Posting an entry and reversing it leaves two entries in the journal — the
     * original, flagged `reversed`, and the compensation, posted — and both are
     * on the books. Counting only `posted` dropped the original while still
     * applying the compensation, so a 10 000 deposit reversed read −10 000
     * instead of nil, and the balance no longer footed against the journal.
     *
     * Here the account returns to nil: the deposit undone and the fee sweep
     * contre-passée with it. Anything else would be a balance the non-overdraft
     * trigger refuses to let exist.
     */
    public function test_reversing_a_deposit_returns_the_account_to_nil(): void
    {
        $setup = $this->setUpAccountWithFee(openingFeeMinor: 500);
        $actor = $setup['actor'];
        $token = 'Bearer '.$actor->createToken('reversal-balance')->plainTextToken;

        $deposit = $this->withApiHeaders(['Authorization' => $token])
            ->postJson('/api/v1/teller-sessions/'.$setup['sessionPublicId'].'/deposits', [
                'customer_account_public_id' => $setup['customerAccount']['public_id'],
                'amount_minor' => 10000,
                'currency' => 'XAF',
                'idempotency_key' => 'reversal-balance-001',
            ]);
        $this->assertJsonSuccess($deposit, 201);
        $transactionPublicId = $this->requireStringJsonPath($deposit, 'data.teller_transaction.public_id');

        $balanceUrl = '/api/v1/customer-accounts/'.$setup['customerAccount']['public_id'].'/available-balance';
        $before = $this->withApiHeaders(['Authorization' => $token])->getJson($balanceUrl);
        $this->assertJsonSuccess($before);
        $before->assertJsonPath('data.accounting_balance_minor', 9500);

        $reversal = $this->withApiHeaders(['Authorization' => $token])
            ->postJson('/api/v1/teller-transactions/'.$transactionPublicId.'/reverse');
        $this->assertJsonSuccess($reversal, 201);

        $after = $this->withApiHeaders(['Authorization' => $token])->getJson($balanceUrl);
        $this->assertJsonSuccess($after);
        $after->assertJsonPath('data.accounting_balance_minor', 0);
        $after->assertJsonPath('data.real_balance_minor', 0);
        // And the fee is armed again for the account's next real operation.
        self::assertNull(DB::table('customer_accounts')->where('id', $setup['customerAccount']['id'])->value('opening_fee_collected_at'));

        // And the statement foots to the same figure, because both entries are
        // still in it: the balance générale agrees with the grand livre.
        $statement = $this->withApiHeaders(['Authorization' => $token])
            ->getJson('/api/v1/customer-accounts/'.$setup['customerAccount']['public_id'].'/statement');
        $this->assertJsonSuccess($statement);
        $credits = $statement->json('data.statement.credit_total_minor');
        $debits = $statement->json('data.statement.debit_total_minor');
        $closing = $statement->json('data.statement.closing_balance_minor');
        self::assertIsInt($credits);
        self::assertIsInt($debits);
        self::assertSame(0, $closing);
        self::assertSame($credits - $debits, $closing);
    }

    /**
     * The deposit response must stay balanced-shaped for the accounting
     * screens: the deposit journal is untouched by the sweep, which posts in
     * its own entry.
     */
    public function test_deposit_journal_remains_the_deposit_only(): void
    {
        $setup = $this->setUpAccountWithFee(openingFeeMinor: 500);
        $actor = $setup['actor'];
        $token = 'Bearer '.$actor->createToken('opening-fee-shape')->plainTextToken;

        $deposit = $this->withApiHeaders(['Authorization' => $token])
            ->postJson('/api/v1/teller-sessions/'.$setup['sessionPublicId'].'/deposits', [
                'customer_account_public_id' => $setup['customerAccount']['public_id'],
                'amount_minor' => 10000,
                'currency' => 'XAF',
                'idempotency_key' => 'opening-fee-shape-first',
            ]);
        $this->assertJsonSuccess($deposit, 201);
        $deposit->assertJsonPath('data.journal_entry.status', 'posted');
        $journalPublicId = $this->requireStringJsonPath($deposit, 'data.journal_entry.public_id');
        $journalId = DB::table('journal_entries')->where('public_id', $journalPublicId)->value('id');
        self::assertIsInt($journalId);

        self::assertSame(10000, (int) DB::table('journal_lines')->where('journal_entry_id', $journalId)->sum('debit_minor'));
        self::assertSame(10000, (int) DB::table('journal_lines')->where('journal_entry_id', $journalId)->sum('credit_minor'));
    }

    /**
     * 14/09/2026, point 3: « Nous avons testé ceci avec le compte utilisé pour
     * les tests mais il n'y a eu aucun impact sur le relevé de compte. »
     *
     * That is the retroactive guard doing its job — the account was opened
     * before the tariff existed, so it owes nothing, and switching a product's
     * fee on must never bill the book already on it. Both halves are pinned
     * here: the tariff change does not reach the open account, and the account
     * can still be brought into line deliberately.
     */
    public function test_a_later_product_tariff_does_not_reach_an_open_account_but_can_be_applied_to_it(): void
    {
        // Opened while the product charged nothing.
        $setup = $this->setUpAccountWithFee(openingFeeMinor: 0);
        $actor = $setup['actor'];
        $token = 'Bearer '.$actor->createToken('aof-retro')->plainTextToken;
        $accountPublicId = $setup['customerAccount']['public_id'];

        // The tariff is switched on afterwards. Written directly: what is on
        // trial here is the account's behaviour, not who may edit a product.
        DB::table('account_products')
            ->where('id', $setup['product']['id'])
            ->update(['opening_fee_minor' => 50000]);

        // The open account still owes nothing: no retroactive billing.
        $unchanged = $this->withApiHeaders(['Authorization' => $token])
            ->getJson('/api/v1/customer-accounts/'.$accountPublicId);
        $this->assertJsonSuccess($unchanged);
        $unchanged->assertJsonPath('data.pending_opening_fee_minor', 0);

        // And it can be brought into line on purpose, which is what was missing.
        $applied = $this->withApiHeaders(['Authorization' => $token])
            ->patchJson('/api/v1/customer-accounts/'.$accountPublicId, [
                'opening_fee_due_minor' => 50000,
            ]);
        $this->assertJsonSuccess($applied);
        $applied->assertJsonPath('data.pending_opening_fee_minor', 50000);

        // Which the very next counter operation then sweeps.
        $deposit = $this->withApiHeaders(['Authorization' => $token])
            ->postJson('/api/v1/teller-sessions/'.$setup['sessionPublicId'].'/deposits', [
                'customer_account_public_id' => $accountPublicId,
                'amount_minor' => 200000,
                'currency' => 'XAF',
                'idempotency_key' => 'aof-retro-001',
            ]);
        $this->assertJsonSuccess($deposit, 201);
        $deposit->assertJsonPath('data.opening_fee.amount_minor', 50000);
        $deposit->assertJsonPath('data.opening_fee.currency', 'XAF');
    }

    /**
     * The account drawer sends every field it holds, so the fee always arrives
     * alongside the (unchanged) product. Two things have to hold in that shape:
     * the figure the operator typed must survive, and merely re-saving an
     * account must not quietly re-apply the product's current tariff — which
     * would be the retroactive billing the snapshot exists to prevent, reached
     * through the edit form instead of the product form.
     */
    public function test_the_typed_fee_survives_a_save_that_also_carries_the_product(): void
    {
        $setup = $this->setUpAccountWithFee(openingFeeMinor: 0);
        $token = 'Bearer '.$setup['actor']->createToken('aof-both')->plainTextToken;
        $accountPublicId = $setup['customerAccount']['public_id'];

        DB::table('account_products')
            ->where('id', $setup['product']['id'])
            ->update(['opening_fee_minor' => 50000]);

        // Re-saving the account with its own product unchanged must not pull
        // the new tariff in behind the operator's back.
        $untouched = $this->withApiHeaders(['Authorization' => $token])
            ->patchJson('/api/v1/customer-accounts/'.$accountPublicId, [
                'account_product_public_id' => $setup['product']['public_id'],
                'account_title' => 'Renamed',
            ]);
        $this->assertJsonSuccess($untouched);
        $untouched->assertJsonPath('data.pending_opening_fee_minor', 0);

        // And when a figure is typed, it wins over that same product default.
        $typed = $this->withApiHeaders(['Authorization' => $token])
            ->patchJson('/api/v1/customer-accounts/'.$accountPublicId, [
                'account_product_public_id' => $setup['product']['public_id'],
                'opening_fee_due_minor' => 12500,
            ]);
        $this->assertJsonSuccess($typed);
        $typed->assertJsonPath('data.pending_opening_fee_minor', 12500);
    }

    /**
     * Once swept, the figure is what the customer was charged. Rewriting it
     * would either bill them twice or falsify the record.
     */
    public function test_a_collected_opening_fee_can_no_longer_be_changed(): void
    {
        $setup = $this->setUpAccountWithFee(openingFeeMinor: 50000);
        $actor = $setup['actor'];
        $token = 'Bearer '.$actor->createToken('aof-locked')->plainTextToken;

        $deposit = $this->withApiHeaders(['Authorization' => $token])
            ->postJson('/api/v1/teller-sessions/'.$setup['sessionPublicId'].'/deposits', [
                'customer_account_public_id' => $setup['customerAccount']['public_id'],
                'amount_minor' => 200000,
                'currency' => 'XAF',
                'idempotency_key' => 'aof-locked-001',
            ]);
        $this->assertJsonSuccess($deposit, 201);

        $refused = $this->withApiHeaders(['Authorization' => $token])
            ->patchJson('/api/v1/customer-accounts/'.$setup['customerAccount']['public_id'], [
                'opening_fee_due_minor' => 1,
            ]);
        $refused->assertStatus(422);
        $refused->assertJsonValidationErrors(['opening_fee_due_minor']);
    }

    /**
     * @return array{
     *     actor: User,
     *     agency: array{id: int, code: string, name: string, public_id: string},
     *     sessionPublicId: string,
     *     customerAccount: array{id: int, public_id: string},
     *     customerLedger: array{id: int, public_id: string},
     *     incomeLedger: array{id: int, public_id: string},
     *     product: array{id: int, public_id: string},
     *     signature: array{id: int, public_id: string},
     * }
     */
    private function setUpAccountWithFee(
        int $openingFeeMinor,
        bool $withMapping = true,
    ): array {
        $agency = $this->createAgency('AOF');
        $actor = $this->createUserWithRole('agency-manager', $agency['code'], $agency['name']);
        $teller = $this->createUserWithRole('teller', $agency['code'], $agency['name']);
        $cashLedger = $this->createLedgerAccount($agency['id'], 'AOF-CASH-TILL', LedgerAccount::ACCOUNT_CLASS_TRESORERIE_INTERBANCAIRE);
        $customerLedger = $this->createLedgerAccount($agency['id'], 'AOF-CUSTOMER-DEPOSIT', LedgerAccount::ACCOUNT_CLASS_OPERATIONS_CLIENTELE);
        $incomeLedger = $this->createLedgerAccount($agency['id'], 'AOF-7611-OPENING-FEES', LedgerAccount::ACCOUNT_CLASS_PRODUITS);

        $product = $this->createAccountProduct($agency['id'], $openingFeeMinor);
        $customerAccount = $this->createCustomerAccount($agency['id'], $customerLedger['id'], 'AOF-ACC-001', $product['id']);

        if ($withMapping) {
            $this->createOpeningFeeMapping($agency['id'], $incomeLedger['id']);
        }

        $till = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('aof-till')->plainTextToken])
            ->postJson('/api/v1/tills', [
                'code' => 'TILL-AOF',
                'name' => 'Opening Fee Till',
                'requires_denominations' => false,
                'assigned_user_public_id' => $teller->public_id,
                'ledger_account_public_id' => $cashLedger['public_id'],
                'max_withdrawal_limit_minor' => 50000,
            ]);
        $this->assertJsonSuccess($till, 201);
        $tillPublicId = $this->requireStringJsonPath($till, 'data.public_id');

        $open = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('aof-open')->plainTextToken])
            ->postJson('/api/v1/teller-sessions', [
                'till_public_id' => $tillPublicId,
                'teller_user_public_id' => $teller->public_id,
                'business_date' => now()->toDateString(),
                'opening_declaration_minor' => 20000,
                'currency' => 'XAF',
            ]);
        $this->assertJsonSuccess($open, 201);
        $sessionPublicId = $this->requireStringJsonPath($open, 'data.public_id');

        $signature = $this->createVerifiedAccountSignature($agency['id'], $customerAccount['id']);

        return [
            'actor' => $actor,
            'agency' => $agency,
            'sessionPublicId' => $sessionPublicId,
            'customerAccount' => $customerAccount,
            'customerLedger' => $customerLedger,
            'incomeLedger' => $incomeLedger,
            'product' => $product,
            'signature' => $signature,
        ];
    }

    /**
     * Funds a customer account through a manual posted journal — credited the
     * customer's ledger, debited the till's — without any teller transaction,
     * so the next counter operation is still the account's first. A posted
     * journal entry requires a poster: the actor signs both legs of the record.
     *
     * @param  array{actor: User, agency: array{id: int}, customerAccount: array{id: int}, customerLedger: array{id: int}, sessionPublicId: string}  $setup
     */
    private function plantCustomerAccountBalance(array $setup, int $amountMinor): void
    {
        // Draft first, lines after, then the walk to posted: journal lines under
        // a posted entry are immutable, so the balance must be planted the same
        // way the workflow posts anything.
        $journalId = DB::table('journal_entries')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'reference' => 'PLANT-'.Str::upper(Str::random(8)),
            'business_date' => now()->toDateString(),
            'accounting_day_id' => null,
            'posted_at' => null,
            'agency_id' => $setup['agency']['id'],
            'source_module' => 'manual_journal',
            'source_type' => 'manual_journal',
            'source_public_id' => (string) Str::ulid(),
            'status' => 'draft',
            'description' => 'Planted opening balance for test',
            'created_by_user_id' => $setup['actor']->id,
            'posted_by_user_id' => null,
            'idempotency_key' => 'plant-balance-'.$setup['customerAccount']['id'],
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('journal_lines')->insert([
            ['public_id' => (string) Str::ulid(), 'agency_id' => $setup['agency']['id'], 'journal_entry_id' => $journalId, 'ledger_account_id' => $setup['customerLedger']['id'], 'customer_account_id' => $setup['customerAccount']['id'], 'debit_minor' => 0, 'credit_minor' => $amountMinor, 'currency' => 'XAF', 'line_memo' => 'Planted opening credit', 'created_at' => now(), 'updated_at' => now()],
            ['public_id' => (string) Str::ulid(), 'agency_id' => $setup['agency']['id'], 'journal_entry_id' => $journalId, 'ledger_account_id' => $setup['customerLedger']['id'], 'customer_account_id' => null, 'debit_minor' => $amountMinor, 'credit_minor' => 0, 'currency' => 'XAF', 'line_memo' => 'Planted offsetting debit', 'created_at' => now(), 'updated_at' => now()],
        ]);

        DB::table('journal_entries')->where('id', $journalId)->update([
            'status' => 'submitted',
            'submitted_at' => now(),
            'submitted_by_user_id' => $setup['actor']->id,
            'updated_at' => now(),
        ]);
        DB::table('journal_entries')->where('id', $journalId)->update([
            'status' => 'approved',
            'reviewed_at' => now(),
            'reviewed_by_user_id' => $setup['actor']->id,
            'updated_at' => now(),
        ]);
        DB::table('journal_entries')->where('id', $journalId)->update([
            'status' => 'posted',
            'posted_at' => now(),
            'posted_by_user_id' => $setup['actor']->id,
            'updated_at' => now(),
        ]);
    }

    private function clientPublicIdOf(int $customerAccountId): string
    {
        $clientId = DB::table('customer_accounts')->where('id', $customerAccountId)->value('client_id');
        $publicId = DB::table('clients')->where('id', $clientId)->value('public_id');
        self::assertIsString($publicId);

        return $publicId;
    }

    /**
     * An active hold on the account — a guarantee deposit, a litigation block:
     * money still on the balance but spoken for.
     *
     * @param  array{customerAccount: array{id: int}}  $setup
     */
    private function placeHold(array $setup, int $amountMinor): void
    {
        DB::table('account_holds')->insert([
            'public_id' => (string) Str::ulid(),
            'customer_account_id' => $setup['customerAccount']['id'],
            'amount_minor' => $amountMinor,
            'currency' => 'XAF',
            'reason_type' => 'loan_guarantee',
            'status' => 'active',
            'placed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @return array{id: int, public_id: string}
     */
    private function createOpeningFeeMapping(int $agencyId, int $incomeLedgerId): array
    {
        $operationId = DB::table('operation_codes')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'code' => 'account_opening_fee',
            'label' => "Frais d'ouverture de compte",
            'module' => 'accounting',
            'operation_type' => 'manual_journal',
            'direction' => 'credit',
            'status' => 'active',
            'metadata' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $id = DB::table('operation_account_mappings')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'operation_code_id' => $operationId,
            'agency_id' => $agencyId,
            'debit_ledger_account_id' => null,
            'credit_ledger_account_id' => $incomeLedgerId,
            'currency' => 'XAF',
            'effective_from' => now()->subDay()->toDateString(),
            'effective_to' => null,
            'status' => 'active',
            'approval_status' => 'approved',
            'sharia_approval_required' => false,
            'sharia_approval_status' => 'not_required',
            'rules' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $mapping = DB::table('operation_account_mappings')->where('id', $id)->first(['public_id']);

        return [
            'id' => $id,
            'public_id' => is_object($mapping) && is_string($mapping->public_id) ? $mapping->public_id : '',
        ];
    }

    /**
     * @return array{id: int, public_id: string}
     */
    private function createAccountProduct(int $agencyId, int $openingFeeMinor, string $code = 'EP-AOF'): array
    {
        $id = DB::table('account_products')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'agency_id' => $agencyId,
            'ledger_account_id' => null,
            'code' => $code,
            'name' => 'Épargne avec frais d\'ouverture',
            'account_family' => 'savings',
            'minimum_balance_minor' => 0,
            'opening_fee_minor' => $openingFeeMinor,
            'currency' => 'XAF',
            'allows_overdraft' => false,
            'overdraft_limit_minor' => 0,
            'status' => 'active',
            'rules' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $product = DB::table('account_products')->where('id', $id)->first(['public_id']);

        return [
            'id' => $id,
            'public_id' => is_object($product) && is_string($product->public_id) ? $product->public_id : '',
        ];
    }

    private function createUserWithRole(string $role, ?string $agencyCode = null, ?string $agencyName = null): User
    {
        $agency = null;
        if ($agencyCode !== null) {
            $agency = DB::table('agencies')
                ->where('code', $agencyCode)
                ->first(['id', 'code', 'name']);

            if ($agency === null) {
                $agency = (object) $this->createAgency($agencyCode, $agencyName);
            }
        }

        $user = User::factory()->createOne([
            'status' => User::STATUS_ACTIVE,
            'phone_verified_at' => now(),
            'agency_id' => $agency->id ?? null,
            'agency_code' => $agency->code ?? null,
            'agency_name' => $agency->name ?? null,
        ]);

        $user->assignRole($role);

        if ($agency !== null) {
            DB::table('staff_agency_assignments')->insert([
                'public_id' => (string) Str::ulid(),
                'user_id' => $user->id,
                'agency_id' => $agency->id,
                'role_at_agency' => $role,
                'starts_on' => now()->toDateString(),
                'is_primary' => true,
                'status' => 'active',
            ]);
        }

        return $user;
    }

    /**
     * @return array{id: int, code: string, name: string, public_id: string}
     */
    private function createAgency(string $code, ?string $name = null): array
    {
        $name ??= $code.' Agency';
        $id = DB::table('agencies')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'code' => $code,
            'name' => $name,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $agency = DB::table('agencies')->where('id', $id)->first(['public_id']);

        return [
            'id' => $id,
            'code' => $code,
            'name' => $name,
            'public_id' => is_object($agency) && is_string($agency->public_id) ? $agency->public_id : '',
        ];
    }

    /**
     * @return array{id: int, public_id: string}
     */
    private function createLedgerAccount(int $agencyId, string $code, string $accountClass): array
    {
        $id = DB::table('ledger_accounts')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'agency_id' => $agencyId,
            'code' => $code,
            'name' => $code.' Ledger',
            'account_class' => $accountClass,
            'account_type' => null,
            'parent_account_id' => null,
            'normal_balance_side' => $accountClass === LedgerAccount::ACCOUNT_CLASS_TRESORERIE_INTERBANCAIRE
                || $accountClass === LedgerAccount::ACCOUNT_CLASS_CHARGES
                    ? LedgerAccount::NORMAL_BALANCE_DEBIT
                    : LedgerAccount::NORMAL_BALANCE_CREDIT,
            'status' => LedgerAccount::STATUS_ACTIVE,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $ledger = DB::table('ledger_accounts')->where('id', $id)->first(['public_id']);

        return [
            'id' => $id,
            'public_id' => is_object($ledger) && is_string($ledger->public_id) ? $ledger->public_id : '',
        ];
    }

    /**
     * @return array{id: int, public_id: string}
     */
    private function createCustomerAccount(int $agencyId, int $ledgerAccountId, string $accountNumber, int $accountProductId, ?int $openingFeeDueMinor = null): array
    {
        // The tariff snapshot the opening flow takes; defaults to the product's
        // current figure, which is what opening the account today would copy.
        if ($openingFeeDueMinor === null) {
            $productFee = DB::table('account_products')->where('id', $accountProductId)->value('opening_fee_minor');
            $openingFeeDueMinor = is_numeric($productFee) ? (int) $productFee : 0;
        }
        $clientId = DB::table('clients')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'agency_id' => $agencyId,
            'client_reference' => 'CL-'.$accountNumber,
            'first_name' => 'Aof',
            'last_name' => 'Depositor',
            'phone_number' => '+237650000001',
            'email' => 'aof-'.$accountNumber.'@example.test',
            'status' => 'active',
            'kyc_status' => 'verified',
            'onboarded_on' => now()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $accountId = DB::table('customer_accounts')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'client_id' => $clientId,
            'agency_id' => $agencyId,
            'ledger_account_id' => $ledgerAccountId,
            'account_product_id' => $accountProductId,
            'account_number' => $accountNumber,
            'account_title' => 'Aof Depositor',
            'account_type' => 'ordinary_savings',
            'currency' => 'XAF',
            'unavailable_amount_minor' => 0,
            'opening_fee_due_minor' => $openingFeeDueMinor,
            'opened_on' => now()->toDateString(),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $account = DB::table('customer_accounts')->where('id', $accountId)->first(['public_id']);

        return [
            'id' => $accountId,
            'public_id' => is_object($account) && is_string($account->public_id) ? $account->public_id : '',
        ];
    }

    /**
     * @return array{id: int, public_id: string}
     */
    private function createVerifiedAccountSignature(int $agencyId, int $customerAccountId): array
    {
        $account = DB::table('customer_accounts')->where('id', $customerAccountId)->first(['client_id']);
        self::assertIsObject($account);
        self::assertIsInt($account->client_id);

        $documentId = DB::table('documents')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'agency_id' => $agencyId,
            'category' => 'account_signature',
            'title' => 'Verified account signature',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $signatureId = DB::table('customer_account_signatures')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'agency_id' => $agencyId,
            'customer_account_id' => $customerAccountId,
            'client_id' => $account->client_id,
            'document_id' => $documentId,
            'client_proxy_id' => null,
            'signature_type' => 'primary_holder',
            'status' => 'active',
            'verified_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $signature = DB::table('customer_account_signatures')->where('id', $signatureId)->first(['public_id']);

        return [
            'id' => $signatureId,
            'public_id' => is_object($signature) && is_string($signature->public_id) ? $signature->public_id : '',
        ];
    }

    private function requireStringJsonPath(mixed $response, string $path): string
    {
        $value = $response instanceof TestResponse ? $response->json($path) : null;
        self::assertIsString($value);

        return $value;
    }
}
