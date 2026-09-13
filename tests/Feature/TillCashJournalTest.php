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
 * The « brouillard de caisse » the accounting team asked for: every cash
 * operation of one till, line by line, with the ten columns of the printed
 * journal — n° d'ordre, pièce de caisse, date, n° de compte, libellé, montant
 * débité, montant crédité, solde progressif, code guichet, caissier.
 *
 * The solde progressif is the till's truth: opening declaration plus the
 * movement of each settled operation's own journal entry on the till's ledger
 * account, accumulated in cash order across sessions and computed before any
 * filter or pagination is applied.
 */
final class TillCashJournalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    /**
     * The core flow: a session opened with 20 000, a 10 000 deposit, a 3 000
     * withdrawal, and a manual journal adding 2 000 — the brouillard shows each
     * on its own line with the running balance the close validation would
     * compute, and the columns carry real names and codes, never ULIDs.
     */
    public function test_cash_journal_prints_the_ten_columns_with_a_true_running_balance(): void
    {
        $setup = $this->setUpJournal();
        $actor = $setup['actor'];

        $response = $this->withApiHeaders()->actingAsSanctum($actor)
            ->getJson('/api/v1/tills/'.$setup['tillPublicId'].'/cash-journal');
        $this->assertJsonSuccess($response);
        $response->assertJsonPath('data.till.code', 'TILL-BRD');
        // The journal opens with the session's declared drawer: 20000.
        $response->assertJsonPath('data.opening_balance_minor', 20000);

        // Every line prints its ordinal, the caisse and its caissier by name.
        $response->assertJsonPath('data.lines.0.sequence_number', 1);
        $response->assertJsonPath('data.lines.1.sequence_number', 2);
        $response->assertJsonPath('data.lines.0.till_code', 'TILL-BRD');
        $response->assertJsonPath('data.lines.1.till_code', 'TILL-BRD');
        $response->assertJsonPath('data.lines.0.teller_name', $setup['teller']->name);
        $response->assertJsonPath('data.lines.1.teller_name', $setup['teller']->name);

        // The deposit carries the account it credited and its ledger code.
        $response->assertJsonPath('data.lines.0.customer_account_number', 'BRD-ACC-001');
        $response->assertJsonPath('data.lines.0.ledger_account_code', 'BRD-CUSTOMER-DEPOSIT');
        $response->assertJsonPath('data.lines.0.cash_debit_minor', 10000);
        $response->assertJsonPath('data.lines.0.cash_credit_minor', 0);
        $response->assertJsonPath('data.lines.0.running_balance_minor', 30000);

        $response->assertJsonPath('data.lines.1.cash_credit_minor', 3000);
        $response->assertJsonPath('data.lines.1.cash_debit_minor', 0);
        $response->assertJsonPath('data.lines.1.running_balance_minor', 27000);

        // The invariant that makes the sheet usable: it closes on the same
        // figure the session close validates the counted drawer against.
        $session = $this->withApiHeaders()->actingAsSanctum($actor)
            ->getJson('/api/v1/teller-sessions/'.$setup['sessionPublicId']);
        $this->assertJsonSuccess($session);
        self::assertSame(
            $session->json('data.summary.expected_cash_balance_minor'),
            $response->json('data.lines.1.running_balance_minor'),
        );
    }

    /**
     * The balance the printed brouillard opens with is the balance before the
     * first filtered row, not zero.
     */
    public function test_date_filter_carries_the_opening_balance_of_the_window(): void
    {
        $setup = $this->setUpJournalWithSecondDay();
        $actor = $setup['actor'];

        $response = $this->withApiHeaders()->actingAsSanctum($actor)
            ->getJson('/api/v1/tills/'.$setup['tillPublicId'].'/cash-journal?filter[transaction_date_from]='.$setup['secondDay']);
        $this->assertJsonSuccess($response);
        // The second day's till was declared fresh at 5000: the journal's
        // opening balance is that declaration, not day one's closing — and the
        // running balance restarts from it.
        $response->assertJsonPath('data.opening_balance_minor', 5000);
        $response->assertJsonPath('data.lines.0.sequence_number', 1);
        $response->assertJsonPath('data.lines.0.running_balance_minor', 10000);
    }

    /**
     * A libellé the teller did not write comes back empty, not filled with the
     * API's generated English description — the screen names the operation in
     * the reader's language, and a French brouillard cannot print
     * « Cash deposit BRD-ACC-001 » in its Libellés column.
     */
    public function test_blank_libelle_is_not_backfilled_with_the_generated_description(): void
    {
        $setup = $this->setUpJournal();

        // A deposit with no description of its own.
        $deposit = $this->withApiHeaders()->actingAsSanctum($setup['actor'])
            ->postJson('/api/v1/teller-sessions/'.$setup['sessionPublicId'].'/deposits', [
                'customer_account_public_id' => $setup['customerAccount']['public_id'],
                'amount_minor' => 1000,
                'currency' => 'XAF',
                'idempotency_key' => 'brd-no-description-001',
            ]);
        $this->assertJsonSuccess($deposit, 201);

        $response = $this->withApiHeaders()->actingAsSanctum($setup['actor'])
            ->getJson('/api/v1/tills/'.$setup['tillPublicId'].'/cash-journal');
        $this->assertJsonSuccess($response);

        // Line 3 is the description-less deposit; lines 1 and 2 carry the ones
        // the setup wrote.
        $response->assertJsonPath('data.lines.0.label', 'Versement espèces');
        $response->assertJsonPath('data.lines.2.label', null);
        self::assertStringContainsString(
            'Cash deposit',
            $this->requireStringJsonPath($response, 'data.lines.2.journal_description'),
        );
    }

    /**
     * A window that matches nothing opens at the position preceding it, not at
     * the till's current drawer: a sheet printed for a period before the till
     * ever traded opens at nil.
     */
    public function test_empty_window_opens_at_the_position_preceding_it(): void
    {
        $setup = $this->setUpJournal();
        $before = now()->subYear()->toDateString();

        $response = $this->withApiHeaders()->actingAsSanctum($setup['actor'])
            ->getJson('/api/v1/tills/'.$setup['tillPublicId'].'/cash-journal'
                .'?filter[transaction_date_from]='.$before
                .'&filter[transaction_date_to]='.$before);
        $this->assertJsonSuccess($response);
        self::assertSame(0, $response->json('meta.pagination.total'));
        $response->assertJsonPath('data.opening_balance_minor', 0);

        // And a window opening after everything carries the position it
        // inherits — the till's last running balance, not zero.
        $after = now()->addYear()->toDateString();
        $later = $this->withApiHeaders()->actingAsSanctum($setup['actor'])
            ->getJson('/api/v1/tills/'.$setup['tillPublicId'].'/cash-journal?filter[transaction_date_from]='.$after);
        $this->assertJsonSuccess($later);
        self::assertSame(0, $later->json('meta.pagination.total'));
        $later->assertJsonPath('data.opening_balance_minor', 27000);
    }

    /**
     * An annulled pair prints in full and moves nothing. Both the reversed line
     * and the extourne that cancels it carry their amounts — the brouillard is a
     * record — while the solde progressif walks straight past them.
     *
     * The figure that settles it is the session's own expected cash position:
     * the close workflow validates the counted drawer against it, so a
     * brouillard that ended anywhere else would be reconciled against the cash
     * and lose. Taking the extourne's movement while the original is already
     * excluded subtracted the same 10 000 twice and ended at 17 000 — 10 000
     * short of a drawer holding 27 000.
     */
    public function test_an_annulled_pair_prints_in_full_and_moves_nothing(): void
    {
        $setup = $this->setUpJournalWithReversal();
        $actor = $setup['actor'];

        $response = $this->withApiHeaders()->actingAsSanctum($actor)
            ->getJson('/api/v1/tills/'.$setup['tillPublicId'].'/cash-journal');
        $this->assertJsonSuccess($response);

        // Line 1: the funded deposit. Line 2: the funded withdrawal.
        $response->assertJsonPath('data.lines.0.status', 'posted');
        $response->assertJsonPath('data.lines.0.running_balance_minor', 30000);
        $response->assertJsonPath('data.lines.1.running_balance_minor', 27000);

        // Line 3: the reversed deposit. Line 4: the extourne. Both printed with
        // their amounts, neither moving the balance.
        $response->assertJsonPath('data.lines.2.status', 'reversed');
        $response->assertJsonPath('data.lines.2.cash_debit_minor', 10000);
        $response->assertJsonPath('data.lines.2.cash_credit_minor', 0);
        $response->assertJsonPath('data.lines.2.running_balance_minor', 27000);

        $response->assertJsonPath('data.lines.3.transaction_type', 'cash_reversal');
        $response->assertJsonPath('data.lines.3.cash_debit_minor', 0);
        $response->assertJsonPath('data.lines.3.cash_credit_minor', 10000);
        $response->assertJsonPath('data.lines.3.running_balance_minor', 27000);

        // And the sheet closes exactly where the till close says the drawer is.
        $session = $this->withApiHeaders()->actingAsSanctum($actor)
            ->getJson('/api/v1/teller-sessions/'.$setup['sessionPublicId']);
        $this->assertJsonSuccess($session);
        self::assertSame(
            $session->json('data.summary.expected_cash_balance_minor'),
            $response->json('data.lines.3.running_balance_minor'),
        );
    }

    /**
     * Pagination continues the solde progressif instead of restarting it: the
     * balance is a property of the till, computed over the whole history
     * before the page is cut.
     */
    public function test_running_balance_continues_across_pages(): void
    {
        $setup = $this->setUpJournal();
        $actor = $setup['actor'];

        $pageTwo = $this->withApiHeaders()->actingAsSanctum($actor)
            ->getJson('/api/v1/tills/'.$setup['tillPublicId'].'/cash-journal?per_page=1&page=2');
        $this->assertJsonSuccess($pageTwo);
        self::assertSame(2, $pageTwo->json('meta.pagination.total'));
        self::assertSame(2, $pageTwo->json('meta.pagination.last_page'));
        $pageTwo->assertJsonPath('data.lines.0.sequence_number', 2);
        $pageTwo->assertJsonPath('data.lines.0.running_balance_minor', 27000);
    }

    /**
     * An agency manager of another agency cannot read the journal at all, while
     * a plain teller reads the till scoped to the sessions they ran themselves:
     * the teller who worked the till sees their lines, a colleague who never
     * touched it sees an empty brouillard — not a 403, which would lock every
     * teller out of every shared till.
     */
    public function test_cash_journal_is_scoped_to_the_agency_and_the_teller(): void
    {
        $setup = $this->setUpJournal();
        $outsider = $this->createUserWithRole('agency-manager', 'BRD-OTHER', 'Other Agency');

        $crossAgency = $this->withApiHeaders()->actingAsSanctum($outsider)
            ->getJson('/api/v1/tills/'.$setup['tillPublicId'].'/cash-journal');
        $crossAgency->assertStatus(403);

        // The teller who ran the sessions reads the whole two-line journal.
        $ownTeller = $this->withApiHeaders()->actingAsSanctum($setup['teller'])
            ->getJson('/api/v1/tills/'.$setup['tillPublicId'].'/cash-journal');
        $this->assertJsonSuccess($ownTeller);
        self::assertSame(2, $ownTeller->json('meta.pagination.total'));
        $ownTeller->assertJsonPath('data.lines.0.running_balance_minor', 30000);

        // A colleague of the same agency reads the same till and finds nothing
        // of their own: the journal is scoped, not refused.
        $otherTeller = $this->withApiHeaders()
            ->actingAsSanctum($this->createUserWithRole('teller', $setup['agencyCode'], $setup['agencyName']))
            ->getJson('/api/v1/tills/'.$setup['tillPublicId'].'/cash-journal');
        $this->assertJsonSuccess($otherTeller);
        self::assertSame(0, $otherTeller->json('meta.pagination.total'));
        self::assertSame([], $otherTeller->json('data.lines'));
        $otherTeller->assertJsonPath('data.opening_balance_minor', 0);
    }

    /**
     * The brouillard prints the guichet (branch) code and the caisse code as
     * two distinct things, so « code guichet » cannot be read as the till.
     */
    public function test_cash_journal_carries_both_the_guichet_and_the_till_code(): void
    {
        $setup = $this->setUpJournal();

        $response = $this->withApiHeaders()->actingAsSanctum($setup['actor'])
            ->getJson('/api/v1/tills/'.$setup['tillPublicId'].'/cash-journal');
        $this->assertJsonSuccess($response);

        $response->assertJsonPath('data.till.agency_code', 'BRD');
        $response->assertJsonPath('data.lines.0.agency_code', 'BRD');
        $response->assertJsonPath('data.lines.0.till_code', 'TILL-BRD');
    }

    /**
     * Unknown filters are refused rather than silently ignored, as on every
     * other cash list.
     */
    public function test_unknown_filters_are_refused(): void
    {
        $setup = $this->setUpJournal();

        $response = $this->withApiHeaders()->actingAsSanctum($setup['actor'])
            ->getJson('/api/v1/tills/'.$setup['tillPublicId'].'/cash-journal?filter[customer]=x');
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['filter']);
    }

    /**
     * @return array{actor: User, teller: User, agencyCode: string, agencyName: string, tillPublicId: string, sessionPublicId: string, customerAccount: array{id: int, public_id: string}, signature: array{id: int, public_id: string}}
     */
    private function setUpJournal(): array
    {
        $agency = $this->createAgency('BRD', 'Brouillard Agency');
        $actor = $this->createUserWithRole('agency-manager', $agency['code'], $agency['name']);
        $teller = $this->createUserWithRole('teller', $agency['code'], $agency['name']);
        $cashLedger = $this->createLedgerAccount($agency['id'], 'BRD-CASH-TILL', LedgerAccount::ACCOUNT_CLASS_TRESORERIE_INTERBANCAIRE);
        $customerLedger = $this->createLedgerAccount($agency['id'], 'BRD-CUSTOMER-DEPOSIT', LedgerAccount::ACCOUNT_CLASS_OPERATIONS_CLIENTELE);
        $customerAccount = $this->createCustomerAccount($agency['id'], $customerLedger['id'], 'BRD-ACC-001');

        $till = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('brd-till')->plainTextToken])
            ->postJson('/api/v1/tills', [
                'code' => 'TILL-BRD',
                'name' => 'Brouillard Till',
                'requires_denominations' => false,
                'assigned_user_public_id' => $teller->public_id,
                'ledger_account_public_id' => $cashLedger['public_id'],
            ]);
        $this->assertJsonSuccess($till, 201);
        $tillPublicId = $this->requireStringJsonPath($till, 'data.public_id');

        $open = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('brd-open')->plainTextToken])
            ->postJson('/api/v1/teller-sessions', [
                'till_public_id' => $tillPublicId,
                'teller_user_public_id' => $teller->public_id,
                'business_date' => now()->toDateString(),
                'opening_declaration_minor' => 20000,
                'currency' => 'XAF',
            ]);
        $this->assertJsonSuccess($open, 201);
        $sessionPublicId = $this->requireStringJsonPath($open, 'data.public_id');

        $deposit = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('brd-deposit')->plainTextToken])
            ->postJson('/api/v1/teller-sessions/'.$sessionPublicId.'/deposits', [
                'customer_account_public_id' => $customerAccount['public_id'],
                'amount_minor' => 10000,
                'currency' => 'XAF',
                'idempotency_key' => 'brd-deposit-001',
                'description' => 'Versement espèces',
            ]);
        $this->assertJsonSuccess($deposit, 201);

        $signature = $this->createVerifiedAccountSignature($agency['id'], $customerAccount['id']);
        $withdrawal = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('brd-withdrawal')->plainTextToken])
            ->postJson('/api/v1/teller-sessions/'.$sessionPublicId.'/withdrawals', [
                'customer_account_public_id' => $customerAccount['public_id'],
                'amount_minor' => 3000,
                'currency' => 'XAF',
                'signature_public_id' => $signature['public_id'],
                'signature_verification_method' => 'visual_match',
                'idempotency_key' => 'brd-withdrawal-001',
                'description' => 'Retrait espèces',
            ]);
        $this->assertJsonSuccess($withdrawal, 201);

        return [
            'actor' => $actor,
            'teller' => $teller,
            'agencyCode' => $agency['code'],
            'agencyName' => $agency['name'],
            'tillPublicId' => $tillPublicId,
            'sessionPublicId' => $sessionPublicId,
            'customerAccount' => $customerAccount,
            'signature' => $signature,
        ];
    }

    /**
     * @return array{actor: User, teller: User, agencyCode: string, agencyName: string, tillPublicId: string, secondDay: string}
     */
    private function setUpJournalWithSecondDay(): array
    {
        $setup = $this->setUpJournal();
        $actor = $setup['actor'];
        $secondDay = now()->addDay()->toDateString();

        // Day one is over: the session closes and frees the till. The close
        // workflow has its own tests; the brouillard only reads the history.
        $tillId = DB::table('tills')->where('public_id', $setup['tillPublicId'])->value('id');
        self::assertIsInt($tillId);
        DB::table('teller_sessions')->where('public_id', $setup['sessionPublicId'])->update([
            'status' => 'closed',
            'closed_at' => now(),
            'closing_declaration_minor' => 27000,
            'updated_at' => now(),
        ]);
        DB::table('tills')->where('id', $tillId)->update([
            'daily_state' => 'closed',
            'last_closing_balance_minor' => 27000,
            'last_closing_at' => now(),
            'updated_at' => now(),
        ]);

        $secondSession = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('brd-open-2')->plainTextToken])
            ->postJson('/api/v1/teller-sessions', [
                'till_public_id' => $setup['tillPublicId'],
                'teller_user_public_id' => $setup['teller']->public_id,
                'business_date' => $secondDay,
                'opening_declaration_minor' => 5000,
                'currency' => 'XAF',
            ]);
        $this->assertJsonSuccess($secondSession, 201);
        $secondSessionPublicId = $this->requireStringJsonPath($secondSession, 'data.public_id');

        $deposit = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('brd-deposit-2')->plainTextToken])
            ->postJson('/api/v1/teller-sessions/'.$secondSessionPublicId.'/deposits', [
                'customer_account_public_id' => $setup['customerAccount']['public_id'],
                'amount_minor' => 5000,
                'currency' => 'XAF',
                'idempotency_key' => 'brd-deposit-002',
                'description' => 'Versement jour 2',
            ]);
        $this->assertJsonSuccess($deposit, 201);

        return [
            'actor' => $setup['actor'],
            'teller' => $setup['teller'],
            'agencyCode' => $setup['agencyCode'],
            'agencyName' => $setup['agencyName'],
            'tillPublicId' => $setup['tillPublicId'],
            'sessionPublicId' => $setup['sessionPublicId'],
            'customerAccount' => $setup['customerAccount'],
            'signature' => $setup['signature'],
            'secondDay' => $secondDay,
        ];
    }

    /**
     * @return array{actor: User, tillPublicId: string, sessionPublicId: string}
     */
    private function setUpJournalWithReversal(): array
    {
        $setup = $this->setUpJournal();
        $actor = $setup['actor'];
        $token = 'Bearer '.$actor->createToken('brd-reverse')->plainTextToken;

        $deposit = $this->withApiHeaders(['Authorization' => $token])
            ->postJson('/api/v1/teller-sessions/'.$setup['sessionPublicId'].'/deposits', [
                'customer_account_public_id' => $setup['customerAccount']['public_id'],
                'amount_minor' => 10000,
                'currency' => 'XAF',
                'idempotency_key' => 'brd-reversal-deposit-001',
                'description' => 'Versement à annuler',
            ]);
        $this->assertJsonSuccess($deposit, 201);
        $transactionPublicId = $this->requireStringJsonPath($deposit, 'data.teller_transaction.public_id');

        $reversal = $this->withApiHeaders(['Authorization' => $token])
            ->postJson('/api/v1/teller-transactions/'.$transactionPublicId.'/reverse');
        $this->assertJsonSuccess($reversal, 201);

        return [
            'actor' => $actor,
            'tillPublicId' => $setup['tillPublicId'],
            'sessionPublicId' => $setup['sessionPublicId'],
        ];
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

    private function createUserWithRole(string $role, string $agencyCode, string $agencyName): User
    {
        $agency = DB::table('agencies')->where('code', $agencyCode)->first(['id', 'code', 'name']);
        if ($agency === null) {
            $created = $this->createAgency($agencyCode, $agencyName);
            $agency = DB::table('agencies')->where('code', $agencyCode)->first(['id', 'code', 'name']);
            self::assertIsObject($agency);
            self::assertSame($created['id'], (int) $agency->id);
        }

        $user = User::factory()->createOne([
            'status' => User::STATUS_ACTIVE,
            'phone_verified_at' => now(),
            'agency_id' => $agency->id,
            'agency_code' => $agency->code,
            'agency_name' => $agency->name,
        ]);

        $user->assignRole($role);

        DB::table('staff_agency_assignments')->insert([
            'public_id' => (string) Str::ulid(),
            'user_id' => $user->id,
            'agency_id' => $agency->id,
            'role_at_agency' => $role,
            'starts_on' => now()->toDateString(),
            'is_primary' => true,
            'status' => 'active',
        ]);

        return $user;
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
    private function createCustomerAccount(int $agencyId, int $ledgerAccountId, string $accountNumber): array
    {
        $clientId = DB::table('clients')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'agency_id' => $agencyId,
            'client_reference' => 'CL-'.$accountNumber,
            'first_name' => 'Brd',
            'last_name' => 'Depositor',
            'phone_number' => '+237650000002',
            'email' => 'brd-'.$accountNumber.'@example.test',
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
            'account_number' => $accountNumber,
            'account_title' => 'Brd Depositor',
            'account_type' => 'ordinary_savings',
            'currency' => 'XAF',
            'unavailable_amount_minor' => 0,
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
