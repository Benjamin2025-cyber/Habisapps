<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Client;
use App\Models\JournalEntry;
use App\Models\Loan;
use App\Models\StaffAgencyAssignment;
use App\Models\TellerSession;
use App\Models\TellerTransaction;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;
use Tests\Traits\HasApiHeaders;

/**
 * GitHub issue #12 — role-tailored dashboard backend requests.
 */
final class DashboardIssue12Test extends TestCase
{
    use HasApiHeaders;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_loan_officer_sees_only_own_portfolio_loans(): void
    {
        $agency = $this->createAgency('LO-A');
        $product = $this->createLoanProduct('LO-P');
        $officer = $this->createUserWithRole('loan-officer', $agency['id']);
        $otherOfficer = $this->createUserWithRole('loan-officer', $agency['id']);

        $ownLoanId = $this->seedLoan($agency['id'], $product['id'], 'application', $officer->id);
        $otherLoanId = $this->seedLoan($agency['id'], $product['id'], 'application', $otherOfficer->id);

        $response = $this->actingWith($officer)->getJson('/api/v1/loans?per_page=100');
        $this->assertJsonSuccess($response);
        $publicIds = $this->pluck($response, 'data.loans', 'public_id');
        self::assertContains($this->loanPublicId($ownLoanId), $publicIds);
        self::assertNotContains($this->loanPublicId($otherLoanId), $publicIds);
    }

    public function test_agency_manager_can_filter_in_agency_credit_agent_but_not_cross_agency(): void
    {
        $home = $this->createAgency('MGR-HOME');
        $other = $this->createAgency('MGR-OTHER');
        $product = $this->createLoanProduct('MGR-P');
        $manager = $this->createUserWithRole('agency-manager', $home['id']);
        $homeAgent = $this->createUserWithRole('loan-officer', $home['id']);
        $otherAgent = $this->createUserWithRole('loan-officer', $other['id']);

        $homeLoanId = $this->seedLoan($home['id'], $product['id'], 'application', $homeAgent->id);
        $this->seedLoan($other['id'], $product['id'], 'application', $otherAgent->id);

        $filtered = $this->actingWith($manager)->getJson('/api/v1/loans?filter[credit_agent_public_id]='.$homeAgent->public_id.'&per_page=100');
        $this->assertJsonSuccess($filtered);
        self::assertSame([$this->loanPublicId($homeLoanId)], $this->pluck($filtered, 'data.loans', 'public_id'));

        $cross = $this->actingWith($manager)->getJson('/api/v1/loans?filter[credit_agent_public_id]='.$otherAgent->public_id.'&per_page=100');
        $this->assertJsonSuccess($cross);
        self::assertSame([], $this->pluck($cross, 'data.loans', 'public_id'));
    }

    public function test_platform_admin_can_filter_credit_agent_across_agencies(): void
    {
        $agencyA = $this->createAgency('ADM-A');
        $agencyB = $this->createAgency('ADM-B');
        $product = $this->createLoanProduct('ADM-P');
        $admin = $this->createUserWithRole('platform-admin');
        $agentA = $this->createUserWithRole('loan-officer', $agencyA['id']);
        $agentB = $this->createUserWithRole('loan-officer', $agencyB['id']);

        $loanA = $this->seedLoan($agencyA['id'], $product['id'], 'application', $agentA->id);
        $this->seedLoan($agencyB['id'], $product['id'], 'application', $agentB->id);

        $response = $this->actingWith($admin)->getJson('/api/v1/loans?filter[credit_agent_public_id]='.$agentA->public_id.'&per_page=100');
        $this->assertJsonSuccess($response);
        self::assertSame([$this->loanPublicId($loanA)], $this->pluck($response, 'data.loans', 'public_id'));
    }

    public function test_filter_status_and_top_level_status_produce_same_count(): void
    {
        $admin = $this->createUserWithRole('platform-admin');
        $agency = $this->createAgency('ST-A');
        $product = $this->createLoanProduct('ST-P');
        $this->seedLoan($agency['id'], $product['id'], 'application');
        $this->seedLoan($agency['id'], $product['id'], 'application');
        $this->seedLoan($agency['id'], $product['id'], 'approved');

        $topLevel = $this->actingWith($admin)->getJson('/api/v1/loans?status=application&per_page=1');
        $bracket = $this->actingWith($admin)->getJson('/api/v1/loans?filter[status]=application&per_page=1');
        $this->assertJsonSuccess($topLevel);
        $this->assertJsonSuccess($bracket);
        $topLevel->assertJsonPath('meta.pagination.total', 2);
        $bracket->assertJsonPath('meta.pagination.total', 2);
    }

