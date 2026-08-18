<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\AccountProduct;
use App\Models\Client;
use App\Models\CustomerAccount;
use App\Models\LedgerAccount;
use App\Models\User;
use App\Support\Accounting\AccountingBalanceCalculator;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

final class Module3AccountingProductTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_platform_admin_can_manage_account_products(): void
    {
        $agency = $this->createAgency('AP01');
        $actor = $this->createUserWithRole('platform-admin');
        $ledger = $this->createLedgerAccount($agency['id']);

        $create = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('account-product-create')->plainTextToken])
            ->postJson('/api/v1/account-products', [
                'agency_public_id' => $agency['public_id'],
                'ledger_account_public_id' => $ledger['public_id'],
                'code' => 'SAV-ORD',
                'name' => 'Ordinary Savings',
                'account_family' => AccountProduct::FAMILY_SAVINGS,
                'minimum_balance_minor' => 5000,
                'currency' => 'xaf',
                'rules' => ['minimum_balance_policy' => 'product_default'],
            ]);

        $this->assertJsonSuccess($create, 201);
        $productPublicId = $this->requireStringJsonPath($create, 'data.public_id');
        $create->assertJsonPath('data.code', 'SAV-ORD');
        $create->assertJsonPath('data.currency', 'XAF');
        $create->assertJsonPath('data.minimum_balance_minor', 5000);
        $create->assertJsonPath('data.ledger_account_public_id', $ledger['public_id']);

        $duplicate = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('account-product-duplicate')->plainTextToken])
            ->postJson('/api/v1/account-products', [
                'agency_public_id' => $agency['public_id'],
                'code' => 'SAV-ORD',
                'name' => 'Duplicate Savings',
                'account_family' => AccountProduct::FAMILY_SAVINGS,
            ]);
        // Reported on the `code` field so the form can put it under that input,
        // and it names the agency the code is already used in.
        $duplicate->assertStatus(422);
        $duplicate->assertJsonValidationErrors(['code']);

        $update = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('account-product-update')->plainTextToken])
            ->patchJson('/api/v1/account-products/'.$productPublicId, [
                'name' => 'Ordinary Savings Updated',
                'minimum_balance_minor' => 7500,
            ]);
        $this->assertJsonSuccess($update);
        $update->assertJsonPath('data.name', 'Ordinary Savings Updated');
        $update->assertJsonPath('data.minimum_balance_minor', 7500);

        $list = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('account-product-list')->plainTextToken])
            ->getJson('/api/v1/account-products?account_family=savings');
        $list->assertOk();
        $list->assertJsonPath('success', true);
        $list->assertJsonPath('message', 'Account products retrieved successfully');
        $list->assertJsonPath('errors', null);
        $list->assertJsonPath('data.account_products.0.public_id', $productPublicId);

        $archive = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('account-product-archive')->plainTextToken])
            ->deleteJson('/api/v1/account-products/'.$productPublicId);
        $this->assertJsonSuccess($archive);

        $this->assertDatabaseHas('account_products', [
            'public_id' => $productPublicId,
            'status' => AccountProduct::STATUS_ARCHIVED,
        ]);
    }

    public function test_customer_account_creation_uses_active_account_product_rules(): void
    {
        $agency = $this->createAgency('AP02');
        $actor = $this->createUserWithRole('platform-admin');
        $client = $this->createVerifiedClient($agency['id']);
        $ledger = $this->createLedgerAccount($agency['id']);
        $productPublicId = (string) Str::ulid();

        DB::table('account_products')->insert([
            'public_id' => $productPublicId,
            'agency_id' => $agency['id'],
            'ledger_account_id' => $ledger['id'],
            'code' => 'REC-001',
            'name' => 'Recovery Account',
            'account_family' => AccountProduct::FAMILY_RECOVERY,
            'minimum_balance_minor' => 0,
            'currency' => 'XAF',
            'status' => AccountProduct::STATUS_ACTIVE,
        ]);

        $create = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('customer-account-create')->plainTextToken])
            ->postJson('/api/v1/customer-accounts', [
                'client_public_id' => $client['public_id'],
                'agency_public_id' => $agency['public_id'],
                'account_product_public_id' => $productPublicId,
                'account_number' => 'ACC-REC-001',
                'account_title' => 'Recovery Account',
                'opened_on' => '2026-05-11',
            ]);

        $this->assertJsonSuccess($create, 201);
        $create->assertJsonPath('data.account_product_public_id', $productPublicId);
        $create->assertJsonPath('data.ledger_account_public_id', $ledger['public_id']);
        $create->assertJsonPath('data.account_type', AccountProduct::FAMILY_RECOVERY);
        $create->assertJsonPath('data.currency', 'XAF');

        $inactiveProductPublicId = (string) Str::ulid();
        DB::table('account_products')->insert([
            'public_id' => $inactiveProductPublicId,
            'agency_id' => $agency['id'],
            'code' => 'SAV-INACTIVE',
            'name' => 'Inactive Savings',
            'account_family' => AccountProduct::FAMILY_SAVINGS,
            'status' => AccountProduct::STATUS_INACTIVE,
        ]);

        $inactive = $this->withApiHeaders([
            'Authorization' => 'Bearer '.$actor->createToken('customer-account-inactive')->plainTextToken,
            'X-Locale' => 'fr',
        ])
            ->postJson('/api/v1/customer-accounts', [
                'client_public_id' => $client['public_id'],
                'agency_public_id' => $agency['public_id'],
                'account_product_public_id' => $inactiveProductPublicId,
                'account_number' => 'ACC-INACTIVE-001',
                'opened_on' => '2026-05-11',
            ]);

        $inactive->assertStatus(422);
        $inactive->assertJsonValidationErrors(['account_product_public_id']);
        $inactive->assertJsonPath('errors.account_product_public_id.0', 'Le produit de compte sélectionné doit être actif et disponible pour l’agence du compte.');
    }

    public function test_customer_account_number_is_auto_generated_when_omitted(): void
    {
        $agency = $this->createAgency('AP-GEN');
        $actor = $this->createUserWithRole('platform-admin');
        $client = $this->createVerifiedClient($agency['id']);
        $ledger = $this->createLedgerAccount($agency['id']);

        $first = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('gen-1')->plainTextToken])
            ->postJson('/api/v1/customer-accounts', [
                'client_public_id' => $client['public_id'],
                'agency_public_id' => $agency['public_id'],
                'ledger_account_public_id' => $ledger['public_id'],
                'account_title' => 'Generated Account',
                'opened_on' => '2026-05-11',
            ]);
        $this->assertJsonSuccess($first, 201);
        $firstNumber = $this->requireStringJsonPath($first, 'data.account_number');
        self::assertMatchesRegularExpression('/^ACC\d{8}$/', $firstNumber);

        $second = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('gen-2')->plainTextToken])
            ->postJson('/api/v1/customer-accounts', [
                'client_public_id' => $client['public_id'],
                'agency_public_id' => $agency['public_id'],
                'ledger_account_public_id' => $ledger['public_id'],
                'account_title' => 'Generated Account 2',
                'opened_on' => '2026-05-11',
            ]);
        $this->assertJsonSuccess($second, 201);
        $secondNumber = $this->requireStringJsonPath($second, 'data.account_number');
        self::assertMatchesRegularExpression('/^ACC\d{8}$/', $secondNumber);

        // Sequential generation never collides.
        self::assertNotSame($firstNumber, $secondNumber);

        // A client-provided number is still honored.
        $provided = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('gen-3')->plainTextToken])
            ->postJson('/api/v1/customer-accounts', [
                'client_public_id' => $client['public_id'],
                'agency_public_id' => $agency['public_id'],
                'ledger_account_public_id' => $ledger['public_id'],
                'account_number' => 'CUSTOM-ACC-001',
                'account_title' => 'Provided Account',
                'opened_on' => '2026-05-11',
            ]);
        $this->assertJsonSuccess($provided, 201);
        $provided->assertJsonPath('data.account_number', 'CUSTOM-ACC-001');
    }

    public function test_a_product_can_be_saved_again_with_the_overdraft_switched_off(): void
    {
        $agency = $this->createAgency('AP-OD2');
        $actor = $this->createUserWithRole('platform-admin');
        $ledger = $this->createLedgerAccount($agency['id']);

        $create = $this->withApiHeaders()->actingAsSanctum($actor)
            ->postJson('/api/v1/account-products', [
                'code' => 'REC-EDIT',
                'name' => 'Compte de recouvrement des crédits',
                'account_family' => AccountProduct::FAMILY_RECOVERY,
                'agency_public_id' => $agency['public_id'],
                'currency' => 'XAF',
                'ledger_account_public_id' => $ledger['public_id'],
                'minimum_balance_minor' => 0,
                'allows_overdraft' => false,
                'overdraft_limit_minor' => null,
                'status' => AccountProduct::STATUS_ACTIVE,
            ]);
        $this->assertJsonSuccess($create, 201);
        $publicId = $this->requireStringJsonPath($create, 'data.public_id');

        // The drawer hides the limit field while the overdraft box is unchecked and
        // sends null for it, so every save of such a product carried a null. Create
        // accepted that and update did not, which made the product editable exactly
        // once — and the refusal named « Plafond de découvert », a field the user
        // could not see to correct.
        $update = $this->withApiHeaders(['X-Locale' => 'fr'])->actingAsSanctum($actor)
            ->patchJson('/api/v1/account-products/'.$publicId, [
                'name' => 'Compte de recouvrement des crédits',
                'allows_overdraft' => false,
                'overdraft_limit_minor' => null,
                'minimum_balance_minor' => null,
            ]);

        $this->assertJsonSuccess($update);
        $update->assertJsonPath('data.allows_overdraft', false);

        // NOT NULL columns, so the null has to land as the value it stands for.
        $row = DB::table('account_products')->where('public_id', $publicId)
            ->first(['overdraft_limit_minor', 'minimum_balance_minor', 'currency', 'status']);
        self::assertNotNull($row);
        self::assertSame(0, (int) $row->overdraft_limit_minor);
        self::assertSame(0, (int) $row->minimum_balance_minor);
        // Untouched by the request, so they keep what they had.
        self::assertSame('XAF', $row->currency);
        self::assertSame(AccountProduct::STATUS_ACTIVE, $row->status);
    }

    public function test_head_office_can_edit_an_agency_account_product(): void
    {
        $agency = $this->createAgency('AP-HO');
        $ledger = $this->createLedgerAccount($agency['id']);

        // The chef comptable owns this catalogue and is the only role allowed to
        // write it — and carries no agency assignment, because head office belongs
        // to no branch. The scope test compared his (null) agency against the
        // product's, so he matched nothing: he could create a product for an
        // agency, since create carries no scope test, and then never edit it
        // again. The list already read institution scope this way; the policy did
        // not.
        $chief = $this->createUserWithRole('chief-accountant');
        DB::table('staff_agency_assignments')->where('user_id', $chief->id)->delete();
        self::assertNull($chief->refresh()->currentAgencyId(), 'Head office carries no agency.');

        $productPublicId = (string) Str::ulid();
        DB::table('account_products')->insert([
            'public_id' => $productPublicId,
            'agency_id' => $agency['id'],
            'ledger_account_id' => $ledger['id'],
            'code' => 'HO-001',
            'name' => 'Produit agence',
            'account_family' => AccountProduct::FAMILY_CURRENT,
            'minimum_balance_minor' => 0,
            'currency' => 'XAF',
            'allows_overdraft' => true,
            'overdraft_limit_minor' => 500000,
            'status' => AccountProduct::STATUS_ACTIVE,
        ]);

        // The case the accounting team hit: unchecking the overdraft and saving.
        $update = $this->withApiHeaders()->actingAsSanctum($chief)
            ->patchJson('/api/v1/account-products/'.$productPublicId, [
                'allows_overdraft' => false,
                'overdraft_limit_minor' => null,
            ]);
        $this->assertJsonSuccess($update);
        $update->assertJsonPath('data.allows_overdraft', false);

        // Institution scope is not a way past the permission itself: a teller
        // reads this catalogue and still may not write it.
        $teller = $this->createUserWithRole('teller');
        $this->withApiHeaders()->actingAsSanctum($teller)
            ->patchJson('/api/v1/account-products/'.$productPublicId, ['name' => 'Renommé'])
            ->assertForbidden();
    }

    public function test_currency_and_family_freeze_once_accounts_exist(): void
    {
        $agency = $this->createAgency('AP-FRZ');
        $actor = $this->createUserWithRole('platform-admin');
        $client = $this->createVerifiedClient($agency['id']);
        $ledger = $this->createLedgerAccount($agency['id']);
        $productPublicId = (string) Str::ulid();

        DB::table('account_products')->insert([
            'public_id' => $productPublicId,
            'agency_id' => $agency['id'],
            'ledger_account_id' => $ledger['id'],
            'code' => 'FRZ-001',
            'name' => 'Compte courant',
            'account_family' => AccountProduct::FAMILY_CURRENT,
            'minimum_balance_minor' => 0,
            'currency' => 'XAF',
            'status' => AccountProduct::STATUS_ACTIVE,
        ]);

        // Before any account exists, both are still open to correction.
        $early = $this->withApiHeaders()->actingAsSanctum($actor)
            ->patchJson('/api/v1/account-products/'.$productPublicId, ['currency' => 'EUR']);
        $this->assertJsonSuccess($early);
        $early->assertJsonPath('data.currency', 'EUR');

        $this->withApiHeaders()->actingAsSanctum($actor)
            ->patchJson('/api/v1/account-products/'.$productPublicId, ['currency' => 'XAF']);

        // An account copies the currency and the family when it is opened and
        // keeps its copy, so changing them afterwards migrates nothing — it only
        // makes tomorrow's accounts disagree with yesterday's, silently. The
        // currency is the sharper one: a disbursement requires the account's
        // currency to match the loan's.
        $this->withApiHeaders()->actingAsSanctum($actor)
            ->postJson('/api/v1/customer-accounts', [
                'client_public_id' => $client['public_id'],
                'agency_public_id' => $agency['public_id'],
                'account_product_public_id' => $productPublicId,
                'account_number' => 'ACC-FRZ-1',
                'opened_on' => '2026-05-11',
            ])->assertStatus(201);

        $currency = $this->withApiHeaders()->actingAsSanctum($actor)
            ->patchJson('/api/v1/account-products/'.$productPublicId, ['currency' => 'EUR']);
        $currency->assertStatus(422);
        $currency->assertJsonValidationErrors(['currency']);

        $family = $this->withApiHeaders()->actingAsSanctum($actor)
            ->patchJson('/api/v1/account-products/'.$productPublicId, [
                'account_family' => AccountProduct::FAMILY_SAVINGS,
            ]);
        $family->assertStatus(422);
        $family->assertJsonValidationErrors(['account_family']);

        // Everything else still edits: this freezes two fields, it does not
        // freeze the product.
        $rename = $this->withApiHeaders()->actingAsSanctum($actor)
            ->patchJson('/api/v1/account-products/'.$productPublicId, [
                'name' => 'Compte courant renommé',
                'currency' => 'XAF',
                'account_family' => AccountProduct::FAMILY_CURRENT,
            ]);
        $this->assertJsonSuccess($rename);
        $rename->assertJsonPath('data.name', 'Compte courant renommé');
    }

    public function test_an_authorised_overdraft_counts_as_available_balance(): void
    {
        $agency = $this->createAgency('AP-OD');
        $client = $this->createVerifiedClient($agency['id']);
        $ledger = $this->createLedgerAccount($agency['id']);

        $makeAccount = function (bool $overdraft) use ($agency, $client, $ledger): CustomerAccount {
            $productPublicId = (string) Str::ulid();
            DB::table('account_products')->insert([
                'public_id' => $productPublicId,
                'agency_id' => $agency['id'],
                'ledger_account_id' => $ledger['id'],
                'code' => 'OD-'.Str::ulid(),
                'name' => $overdraft ? 'Courant avec découvert' : 'Courant sans découvert',
                'account_family' => AccountProduct::FAMILY_CURRENT,
                'minimum_balance_minor' => 0,
                'currency' => 'XAF',
                'allows_overdraft' => $overdraft,
                'overdraft_limit_minor' => $overdraft ? 500000 : 0,
                'status' => AccountProduct::STATUS_ACTIVE,
            ]);

            return CustomerAccount::query()->create([
                'public_id' => (string) Str::ulid(),
                'client_id' => $client['id'],
                'agency_id' => $agency['id'],
                'ledger_account_id' => $ledger['id'],
                'account_product_id' => DB::table('account_products')->where('public_id', $productPublicId)->value('id'),
                'account_number' => 'ACC-'.Str::ulid(),
                'currency' => 'XAF',
                'status' => CustomerAccount::STATUS_ACTIVE,
                'opened_on' => '2026-05-11',
            ]);
        };

        $calculator = app(AccountingBalanceCalculator::class);

        // An authorised overdraft is spending power the account has. Both of these
        // sit at nil, and only one of them may be debited — that difference is the
        // whole meaning of the field, and nothing used to read it: a current
        // account carrying a 500 000 limit was refused at zero exactly like a
        // savings account with none, and the product screen said otherwise.
        $withOverdraft = $calculator->availableForCustomerAccount($makeAccount(true), 'XAF');
        $without = $calculator->availableForCustomerAccount($makeAccount(false), 'XAF');

        self::assertSame(0, $withOverdraft['accounting_balance_minor']);
        self::assertSame(500000, $withOverdraft['overdraft_limit_minor']);
        self::assertSame(500000, $withOverdraft['available_balance_minor']);

        self::assertSame(0, $without['overdraft_limit_minor']);
        self::assertSame(0, $without['available_balance_minor']);
    }

    public function test_platform_admin_can_manage_emf_regulatory_account_hierarchy(): void
    {
        $actor = $this->createUserWithRole('platform-admin');

        $parent = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('emf-parent')->plainTextToken])
            ->postJson('/api/v1/emf-regulatory-accounts', [
                'code' => '10',
                'name' => 'Treasury And Cash',
                'account_class' => 'capitaux_permanents',
                'metadata' => ['source' => 'COBAC'],
            ]);
        $this->assertJsonSuccess($parent, 201);
        $parentPublicId = $this->requireStringJsonPath($parent, 'data.public_id');

        $child = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('emf-child')->plainTextToken])
            ->postJson('/api/v1/emf-regulatory-accounts', [
                'parent_public_id' => $parentPublicId,
                'code' => '101',
                'name' => 'Cash In Till',
                'account_class' => 'capitaux_permanents',
            ]);
        $this->assertJsonSuccess($child, 201);
        $childPublicId = $this->requireStringJsonPath($child, 'data.public_id');
        $child->assertJsonPath('data.parent_public_id', $parentPublicId);

        $cycle = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('emf-cycle')->plainTextToken])
            ->patchJson('/api/v1/emf-regulatory-accounts/'.$parentPublicId, [
                'parent_public_id' => $childPublicId,
            ]);
        $cycle->assertStatus(422);
        $cycle->assertJsonValidationErrors(['parent_public_id']);

        $parentArchive = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('emf-parent-archive')->plainTextToken])
            ->deleteJson('/api/v1/emf-regulatory-accounts/'.$parentPublicId);
        $this->assertJsonError($parentArchive, 422, 'EMF regulatory account cannot be archived while child accounts or ledger mappings exist.');

        $childArchive = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('emf-child-archive')->plainTextToken])
            ->deleteJson('/api/v1/emf-regulatory-accounts/'.$childPublicId);
        $this->assertJsonSuccess($childArchive);

        $this->assertDatabaseHas('emf_regulatory_accounts', [
            'public_id' => $childPublicId,
            'status' => 'archived',
        ]);
    }

    public function test_platform_admin_can_manage_emf_ledger_account_mappings(): void
    {
        $agency = $this->createAgency('EM01');
        $actor = $this->createUserWithRole('platform-admin');
        $ledger = $this->createLedgerAccount($agency['id']);
        $emfPublicId = (string) Str::ulid();

        DB::table('emf_regulatory_accounts')->insert([
            'public_id' => $emfPublicId,
            'code' => '201',
            'name' => 'Customer Deposits',
            'account_class' => 'valeurs_immobilisees',
            'status' => 'active',
        ]);

        $create = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('emf-map-create')->plainTextToken])
            ->postJson('/api/v1/emf-ledger-account-mappings', [
                'emf_regulatory_account_public_id' => $emfPublicId,
                'ledger_account_public_id' => $ledger['public_id'],
            ]);

        $this->assertJsonSuccess($create, 201);
        $mappingPublicId = $this->requireStringJsonPath($create, 'data.public_id');
        $create->assertJsonPath('data.emf_regulatory_account_public_id', $emfPublicId);
        $create->assertJsonPath('data.ledger_account_public_id', $ledger['public_id']);
        $create->assertJsonPath('data.ledger_account_agency_public_id', $agency['public_id']);

        $duplicate = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('emf-map-duplicate')->plainTextToken])
            ->postJson('/api/v1/emf-ledger-account-mappings', [
                'emf_regulatory_account_public_id' => $emfPublicId,
                'ledger_account_public_id' => $ledger['public_id'],
            ]);
        $this->assertJsonError($duplicate, 422, 'EMF ledger account mapping already exists.');

        $list = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('emf-map-list')->plainTextToken])
            ->getJson('/api/v1/emf-ledger-account-mappings?ledger_account_public_id='.$ledger['public_id']);
        $list->assertOk();
        $list->assertJsonPath('data.emf_ledger_account_mappings.0.public_id', $mappingPublicId);

        $inactive = $this->createLedgerAccount($agency['id'], LedgerAccount::STATUS_INACTIVE);
        $inactiveResponse = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('emf-map-inactive')->plainTextToken])
            ->postJson('/api/v1/emf-ledger-account-mappings', [
                'emf_regulatory_account_public_id' => $emfPublicId,
                'ledger_account_public_id' => $inactive['public_id'],
            ]);
        $inactiveResponse->assertStatus(422);
        $inactiveResponse->assertJsonValidationErrors(['ledger_account_public_id']);

        $archive = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('emf-map-archive')->plainTextToken])
            ->deleteJson('/api/v1/emf-ledger-account-mappings/'.$mappingPublicId);
        $this->assertJsonSuccess($archive);

        $this->assertDatabaseHas('emf_ledger_account_mappings', [
            'public_id' => $mappingPublicId,
            'status' => 'archived',
        ]);
    }

    public function test_platform_admin_can_manage_operation_codes_and_account_mappings_without_posting(): void
    {
        $agency = $this->createAgency('OP01');
        $actor = $this->createUserWithRole('platform-admin');
        $debit = $this->createLedgerAccount($agency['id']);
        $credit = $this->createLedgerAccount($agency['id']);

        $codeResponse = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('operation-code-create')->plainTextToken])
            ->postJson('/api/v1/operation-codes', [
                'code' => 'LOAN_DISBURSEMENT',
                'label' => 'Loan disbursement',
                'module' => 'loan',
                'operation_type' => 'disbursement',
                'direction' => 'debit_credit',
                'metadata' => ['protected' => true],
            ]);

        $this->assertJsonSuccess($codeResponse, 201);
        $operationCodePublicId = $this->requireStringJsonPath($codeResponse, 'data.public_id');
        $codeResponse->assertJsonPath('data.module', 'loan');

        $mappingResponse = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('operation-map-create')->plainTextToken])
            ->postJson('/api/v1/operation-account-mappings', [
                'operation_code_public_id' => $operationCodePublicId,
                'debit_ledger_account_public_id' => $debit['public_id'],
                'credit_ledger_account_public_id' => $credit['public_id'],
                'currency' => 'xaf',
                'rules' => ['source' => 'loan_disbursement_workflow'],
            ]);

        $this->assertJsonSuccess($mappingResponse, 201);
        $mappingPublicId = $this->requireStringJsonPath($mappingResponse, 'data.public_id');
        $mappingResponse->assertJsonPath('data.operation_code_public_id', $operationCodePublicId);
        $mappingResponse->assertJsonPath('data.currency', 'XAF');

        $crossAgencyCredit = $this->createLedgerAccount($this->createAgency('OP02')['id']);
        $crossAgencyResponse = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('operation-map-cross')->plainTextToken])
            ->postJson('/api/v1/operation-account-mappings', [
                'operation_code_public_id' => $operationCodePublicId,
                'debit_ledger_account_public_id' => $debit['public_id'],
                'credit_ledger_account_public_id' => $crossAgencyCredit['public_id'],
            ]);
        $this->assertJsonError($crossAgencyResponse, 422, 'Debit and credit ledger accounts must belong to the same agency.');

        $codeArchiveBlocked = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('operation-code-blocked')->plainTextToken])
            ->deleteJson('/api/v1/operation-codes/'.$operationCodePublicId);
        $this->assertJsonError($codeArchiveBlocked, 422, 'Operation code cannot be archived while active or inactive account mappings exist.');

        $archiveMapping = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('operation-map-archive')->plainTextToken])
            ->deleteJson('/api/v1/operation-account-mappings/'.$mappingPublicId);
        $this->assertJsonSuccess($archiveMapping);

        $this->assertDatabaseHas('operation_account_mappings', [
            'public_id' => $mappingPublicId,
            'status' => 'archived',
        ]);
        $this->assertDatabaseCount('journal_entries', 0);
        $this->assertDatabaseCount('journal_lines', 0);
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
        ]);

        return ['id' => $id, 'public_id' => $publicId];
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
    private function createLedgerAccount(int $agencyId, string $status = LedgerAccount::STATUS_ACTIVE): array
    {
        $publicId = (string) Str::ulid();
        $id = DB::table('ledger_accounts')->insertGetId([
            'public_id' => $publicId,
            'agency_id' => $agencyId,
            'code' => 'LA-'.Str::ulid(),
            'name' => 'Customer Control',
            'account_class' => LedgerAccount::ACCOUNT_CLASS_TRESORERIE_INTERBANCAIRE,
            'normal_balance_side' => LedgerAccount::NORMAL_BALANCE_DEBIT,
            'status' => $status,
        ]);

        return ['id' => $id, 'public_id' => $publicId];
    }

    /**
     * @return array{id:int, public_id:string}
     */
    private function createVerifiedClient(int $agencyId): array
    {
        $publicId = (string) Str::ulid();
        $id = DB::table('clients')->insertGetId([
            'public_id' => $publicId,
            'agency_id' => $agencyId,
            'client_reference' => 'CL-'.Str::ulid(),
            'first_name' => 'Account',
            'last_name' => 'Owner',
            'status' => Client::STATUS_ACTIVE,
            'kyc_status' => Client::KYC_STATUS_VERIFIED,
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
