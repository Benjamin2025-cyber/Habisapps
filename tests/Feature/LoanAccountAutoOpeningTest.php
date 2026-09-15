<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Agency;
use App\Models\Client;
use App\Models\CustomerAccount;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Models\User;
use App\Support\Loans\LoanTermLedgerResolver;
use Database\Seeders\LoanSetupLedgerAccountSeeder;
use Database\Seeders\PcemfChartSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * « Le compte d'amortissement du prêt, des impayés, de virement et de
 * recouvrement, est le même pour un crédit donné, il est créé automatiquement
 * lors de la mise en place » — CORRECTIONS HABISLOAN 04/09/2026, with the
 * account's control fixed by the term (3282 / 3181 / 3081) and its number
 * built to the ten-digit nomenclature.
 */
final class LoanAccountAutoOpeningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    /**
     * A twelve-month loan is court terme, so its account sits under 3282 and
     * is numbered 3282 + order + agency. All four links point at it.
     */
    public function test_a_short_term_loan_opens_one_account_filling_all_four_links(): void
    {
        $setup = $this->setUpAgencyWithChart('001');

        $response = $this->createLoan($setup, installments: 12);
        $this->assertJsonSuccess($response, 201);

        $loan = Loan::query()->where('public_id', $response->json('data.public_id'))->firstOrFail();

        self::assertNotNull($loan->amortization_account_id, 'No account was opened for the loan.');
        // One account, not four: the accounting team treats them as one.
        self::assertSame($loan->amortization_account_id, $loan->unpaid_account_id);
        self::assertSame($loan->amortization_account_id, $loan->recovery_account_id);
        self::assertSame($loan->amortization_account_id, $loan->transfer_account_id);

        $account = CustomerAccount::query()->findOrFail($loan->amortization_account_id);
        self::assertSame(CustomerAccount::TYPE_LOAN, $account->account_type);
        self::assertSame($setup['client']->id, $account->client_id);
        // 3282 + three-digit order + agency 001, exactly their 3282001001.
        self::assertSame('3282001001', $account->account_number);
    }

    /**
     * The term decides the family. Their bands: 0–24 months court terme,
     * 24–84 moyen terme, beyond that long terme.
     */
    public function test_the_term_decides_which_credit_family_the_account_belongs_to(): void
    {
        $setup = $this->setUpAgencyWithChart('002');

        foreach ([
            [12, LoanTermLedgerResolver::COURT_TERME],
            [36, LoanTermLedgerResolver::MOYEN_TERME],
            [120, LoanTermLedgerResolver::LONG_TERME],
        ] as [$installments, $expectedCode]) {
            $response = $this->createLoan($setup, $installments);
            $this->assertJsonSuccess($response, 201);

            $loan = Loan::query()->where('public_id', $response->json('data.public_id'))->firstOrFail();
            $account = CustomerAccount::query()->findOrFail($loan->amortization_account_id);

            self::assertStringStartsWith(
                $expectedCode,
                $account->account_number,
                "A {$installments}-month loan was filed under the wrong credit family.",
            );
        }
    }

    /**
     * The boundary months themselves. 24 is court terme and 84 is moyen terme
     * — the document writes both bands as touching, and a loan may only be in
     * one of them.
     */
    public function test_the_band_boundaries_fall_on_the_lower_family(): void
    {
        self::assertSame(LoanTermLedgerResolver::COURT_TERME, LoanTermLedgerResolver::ledgerCodeForMonths(24));
        self::assertSame(LoanTermLedgerResolver::MOYEN_TERME, LoanTermLedgerResolver::ledgerCodeForMonths(25));
        self::assertSame(LoanTermLedgerResolver::MOYEN_TERME, LoanTermLedgerResolver::ledgerCodeForMonths(84));
        self::assertSame(LoanTermLedgerResolver::LONG_TERME, LoanTermLedgerResolver::ledgerCodeForMonths(85));
    }

    /**
     * A file whose accounts were chosen by hand keeps them — an existing loan
     * being corrected must stay reconciled against what it was booked on.
     */
    public function test_explicitly_supplied_accounts_are_not_overridden(): void
    {
        $setup = $this->setUpAgencyWithChart('003');

        $existing = CustomerAccount::query()->create([
            'public_id' => (string) Str::ulid(),
            'client_id' => $setup['client']->id,
            'agency_id' => $setup['agency']->id,
            'account_number' => 'HAND-PICKED-001',
            'currency' => 'XAF',
            'opened_on' => '2026-08-01',
            'status' => CustomerAccount::STATUS_ACTIVE,
        ]);

        $response = $this->createLoan($setup, installments: 12, extra: [
            'amortization_account_public_id' => $existing->public_id,
        ]);
        $this->assertJsonSuccess($response, 201);

        $loan = Loan::query()->where('public_id', $response->json('data.public_id'))->firstOrFail();
        self::assertSame($existing->id, $loan->amortization_account_id);
    }

    /**
     * @param  array{agency: Agency, client: Client, product: LoanProduct, actor: User}  $setup
     * @param  array<string, mixed>  $extra
     */
    private function createLoan(array $setup, int $installments, array $extra = []): TestResponse
    {
        return $this->withApiHeaders()->actingAsSanctum($setup['actor'])
            ->postJson('/api/v1/loans', array_merge([
                'client_public_id' => $setup['client']->public_id,
                'loan_product_public_id' => $setup['product']->public_id,
                'requested_amount_minor' => 500000,
                'currency' => 'XAF',
                'applied_on' => '2026-09-01',
                'number_of_installments' => $installments,
            ], $extra));
    }

    /**
     * @return array{agency: Agency, client: Client, product: LoanProduct, actor: User}
     */
    private function setUpAgencyWithChart(string $code): array
    {
        $agency = Agency::query()->create([
            'public_id' => (string) Str::ulid(),
            'code' => $code,
            'name' => 'Agence '.$code,
            'status' => 'active',
        ]);

        // 3282 comes from the chart; 3181 and 3081 from the loan set-up seeder.
        $this->seed(PcemfChartSeeder::class);
        $this->seed(LoanSetupLedgerAccountSeeder::class);

        $client = Client::query()->create([
            'public_id' => (string) Str::ulid(),
            'agency_id' => $agency->id,
            'client_reference' => 'CLI-'.Str::ulid(),
            'first_name' => 'Loan',
            'last_name' => 'Client',
            'status' => 'active',
            'kyc_status' => 'verified',
        ]);

        $product = LoanProduct::query()->create([
            'public_id' => (string) Str::ulid(),
            'code' => 'LP-'.Str::ulid(),
            'name' => 'Crédit Ordinaire',
            'status' => LoanProduct::STATUS_ACTIVE,
            'min_amount_minor' => 100000,
            'max_amount_minor' => 1000000,
            'min_term_count' => 1,
            'max_term_count' => 240,
            'term_unit' => LoanProduct::TERM_UNIT_MONTH,
        ]);

        $actor = User::factory()->create(['agency_id' => $agency->id]);
        $actor->assignRole('platform-admin');

        return ['agency' => $agency, 'client' => $client, 'product' => $product, 'actor' => $actor];
    }
}