    public function test_credit_agent_filter_combines_with_in_arrears_and_pagination_total(): void
    {
        $admin = $this->createUserWithRole('platform-admin');
        $agency = $this->createAgency('ARR-A');
        $product = $this->createLoanProduct('ARR-P');
        $agent = $this->createUserWithRole('loan-officer', $agency['id']);

        $this->seedLoanWithSchedule($agency['id'], $product['id'], 'active', $agent->id, [
            ['due_date' => '2026-04-01', 'principal_minor' => 5000, 'interest_minor' => 0, 'penalty_minor' => 0],
        ]);
        $this->seedLoanWithSchedule($agency['id'], $product['id'], 'active', $agent->id, [
            ['due_date' => '2026-12-01', 'principal_minor' => 3000, 'interest_minor' => 0, 'penalty_minor' => 0],
        ]);

        $response = $this->actingWith($admin)->getJson('/api/v1/loans?filter[credit_agent_public_id]='.$agent->public_id.'&filter[in_arrears]=true&per_page=1');
        $this->assertJsonSuccess($response);
        $response->assertJsonPath('meta.pagination.total', 1);
        $response->assertJsonPath('data.loans.0.days_in_arrears', fn (mixed $value): bool => is_int($value) && $value >= 30);
    }

    public function test_par_bucket_filter_uses_non_cumulative_bands_and_invalid_bucket_returns_422(): void
    {
        $admin = $this->createUserWithRole('platform-admin');
        $agency = $this->createAgency('PAR-A');
        $product = $this->createLoanProduct('PAR-P');

        $this->seedLoanWithSchedule($agency['id'], $product['id'], 'active', null, [
            ['due_date' => '2026-04-01', 'principal_minor' => 1000, 'interest_minor' => 0, 'penalty_minor' => 0],
        ]);
        $this->seedLoanWithSchedule($agency['id'], $product['id'], 'active', null, [
            ['due_date' => '2026-03-05', 'principal_minor' => 1000, 'interest_minor' => 0, 'penalty_minor' => 0],
        ]);

        $par30 = $this->actingWith($admin)->getJson('/api/v1/loans?filter[in_arrears]=true&filter[par_bucket]=30&filter[as_of_date]=2026-05-08&per_page=100');
        $this->assertJsonSuccess($par30);
        self::assertCount(1, $this->pluck($par30, 'data.loans', 'public_id'));

        $par60 = $this->actingWith($admin)->getJson('/api/v1/loans?filter[in_arrears]=true&filter[par_bucket]=60&filter[as_of_date]=2026-05-08&per_page=100');
        $this->assertJsonSuccess($par60);
        self::assertCount(1, $this->pluck($par60, 'data.loans', 'public_id'));

        $invalid = $this->actingWith($admin)->getJson('/api/v1/loans?filter[par_bucket]=45');
        $this->assertJsonError($invalid, 422);
    }

    public function test_loan_stats_match_list_scope_and_are_page_size_independent(): void
    {
        $admin = $this->createUserWithRole('platform-admin');
        $agency = $this->createAgency('STATS-A');
        $product = $this->createLoanProduct('STATS-P');
        $this->seedLoan($agency['id'], $product['id'], 'application');
        $this->seedLoan($agency['id'], $product['id'], 'application');
        $this->seedLoan($agency['id'], $product['id'], 'approved');

        $stats = $this->actingWith($admin)->getJson('/api/v1/loans/stats');
        $this->assertJsonSuccess($stats);
        $stats->assertJsonPath('data.by_status.application', 2);
        $stats->assertJsonPath('data.by_status.approved', 1);

        $list = $this->actingWith($admin)->getJson('/api/v1/loans?status=application&per_page=1');
        $list->assertJsonPath('meta.pagination.total', 2);
    }

    public function test_client_stats_return_zero_filled_keys_for_kyc_officer_scope(): void
    {
        $agency = $this->createAgency('CL-A');
        $officer = $this->createUserWithRole('kyc-officer', $agency['id']);
        $this->seedClient($agency['id'], Client::KYC_STATUS_PENDING_REVIEW);
        $this->seedClient($agency['id'], Client::KYC_STATUS_VERIFIED);

        $stats = $this->actingWith($officer)->getJson('/api/v1/clients/stats');
        $this->assertJsonSuccess($stats);
        $stats->assertJsonPath('data.by_kyc_status.pending', 1);
        $stats->assertJsonPath('data.by_kyc_status.verified', 1);
        $stats->assertJsonPath('data.by_kyc_status.rejected', 0);
    }

