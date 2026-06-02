<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\LedgerAccount;
use App\Models\StaffAgencyAssignment;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * FBI2-027 — Teller dashboard real-data contract closure.
 *
 * This is the integration-closure ticket: it proves every teller dashboard
 * section can be powered by real backend endpoints (no placeholder/demo data),
 * that a default teller is scoped to their own agency, and that each required
 * narrow permission gates exactly its endpoint with a clear 403 instead of a
 * 500 or silently masked data.
 *
 * The dashboard fetch sequence under contract:
 *   - GET /teller-sessions
 *   - GET /teller-transactions
 *   - GET /notifications
 *   - GET /clients
 *   - GET /customer-accounts
 *   - GET /customer-accounts/{id}/available-balance
 */
final class TellerDashboardContractTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_default_teller_loads_full_dashboard_from_real_endpoints_for_current_agency(): void
    {
        $ctx = $this->bootstrapAgencyDashboard('DASH-A');
        $teller = $ctx['teller'];

        // 1. Teller sessions — own session with server-calculated summary.
        $sessions = $this->actingAsTeller($teller)->getJson('/api/v1/teller-sessions');
        $this->assertJsonSuccess($sessions);
        $sessions->assertJsonPath('data.teller_sessions.0.public_id', $ctx['session_public_id']);
        $sessions->assertJsonPath('data.teller_sessions.0.summary.deposits_total_minor', 10000);
        $sessions->assertJsonPath('data.teller_sessions.0.summary.expected_cash_balance_minor', 15000);
        $sessions->assertJsonPath('data.teller_sessions.0.summary.transaction_count', 1);
        self::assertSame([$ctx['session_public_id']], $this->pluck($sessions, 'data.teller_sessions', 'public_id'));
        $sessions->assertJsonStructure(['meta' => ['pagination' => ['current_page', 'per_page', 'total', 'last_page']]]);

        // 2. Teller transactions — recent operations for the teller's own session.
        $transactions = $this->actingAsTeller($teller)->getJson('/api/v1/teller-transactions');
        $this->assertJsonSuccess($transactions);
        self::assertContains($ctx['transaction_public_id'], $this->pluck($transactions, 'data.teller_transactions', 'public_id'));
        $transactions->assertJsonStructure(['meta' => ['pagination' => ['current_page', 'per_page', 'total', 'last_page']]]);

        // 3. Notifications — agency operational feed (real rows, never demo data).
        $notifications = $this->actingAsTeller($teller)->getJson('/api/v1/notifications');
        $this->assertJsonSuccess($notifications);
        $categories = $this->pluck($notifications, 'data.notifications', 'category');
        self::assertContains('cash_session_opened', $categories);
        self::assertContains('cash_deposit_posted', $categories);

        // 4. Clients — same-agency client with operational identity visible, sensitive PII still hidden.
        $clients = $this->actingAsTeller($teller)->getJson('/api/v1/clients');
        $this->assertJsonSuccess($clients);
        self::assertContains($ctx['client_public_id'], $this->pluck($clients, 'data.clients', 'public_id'));
        $clients->assertJsonPath('data.clients.0.identity_redacted', false);
        $clients->assertJsonPath('data.clients.0.sensitive_pii_redacted', true);
        $clients->assertJsonPath('data.clients.0.first_name', 'Cash');
        $clients->assertJsonPath('data.clients.0.father_name', fn (mixed $value): bool => $value !== 'Sponsor');

        // 5. Customer accounts — same-agency account list.
        $accounts = $this->actingAsTeller($teller)->getJson('/api/v1/customer-accounts');
        $this->assertJsonSuccess($accounts);
        self::assertContains($ctx['account_public_id'], $this->pluck($accounts, 'data.customer_accounts', 'public_id'));

        // 6. Available balance — needed by deposit/withdrawal screens.
        $balance = $this->actingAsTeller($teller)
            ->getJson('/api/v1/customer-accounts/'.$ctx['account_public_id'].'/available-balance?currency=XAF');
        $this->assertJsonSuccess($balance);
        $balance->assertJsonStructure(['data' => [
            'currency',
            'accounting_balance_minor',
            'minimum_balance_minor',
            'unavailable_amount_minor',
            'active_hold_amount_minor',
            'available_balance_minor',
        ]]);
        $balance->assertJsonPath('data.available_balance_minor', 10000);
    }

    public function test_default_teller_cannot_access_cross_agency_dashboard_data(): void
    {
        $a = $this->bootstrapAgencyDashboard('XAGENCY-A');
        $b = $this->bootstrapAgencyDashboard('XAGENCY-B');
        $teller = $a['teller'];

        // Lists return agency A rows but never leak agency B rows. Asserting A is
        // present guards against a vacuous pass where an unrelated empty result
        // would also satisfy the "B absent" assertion.
        $sessions = $this->actingAsTeller($teller)->getJson('/api/v1/teller-sessions');
        $sessionIds = $this->pluck($sessions, 'data.teller_sessions', 'public_id');
        self::assertContains($a['session_public_id'], $sessionIds);
        self::assertNotContains($b['session_public_id'], $sessionIds);

        $transactions = $this->actingAsTeller($teller)->getJson('/api/v1/teller-transactions');
        $transactionIds = $this->pluck($transactions, 'data.teller_transactions', 'public_id');
        self::assertContains($a['transaction_public_id'], $transactionIds);
        self::assertNotContains($b['transaction_public_id'], $transactionIds);

        $accounts = $this->actingAsTeller($teller)->getJson('/api/v1/customer-accounts');
        $accountIds = $this->pluck($accounts, 'data.customer_accounts', 'public_id');
        self::assertContains($a['account_public_id'], $accountIds);
        self::assertNotContains($b['account_public_id'], $accountIds);

        $clients = $this->actingAsTeller($teller)->getJson('/api/v1/clients');
        $clientIds = $this->pluck($clients, 'data.clients', 'public_id');
        self::assertContains($a['client_public_id'], $clientIds);
        self::assertNotContains($b['client_public_id'], $clientIds);

        $notifications = $this->actingAsTeller($teller)->getJson('/api/v1/notifications');
        $notificationAgencies = $this->pluck($notifications, 'data.notifications', 'agency_public_id');
        self::assertContains($a['agency']['public_id'], $notificationAgencies);
        self::assertNotContains($b['agency']['public_id'], $notificationAgencies);

        // Direct cross-agency reads are forbidden, not silently masked.
        $this->actingAsTeller($teller)->getJson('/api/v1/teller-sessions/'.$b['session_public_id'])->assertForbidden();
        $this->actingAsTeller($teller)->getJson('/api/v1/clients/'.$b['client_public_id'])->assertForbidden();
        $this->actingAsTeller($teller)->getJson('/api/v1/customer-accounts/'.$b['account_public_id'])->assertForbidden();
        $this->actingAsTeller($teller)
            ->getJson('/api/v1/customer-accounts/'.$b['account_public_id'].'/available-balance?currency=XAF')
            ->assertForbidden();
    }

    public function test_each_required_narrow_permission_gates_its_dashboard_endpoint_with_403(): void
    {
        $ctx = $this->bootstrapAgencyDashboard('PERM-A');
        $tellerPermissions = $this->defaultTellerPermissions();

        $balanceEndpoint = '/api/v1/customer-accounts/'.$ctx['account_public_id'].'/available-balance?currency=XAF';

        /** @var array<string, string> $guarded */
        $guarded = [
            'cash.sessions.view' => '/api/v1/teller-sessions',
            'cash.transactions.view' => '/api/v1/teller-transactions',
            'crm.clients.view' => '/api/v1/clients',
            'customer.accounts.view' => '/api/v1/customer-accounts',
            'customer.accounts.balance.view' => $balanceEndpoint,
        ];

        // Positive control: the same direct-permission fixture WITH the full teller
        // permission set must succeed on every endpoint. This proves the fixture
        // (permission grant + current-agency scope) is sound, so each 403 below is
        // attributable to the single removed permission rather than a broken actor.
        $fullyPermitted = $this->createScopedUser($ctx['agency'], $tellerPermissions);
        foreach ($guarded as $endpoint) {
            $this->actingAsTeller($fullyPermitted)->getJson($endpoint)->assertOk();
        }

        foreach ($guarded as $permission => $endpoint) {
            $withoutPermission = array_values(array_filter(
                $tellerPermissions,
                static fn (string $name): bool => $name !== $permission,
            ));

            $user = $this->createScopedUser($ctx['agency'], $withoutPermission);

            $response = $this->actingAsTeller($user)->getJson($endpoint);

            // A clear 403 on the specific endpoint — not a 500 and not masked data.
            // assertStatus(403) proves it is precisely Forbidden (not a 500) and the
            // envelope reports failure rather than returning silently masked rows.
            $response->assertStatus(403);
            $response->assertJsonPath('success', false);
        }
    }

    public function test_operational_identity_permission_gates_client_identity_without_blocking_the_list(): void
    {
        $ctx = $this->bootstrapAgencyDashboard('IDENT-A');
        $tellerPermissions = $this->defaultTellerPermissions();

        $withoutIdentity = array_values(array_filter(
            $tellerPermissions,
            static fn (string $name): bool => $name !== 'crm.clients.identity.view',
        ));
        $user = $this->createScopedUser($ctx['agency'], $withoutIdentity);

        // The list still resolves (200) but operational identity is masked, not a 403/500.
        $clients = $this->actingAsTeller($user)->getJson('/api/v1/clients');
        $this->assertJsonSuccess($clients);
        $clients->assertJsonPath('data.clients.0.identity_redacted', true);
        $clients->assertJsonPath('data.clients.0.sensitive_pii_redacted', true);
        $clients->assertJsonPath('data.clients.0.first_name', fn (mixed $value): bool => $value !== 'Cash');
    }

    public function test_notifications_view_gates_agency_feed_without_blocking_the_endpoint(): void
    {
        $ctx = $this->bootstrapAgencyDashboard('NOTIF-A');
        $tellerPermissions = $this->defaultTellerPermissions();

        $withoutNotificationsView = array_values(array_filter(
            $tellerPermissions,
            static fn (string $name): bool => $name !== 'notifications.view',
        ));
        $user = $this->createScopedUser($ctx['agency'], $withoutNotificationsView);

        // Own feed is always reachable (200); agency-wide notifications stay hidden.
        $notifications = $this->actingAsTeller($user)->getJson('/api/v1/notifications');
        $this->assertJsonSuccess($notifications);
        $categories = $this->pluck($notifications, 'data.notifications', 'category');
        self::assertNotContains('cash_session_opened', $categories);
        self::assertNotContains('cash_deposit_posted', $categories);
    }

    public function test_teller_dashboard_requires_no_broad_ledger_audit_or_full_pii_grants(): void
    {
        $teller = $this->createScopedUserWithRole('teller', $this->createAgency('NOBROAD-A'));

        foreach (['crm.pii.view', 'ledger.accounts.view', 'accounting.audit.view', 'journal.entries.view'] as $broad) {
            self::assertFalse(
                $teller->checkPermissionTo($broad),
                "Default teller must not hold broad permission {$broad} for dashboard rendering.",
            );
        }

        // No broad grant required to reach the dashboard contract is proven by the
        // happy-path test above; here we also confirm teller stays denied on the
        // operational dashboard so dashboard data must come from teller-scoped endpoints.
        $this->actingAsTeller($teller)->getJson('/api/v1/dashboards/operational')->assertForbidden();
    }

    /**
     * @return array{
     *     agency: array{id:int, code:string, name:string, public_id:string},
     *     manager: User,
     *     teller: User,
     *     session_public_id: string,
     *     transaction_public_id: string,
     *     account_public_id: string,
     *     client_public_id: string,
     * }
     */
    private function bootstrapAgencyDashboard(string $code): array
    {
        $agency = $this->createAgency($code);
        $manager = $this->createScopedUserWithRole('agency-manager', $agency);
        $teller = $this->createScopedUserWithRole('teller', $agency);

        $cashLedger = $this->createLedgerAccount($agency['id'], $code.'-TILL-LED', LedgerAccount::ACCOUNT_CLASS_ASSET);
        $depositLedger = $this->createLedgerAccount($agency['id'], $code.'-DEP-LED', LedgerAccount::ACCOUNT_CLASS_LIABILITY);
        $account = $this->createCustomerAccount($agency['id'], $depositLedger['id'], $code.'-ACC');

        $till = $this->actingAsTeller($manager)->postJson('/api/v1/tills', [
            'code' => $code.'-TILL',
            'name' => $code.' Till',
            'requires_denominations' => false,
            'assigned_user_public_id' => $teller->public_id,
            'ledger_account_public_id' => $cashLedger['public_id'],
            'max_withdrawal_limit_minor' => 100000,
        ]);
        $this->assertJsonSuccess($till, 201);
        $tillPublicId = $this->requireString($till, 'data.public_id');

        $session = $this->actingAsTeller($manager)->postJson('/api/v1/teller-sessions', [
            'till_public_id' => $tillPublicId,
            'teller_user_public_id' => $teller->public_id,
            'business_date' => now()->toDateString(),
            'opening_declaration_minor' => 5000,
            'currency' => 'XAF',
        ]);
        $this->assertJsonSuccess($session, 201);
        $sessionPublicId = $this->requireString($session, 'data.public_id');

        $deposit = $this->actingAsTeller($manager)->postJson('/api/v1/teller-sessions/'.$sessionPublicId.'/deposits', [
            'customer_account_public_id' => $account['public_id'],
            'amount_minor' => 10000,
            'currency' => 'XAF',
            'idempotency_key' => $code.'-deposit-1',
            'operation_code' => 'cash_deposit',
            'depositor_name' => 'Counter Depositor',
            'description' => 'Counter cash deposit',
        ]);
        $this->assertJsonSuccess($deposit, 201);
        $transactionPublicId = $this->requireString($deposit, 'data.teller_transaction.public_id');

        $clientPublicId = DB::table('customer_accounts')
            ->join('clients', 'clients.id', '=', 'customer_accounts.client_id')
            ->where('customer_accounts.public_id', $account['public_id'])
            ->value('clients.public_id');
        self::assertIsString($clientPublicId);

        return [
            'agency' => $agency,
            'manager' => $manager,
            'teller' => $teller,
            'session_public_id' => $sessionPublicId,
            'transaction_public_id' => $transactionPublicId,
            'account_public_id' => $account['public_id'],
            'client_public_id' => $clientPublicId,
        ];
    }

    private function actingAsTeller(User $user): static
    {
        return $this->withApiHeaders()->actingAsSanctum($user);
    }

    /**
     * @return array<int, string>
     */
    private function defaultTellerPermissions(): array
    {
        $rows = DB::table('roles')
            ->join('role_has_permissions', 'role_has_permissions.role_id', '=', 'roles.id')
            ->join('permissions', 'permissions.id', '=', 'role_has_permissions.permission_id')
            ->where('roles.name', 'teller')
            ->where('roles.guard_name', 'web')
            ->pluck('permissions.name');

        $names = [];
        foreach ($rows as $name) {
            if (is_string($name) && $name !== '') {
                $names[] = $name;
            }
        }

        self::assertNotEmpty($names, 'Default teller role must expose permissions for the dashboard contract.');

        return $names;
    }

    /**
     * @param  array{id:int, code:string, name:string, public_id:string}  $agency
     */
    private function createScopedUserWithRole(string $role, array $agency): User
    {
        $user = $this->makeAgencyUser($agency);
        $user->assignRole($role);
        $this->assignAgency($user, $agency, $role);

        return $user;
    }

    /**
     * @param  array{id:int, code:string, name:string, public_id:string}  $agency
     * @param  array<int, string>  $permissions
     */
    private function createScopedUser(array $agency, array $permissions): User
    {
        $user = $this->makeAgencyUser($agency);
        $user->givePermissionTo($permissions);
        $this->assignAgency($user, $agency, 'teller');

        return $user;
    }

    /**
     * @param  array{id:int, code:string, name:string, public_id:string}  $agency
     */
    private function makeAgencyUser(array $agency): User
    {
        return User::factory()->createOne([
            'status' => User::STATUS_ACTIVE,
            'phone_verified_at' => now(),
            'agency_id' => $agency['id'],
            'agency_code' => $agency['code'],
            'agency_name' => $agency['name'],
        ]);
    }

    /**
     * @param  array{id:int, code:string, name:string, public_id:string}  $agency
     */
    private function assignAgency(User $user, array $agency, string $roleAtAgency): void
    {
        DB::table('staff_agency_assignments')->insert([
            'public_id' => (string) Str::ulid(),
            'user_id' => $user->id,
            'agency_id' => $agency['id'],
            'role_at_agency' => $roleAtAgency,
            'starts_on' => now()->toDateString(),
            'ends_on' => null,
            'is_primary' => true,
            'status' => StaffAgencyAssignment::STATUS_ACTIVE,
        ]);
    }

    /**
     * @return array{id:int, code:string, name:string, public_id:string}
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
     * @return array{id:int, public_id:string}
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
            'normal_balance_side' => $accountClass === LedgerAccount::ACCOUNT_CLASS_ASSET
                || $accountClass === LedgerAccount::ACCOUNT_CLASS_EXPENSE
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
     * @return array{id:int, public_id:string}
     */
    private function createCustomerAccount(int $agencyId, int $ledgerAccountId, string $accountNumber): array
    {
        $clientId = DB::table('clients')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'agency_id' => $agencyId,
            'client_reference' => 'CL-'.$accountNumber,
            'first_name' => 'Cash',
            'last_name' => 'Depositor',
            'father_name' => 'Sponsor',
            'phone_number' => '237600000000',
            'email' => 'cash.depositor@example.test',
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
            'account_title' => 'Cash Depositor',
            'account_type' => 'ordinary_savings',
            'currency' => 'XAF',
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
     * @return array<int, string>
     */
    private function pluck(TestResponse $response, string $path, string $key): array
    {
        $rows = $response->json($path);
        $values = [];
        if (is_array($rows)) {
            foreach ($rows as $row) {
                if (is_array($row) && isset($row[$key]) && is_string($row[$key])) {
                    $values[] = $row[$key];
                }
            }
        }

        return $values;
    }

    private function requireString(TestResponse $response, string $path): string
    {
        $value = $response->json($path);
        self::assertIsString($value);

        return $value;
    }
}