    public function test_journal_entry_status_filter_and_stats_are_scoped_and_page_size_independent(): void
    {
        $agency = $this->createAgency('JE-A');
        $accountant = $this->createUserWithRole('accountant', $agency['id']);
        $this->seedJournalEntry($agency['id'], JournalEntry::STATUS_SUBMITTED);
        $this->seedJournalEntry($agency['id'], JournalEntry::STATUS_SUBMITTED);
        $this->seedJournalEntry($agency['id'], JournalEntry::STATUS_DRAFT);

        $filtered = $this->actingWith($accountant)->getJson('/api/v1/journal-entries?filter[status]=submitted&per_page=1');
        $this->assertJsonSuccess($filtered);
        $filtered->assertJsonPath('meta.pagination.total', 2);

        $stats = $this->actingWith($accountant)->getJson('/api/v1/journal-entries/stats');
        $this->assertJsonSuccess($stats);
        $stats->assertJsonPath('data.by_status.submitted', 2);
        $stats->assertJsonPath('data.submitted_count', 2);
        $stats->assertJsonPath('data.by_status.cancelled', 0);

        $invalid = $this->actingWith($accountant)->getJson('/api/v1/journal-entries?filter[status]=not-valid');
        $this->assertJsonError($invalid, 422);
    }

    public function test_field_role_dashboards_are_self_scoped_and_operational_remains_restricted(): void
    {
        $agency = $this->createAgency('FLD-A');
        $officer = $this->createUserWithRole('loan-officer', $agency['id']);
        $kyc = $this->createUserWithRole('kyc-officer', $agency['id']);
        $accountant = $this->createUserWithRole('accountant', $agency['id']);

        $this->actingWith($officer)->getJson('/api/v1/dashboards/operational')->assertForbidden();
        $loanOfficer = $this->actingWith($officer)->getJson('/api/v1/dashboards/loan-officer');
        $this->assertJsonSuccess($loanOfficer);
        $loanOfficer->assertJsonPath('data.scope', 'self');

        $kycDash = $this->actingWith($kyc)->getJson('/api/v1/dashboards/kyc-officer');
        $this->assertJsonSuccess($kycDash);
        $kycDash->assertJsonPath('data.scope', 'current_agency');

        $accountantDash = $this->actingWith($accountant)->getJson('/api/v1/dashboards/accountant');
        $this->assertJsonSuccess($accountantDash);
        $accountantDash->assertJsonPath('data.scope', 'current_agency');
    }

    public function test_teller_session_summary_counts_distinct_clients_and_commissions(): void
    {
        $ctx = $this->bootstrapTellerSession('TEL-A');
        $clientB = $this->seedClient($ctx['agency_id'], Client::KYC_STATUS_VERIFIED);

        $this->insertPostedTellerTransaction($ctx['session_id'], $ctx['agency_id'], $ctx['client_id'], 1000, false);
        $this->insertPostedTellerTransaction($ctx['session_id'], $ctx['agency_id'], $ctx['client_id'], 2000, true, 250);
        $this->insertPostedTellerTransaction($ctx['session_id'], $ctx['agency_id'], $clientB, 1500, false);
        $this->insertPostedTellerTransaction($ctx['session_id'], $ctx['agency_id'], $ctx['client_id'], 500, false, 0, TellerTransaction::STATUS_CANCELLED);

        $response = $this->actingWith($ctx['teller'])->getJson('/api/v1/teller-sessions/'.$ctx['session_public_id']);
        $this->assertJsonSuccess($response);
        $response->assertJsonPath('data.summary.distinct_clients_served_count', 2);
        $response->assertJsonPath('data.summary.commissions_total_minor', 250);
    }

    public function test_awaiting_disbursement_filter_stats_and_accountant_dashboard_stay_aligned(): void
    {
        $home = $this->createAgency('DIS-HOME');
        $other = $this->createAgency('DIS-OTHER');
        $product = $this->createLoanProduct('DIS-P');
        $blockingProduct = $this->createLoanProductWithSetupCharges('DIS-BLOCK');
        $accountant = $this->createUserWithRole('accountant', $home['id']);

        $this->seedDisbursementMapping($home['id']);
        $this->seedDisbursementMapping($other['id']);

        $readyLoanId = $this->seedLoan($home['id'], $product['id'], Loan::STATUS_APPROVED);
        $blockingLoanId = $this->seedLoan($home['id'], $blockingProduct['id'], Loan::STATUS_APPROVED);
        $this->seedBlockingSetupCharge($blockingLoanId);
        $activeLoanId = $this->seedLoan($home['id'], $product['id'], Loan::STATUS_ACTIVE);
        $alreadyDisbursedLoanId = $this->seedLoan($home['id'], $product['id'], Loan::STATUS_APPROVED);
        $this->seedPostedDisbursement($alreadyDisbursedLoanId, $home['id'], $accountant->id);
        $this->seedLoan($other['id'], $product['id'], Loan::STATUS_APPROVED);

        $list = $this->actingWith($accountant)->getJson('/api/v1/loans?filter[awaiting_disbursement]=true&per_page=1');
        $this->assertJsonSuccess($list);
        $list->assertJsonPath('meta.pagination.total', 1);
        self::assertSame([$this->loanPublicId($readyLoanId)], $this->pluck($list, 'data.loans', 'public_id'));

        $stats = $this->actingWith($accountant)->getJson('/api/v1/loans/stats');
        $this->assertJsonSuccess($stats);
        $stats->assertJsonPath('data.awaiting_disbursement_count', 1);

        $accountantDash = $this->actingWith($accountant)->getJson('/api/v1/dashboards/accountant');
        $this->assertJsonSuccess($accountantDash);
        $accountantDash->assertJsonPath('data.awaiting_disbursement_count', 1);

        self::assertNotContains($this->loanPublicId($blockingLoanId), $this->pluck($list, 'data.loans', 'public_id'));
        self::assertNotContains($this->loanPublicId($activeLoanId), $this->pluck($list, 'data.loans', 'public_id'));
        self::assertNotContains($this->loanPublicId($alreadyDisbursedLoanId), $this->pluck($list, 'data.loans', 'public_id'));
    }

    public function test_platform_admin_is_denied_field_role_dashboard_endpoints(): void
    {
        $admin = $this->createUserWithRole('platform-admin');

        $this->actingWith($admin)->getJson('/api/v1/dashboards/loan-officer')->assertForbidden();
        $this->actingWith($admin)->getJson('/api/v1/dashboards/kyc-officer')->assertForbidden();
        $this->actingWith($admin)->getJson('/api/v1/dashboards/accountant')->assertForbidden();
        $this->actingWith($admin)->getJson('/api/v1/dashboards/regional')->assertForbidden();
    }

    // ---- GHI-012E: cross-user / cross-agency leakage for every field role ----

    public function test_loan_officer_dashboard_excludes_other_officers_loans(): void
    {
        $agency = $this->createAgency('LOD-A');
        $product = $this->createLoanProduct('LOD-P');
        $officer = $this->createUserWithRole('loan-officer', $agency['id']);
        $other = $this->createUserWithRole('loan-officer', $agency['id']);

        $this->seedLoan($agency['id'], $product['id'], Loan::STATUS_ACTIVE, $officer->id);
        $this->seedLoan($agency['id'], $product['id'], Loan::STATUS_ACTIVE, $officer->id);
        $this->seedLoan($agency['id'], $product['id'], Loan::STATUS_ACTIVE, $other->id);

        $dash = $this->actingWith($officer)->getJson('/api/v1/dashboards/loan-officer');
        $this->assertJsonSuccess($dash);
        $dash->assertJsonPath('data.scope', 'self');
        $dash->assertJsonPath('data.active_loan_count', 2);
        $dash->assertJsonPath('data.by_status.active', 2);
    }

    public function test_kyc_officer_dashboard_excludes_other_agency_clients(): void
    {
        $home = $this->createAgency('KOD-A');
        $other = $this->createAgency('KOD-B');
        $officer = $this->createUserWithRole('kyc-officer', $home['id']);

        $this->seedClient($home['id'], Client::KYC_STATUS_PENDING_REVIEW);
        $this->seedClient($other['id'], Client::KYC_STATUS_PENDING_REVIEW);

        $dash = $this->actingWith($officer)->getJson('/api/v1/dashboards/kyc-officer');
        $this->assertJsonSuccess($dash);
        $dash->assertJsonPath('data.by_kyc_status.pending', 1);
    }

    public function test_accountant_dashboard_excludes_other_agency_journals(): void
    {
        $home = $this->createAgency('AOD-A');
        $other = $this->createAgency('AOD-B');
        $accountant = $this->createUserWithRole('accountant', $home['id']);

        $this->seedJournalEntry($home['id'], JournalEntry::STATUS_SUBMITTED);
        $this->seedJournalEntry($home['id'], JournalEntry::STATUS_SUBMITTED);
        $this->seedJournalEntry($other['id'], JournalEntry::STATUS_SUBMITTED);

        $dash = $this->actingWith($accountant)->getJson('/api/v1/dashboards/accountant');
        $this->assertJsonSuccess($dash);
        $dash->assertJsonPath('data.submitted_journal_count', 2);
    }

    public function test_regional_dashboard_only_includes_assigned_agencies(): void
    {
        $assigned = $this->createAgency('REG-A');
        $unassigned = $this->createAgency('REG-B');
        $product = $this->createLoanProduct('REG-P');
        $manager = $this->createUserWithRole('regional-manager', $assigned['id']);

        $this->seedLoan($assigned['id'], $product['id'], Loan::STATUS_ACTIVE);
        $this->seedLoan($assigned['id'], $product['id'], Loan::STATUS_ACTIVE);
        $this->seedLoan($unassigned['id'], $product['id'], Loan::STATUS_ACTIVE);

        $dash = $this->actingWith($manager)->getJson('/api/v1/dashboards/regional');
        $this->assertJsonSuccess($dash);
        $dash->assertJsonPath('data.scope', 'assigned_region');
        self::assertSame([$assigned['public_id']], $this->pluck($dash, 'data.agencies', 'agency_public_id'));
        $dash->assertJsonPath('data.active_loan_count', 2);
    }

    // ---- GHI-012F: cross-teller session summary denial ----

    public function test_teller_cannot_read_another_tellers_session_summary(): void
    {
        $ctx = $this->bootstrapTellerSession('TEL-OWN');
        $otherAgency = $this->createAgency('TEL-OTHER');
        $otherTeller = $this->createUserWithRole('teller', $otherAgency['id']);

        $this->actingWith($otherTeller)
            ->getJson('/api/v1/teller-sessions/'.$ctx['session_public_id'])
            ->assertForbidden();
    }

    // ---- GHI-012D: unauthorized denial + journal stats agency scope ----

    public function test_journal_entry_stats_denies_unauthorized_user(): void
    {
        $agency = $this->createAgency('JEU-A');
        $user = $this->createActiveUserWithoutRole($agency['id']);

        $this->actingWith($user)->getJson('/api/v1/journal-entries/stats')->assertForbidden();
        $this->actingWith($user)->getJson('/api/v1/journal-entries')->assertForbidden();
    }

    public function test_journal_entry_stats_are_agency_scoped(): void
    {
        $home = $this->createAgency('JES-A');
        $other = $this->createAgency('JES-B');
        $accountant = $this->createUserWithRole('accountant', $home['id']);

        $this->seedJournalEntry($home['id'], JournalEntry::STATUS_SUBMITTED);
        $this->seedJournalEntry($other['id'], JournalEntry::STATUS_SUBMITTED);

        $stats = $this->actingWith($accountant)->getJson('/api/v1/journal-entries/stats');
        $this->assertJsonSuccess($stats);
        $stats->assertJsonPath('data.by_status.submitted', 1);
    }

    // ---- GHI-012B: PAR90, overdue amount, multi-line aggregation, scope ----

    public function test_par_bucket_90_aggregates_overdue_lines_once(): void
    {
        $admin = $this->createUserWithRole('platform-admin');
        $agency = $this->createAgency('P90-A');
        $product = $this->createLoanProduct('P90-P');

        $loanId = $this->seedLoanWithSchedule($agency['id'], $product['id'], Loan::STATUS_ACTIVE, null, [
            ['due_date' => '2026-01-01', 'principal_minor' => 5000, 'interest_minor' => 0, 'penalty_minor' => 0],
            ['due_date' => '2026-02-01', 'principal_minor' => 3000, 'interest_minor' => 0, 'penalty_minor' => 0],
        ]);

        $res = $this->actingWith($admin)->getJson('/api/v1/loans?filter[in_arrears]=true&filter[par_bucket]=90&filter[as_of_date]=2026-05-08&per_page=100');
        $this->assertJsonSuccess($res);
        self::assertSame([$this->loanPublicId($loanId)], $this->pluck($res, 'data.loans', 'public_id'));
        $res->assertJsonPath('data.loans.0.par_bucket', 90);
        $res->assertJsonPath('data.loans.0.overdue_amount_minor', 8000);
        $res->assertJsonPath('data.loans.0.days_in_arrears', fn (mixed $value): bool => is_int($value) && $value >= 90);
    }

    public function test_in_arrears_filter_cannot_widen_loan_officer_scope(): void
    {
        $agency = $this->createAgency('AW-A');
        $product = $this->createLoanProduct('AW-P');
        $officer = $this->createUserWithRole('loan-officer', $agency['id']);
        $other = $this->createUserWithRole('loan-officer', $agency['id']);

        $otherLoan = $this->seedLoanWithSchedule($agency['id'], $product['id'], Loan::STATUS_ACTIVE, $other->id, [
            ['due_date' => '2026-01-01', 'principal_minor' => 5000, 'interest_minor' => 0, 'penalty_minor' => 0],
        ]);

        $res = $this->actingWith($officer)->getJson('/api/v1/loans?filter[in_arrears]=true&filter[as_of_date]=2026-05-08&per_page=100');
        $this->assertJsonSuccess($res);
        self::assertNotContains($this->loanPublicId($otherLoan), $this->pluck($res, 'data.loans', 'public_id'));
        $res->assertJsonPath('meta.pagination.total', 0);
    }

    public function test_written_off_loan_excluded_from_arrears_matching_dashboard_definition(): void
    {
        $admin = $this->createUserWithRole('platform-admin');
        $agency = $this->createAgency('WO-A');
        $product = $this->createLoanProduct('WO-P');

        $writtenOff = $this->seedLoanWithSchedule($agency['id'], $product['id'], Loan::STATUS_WRITTEN_OFF, null, [
            ['due_date' => '2026-01-01', 'principal_minor' => 5000, 'interest_minor' => 0, 'penalty_minor' => 0],
        ]);

        $list = $this->actingWith($admin)->getJson('/api/v1/loans?filter[in_arrears]=true&filter[as_of_date]=2026-05-08&per_page=100');
        $this->assertJsonSuccess($list);
        self::assertNotContains($this->loanPublicId($writtenOff), $this->pluck($list, 'data.loans', 'public_id'));

        $stats = $this->actingWith($admin)->getJson('/api/v1/loans/stats?filter[as_of_date]=2026-05-08');
        $this->assertJsonSuccess($stats);
        $stats->assertJsonPath('data.in_arrears_count', 0);
        $stats->assertJsonPath('data.par_buckets.par90', 0);
        $stats->assertJsonPath('data.by_status.written_off', 1);
    }

    // ---- GHI-012C: unsupported filters and role-scoped stats ----

    public function test_loan_stats_rejects_unsupported_filter(): void
    {
        $admin = $this->createUserWithRole('platform-admin');
        $res = $this->actingWith($admin)->getJson('/api/v1/loans/stats?filter[bogus]=1');
        $this->assertJsonError($res, 422);
    }

    public function test_client_stats_rejects_unsupported_filter(): void
    {
        $agency = $this->createAgency('CSU-A');
        $officer = $this->createUserWithRole('kyc-officer', $agency['id']);
        $res = $this->actingWith($officer)->getJson('/api/v1/clients/stats?filter[bogus]=1');
        $this->assertJsonError($res, 422);
    }

    public function test_loan_stats_are_self_scoped_for_loan_officer(): void
    {
        $agency = $this->createAgency('LSS-A');
        $product = $this->createLoanProduct('LSS-P');
        $officer = $this->createUserWithRole('loan-officer', $agency['id']);
        $other = $this->createUserWithRole('loan-officer', $agency['id']);

        $this->seedLoan($agency['id'], $product['id'], 'application', $officer->id);
        $this->seedLoan($agency['id'], $product['id'], 'application', $other->id);
        $this->seedLoan($agency['id'], $product['id'], 'application', $other->id);

        $stats = $this->actingWith($officer)->getJson('/api/v1/loans/stats');
        $this->assertJsonSuccess($stats);
        $stats->assertJsonPath('data.by_status.application', 1);
    }

    public function test_loan_stats_by_status_is_a_full_partition(): void
    {
        $admin = $this->createUserWithRole('platform-admin');
        $agency = $this->createAgency('PART-A');
        $product = $this->createLoanProduct('PART-P');

        $this->seedLoan($agency['id'], $product['id'], Loan::STATUS_ACTIVE);
        $this->seedLoan($agency['id'], $product['id'], Loan::STATUS_RESCHEDULED);
        $this->seedLoan($agency['id'], $product['id'], Loan::STATUS_WRITTEN_OFF);

        $stats = $this->actingWith($admin)->getJson('/api/v1/loans/stats');
        $this->assertJsonSuccess($stats);
        $stats->assertJsonPath('data.by_status.active', 1);
        $stats->assertJsonPath('data.by_status.rescheduled', 1);
        $stats->assertJsonPath('data.by_status.written_off', 1);
    }

    /**
     * @return array{id:int, public_id:string}
     */
    private function createLoanProductWithSetupCharges(string $code): array
    {
        $publicId = (string) Str::ulid();
        $id = DB::table('loan_products')->insertGetId([
            'public_id' => $publicId,
            'code' => $code,
            'name' => 'Product '.$code,
            'status' => 'active',
            'rules' => json_encode([
                'setup_charges' => [
                    'dossier_fee_rate' => '3.000000',
                ],
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return ['id' => $id, 'public_id' => $publicId];
    }

    private function seedDisbursementMapping(int $agencyId): void
    {
        $ledgerId = DB::table('ledger_accounts')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'agency_id' => $agencyId,
            'code' => 'LOAN-'.Str::ulid(),
            'name' => 'Loan Principal',
            'account_class' => 'asset',
            'normal_balance_side' => 'debit',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $operationCodeId = DB::table('operation_codes')->where('code', 'loan_principal_disbursement')->value('id');
        if (! is_int($operationCodeId)) {
            $operationCodeId = DB::table('operation_codes')->insertGetId([
                'public_id' => (string) Str::ulid(),
                'code' => 'loan_principal_disbursement',
                'label' => 'loan principal disbursement',
                'module' => 'loan',
                'operation_type' => 'posting',
                'direction' => 'debit_credit',
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DB::table('operation_account_mappings')->insert([
            'public_id' => (string) Str::ulid(),
            'operation_code_id' => $operationCodeId,
            'agency_id' => $agencyId,
            'debit_ledger_account_id' => $ledgerId,
            'credit_ledger_account_id' => null,
            'currency' => 'XAF',
            'status' => 'active',
            'approval_status' => 'approved',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function seedBlockingSetupCharge(int $loanId): void
    {
        DB::table('loan_charge_assessments')->insert([
            'public_id' => (string) Str::ulid(),
            'loan_id' => $loanId,
            'charge_type' => 'dossier_fee',
            'assessed_amount_minor' => 3000,
            'currency' => 'XAF',
            'status' => 'assessed',
            'paid_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function seedPostedDisbursement(int $loanId, int $agencyId, int $postedByUserId): void
    {
        $journalEntryId = DB::table('journal_entries')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'reference' => 'DISB-'.Str::ulid(),
            'business_date' => now()->toDateString(),
            'agency_id' => $agencyId,
            'source_module' => 'credit',
            'source_type' => 'loan_disbursement',
            'status' => JournalEntry::STATUS_POSTED,
            'posted_at' => now(),
            'posted_by_user_id' => $postedByUserId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('loan_disbursements')->insert([
            'public_id' => (string) Str::ulid(),
            'agency_id' => $agencyId,
            'loan_id' => $loanId,
            'journal_entry_id' => $journalEntryId,
            'disbursement_channel' => 'cash',
            'principal_amount_minor' => 10000,
            'currency' => 'XAF',
            'status' => 'posted',
            'posted_at' => now(),
            'posted_by_user_id' => $postedByUserId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function actingWith(User $actor): self
    {
        return $this->withApiHeaders()->actingAsSanctum($actor);
    }

    private function createUserWithRole(string $role, ?int $agencyId = null): User
    {
        $user = User::factory()->createOne([
            'status' => User::STATUS_ACTIVE,
            'phone_verified_at' => now(),
            'agency_id' => $agencyId,
        ]);
        $user->assignRole($role);
        if ($agencyId !== null) {
            $this->assignStaffToAgency($user, $agencyId, $role);
        }

        return $user;
    }

    private function createActiveUserWithoutRole(?int $agencyId = null): User
    {
        return User::factory()->createOne([
            'status' => User::STATUS_ACTIVE,
            'phone_verified_at' => now(),
            'agency_id' => $agencyId,
        ]);
    }

    private function assignStaffToAgency(User $user, int $agencyId, string $role): void
    {
        StaffAgencyAssignment::query()->create([
            'public_id' => (string) Str::ulid(),
            'user_id' => $user->id,
            'agency_id' => $agencyId,
            'role_at_agency' => $role,
            'starts_on' => now()->subDay()->toDateString(),
            'is_primary' => true,
            'status' => StaffAgencyAssignment::STATUS_ACTIVE,
        ]);
    }

    /**
     * @return array{id:int, public_id:string, code:string, name:string}
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

        return ['id' => $id, 'public_id' => $publicId, 'code' => $code, 'name' => 'Agency '.$code];
    }

    /**
     * @return array{id:int, public_id:string}
     */
    private function createLoanProduct(string $code): array
    {
        $publicId = (string) Str::ulid();
        $id = DB::table('loan_products')->insertGetId([
            'public_id' => $publicId,
            'code' => $code,
            'name' => 'Product '.$code,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return ['id' => $id, 'public_id' => $publicId];
    }

    private function seedClient(int $agencyId, string $kycStatus = Client::KYC_STATUS_VERIFIED): int
    {
        return DB::table('clients')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'agency_id' => $agencyId,
            'client_reference' => 'CLI-'.Str::ulid(),
            'first_name' => 'Test',
            'last_name' => 'Client',
            'status' => Client::STATUS_ACTIVE,
            'kyc_status' => $kycStatus,
            'phone_number' => '+2376000'.random_int(10000, 99999),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function seedLoan(int $agencyId, int $productId, string $status, ?int $creditAgentId = null): int
    {
        $clientId = $this->seedClient($agencyId);

        return DB::table('loans')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'client_id' => $clientId,
            'agency_id' => $agencyId,
            'loan_product_id' => $productId,
            'credit_agent_id' => $creditAgentId,
            'loan_number' => 'LOAN-'.Str::ulid(),
            'requested_amount_minor' => 10000,
            'approved_principal_minor' => 10000,
            'currency' => 'XAF',
            'applied_on' => '2026-01-01',
            'status' => $status,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @param  list<array{due_date:string, principal_minor:int, interest_minor:int, penalty_minor:int}>  $lines
     */
    private function seedLoanWithSchedule(int $agencyId, int $productId, string $status, ?int $creditAgentId, array $lines, ?string $asOfIgnored = null): int
    {
        $loanId = $this->seedLoan($agencyId, $productId, $status, $creditAgentId);
        $snapshotId = DB::table('loan_schedule_snapshots')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'loan_id' => $loanId,
            'formula_engine_key' => 'test',
            'formula_engine_version' => '1',
            'policy_snapshot_hash' => str_repeat('a', 64),
            'generated_at' => now(),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        foreach ($lines as $index => $line) {
            DB::table('loan_schedule_lines')->insert([
                'loan_schedule_snapshot_id' => $snapshotId,
                'installment_number' => $index + 1,
                'due_date' => $line['due_date'],
                'principal_minor' => $line['principal_minor'],
                'interest_minor' => $line['interest_minor'],
                'fees_minor' => 0,
                'insurance_minor' => 0,
                'tax_minor' => 0,
                'penalty_minor' => $line['penalty_minor'],
                'currency' => 'XAF',
                'status' => 'scheduled',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $loanId;
    }

    private function seedJournalEntry(int $agencyId, string $status): void
    {
        DB::table('journal_entries')->insert([
            'public_id' => (string) Str::ulid(),
            'reference' => 'JE-'.Str::upper(Str::random(6)),
            'business_date' => now()->toDateString(),
            'agency_id' => $agencyId,
            'status' => $status,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @return array{agency_id:int, session_id:int, session_public_id:string, client_id:int, teller:User}
     */
    private function bootstrapTellerSession(string $code): array
    {
        $agency = $this->createAgency($code);
        $teller = $this->createUserWithRole('teller', $agency['id']);
        $clientId = $this->seedClient($agency['id']);
        $ledgerId = DB::table('ledger_accounts')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'agency_id' => $agency['id'],
            'code' => 'TILL-'.Str::ulid(),
            'name' => 'Till Cash',
            'account_class' => 'asset',
            'normal_balance_side' => 'debit',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $tillId = DB::table('tills')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'agency_id' => $agency['id'],
            'ledger_account_id' => $ledgerId,
            'code' => 'T-'.$code,
            'name' => 'Till '.$code,
            'currency' => 'XAF',
            'status' => 'active',
            'daily_state' => 'open',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $sessionPublicId = (string) Str::ulid();
        $sessionId = DB::table('teller_sessions')->insertGetId([
            'public_id' => $sessionPublicId,
            'till_id' => $tillId,
            'agency_id' => $agency['id'],
            'teller_user_id' => $teller->id,
            'business_date' => now()->toDateString(),
            'opened_at' => now(),
            'opening_declaration_minor' => 0,
            'currency' => 'XAF',
            'status' => TellerSession::STATUS_OPEN,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [
            'agency_id' => $agency['id'],
            'session_id' => $sessionId,
            'session_public_id' => $sessionPublicId,
            'client_id' => $clientId,
            'teller' => $teller,
        ];
    }

    private function insertPostedTellerTransaction(
        int $sessionId,
        int $agencyId,
        int $clientId,
        int $amountMinor,
        bool $feesApplied,
        int $feeAmountMinor = 0,
        string $status = TellerTransaction::STATUS_POSTED,
    ): void {
        DB::table('teller_transactions')->insert([
            'public_id' => (string) Str::ulid(),
            'teller_session_id' => $sessionId,
            'agency_id' => $agencyId,
            'transaction_date' => now()->toDateString(),
            'transaction_type' => TellerTransaction::TYPE_CASH_DEPOSIT,
            'client_id' => $clientId,
            'amount_minor' => $amountMinor,
            'cash_amount_minor' => $amountMinor,
            'currency' => 'XAF',
            'status' => $status,
            'reference' => 'TT-'.Str::ulid(),
            'fees_applied' => $feesApplied,
            'fee_amount_minor' => $feeAmountMinor,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function loanPublicId(int $loanId): string
    {
        $value = DB::table('loans')->where('id', $loanId)->value('public_id');
        self::assertIsString($value);

        return $value;
    }

    /**
     * @return list<string>
     */
    private function pluck(TestResponse $response, string $path, string $key): array
    {
        $rows = $response->json($path);
        self::assertIsArray($rows);
        $values = [];
        foreach ($rows as $row) {
            self::assertIsArray($row);
            self::assertArrayHasKey($key, $row);
            self::assertIsString($row[$key]);
            $values[] = $row[$key];
        }

        return $values;
    }
}
