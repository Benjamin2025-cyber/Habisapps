<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\LedgerAccount;
use App\Models\TellerTransaction;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

final class Module5CashInfrastructureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_platform_admin_can_manage_denominations_without_creating_cash_workflow_records(): void
    {
        $actor = $this->createUserWithRole('platform-admin');

        $create = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('denomination-create')->plainTextToken])
            ->postJson('/api/v1/denominations', [
                'code' => 'XAF-10000',
                'label' => 'Billet 10 000 XAF',
                'value_minor' => 10000,
                'currency' => 'xaf',
                'type' => 'banknote',
                'status' => 'active',
            ]);

        $this->assertJsonSuccess($create, 201);
        $create->assertJsonPath('data.public_id', fn (mixed $value): bool => is_string($value) && $value !== '');
        $create->assertJsonPath('data.code', 'XAF-10000');
        $create->assertJsonPath('data.currency', 'XAF');
        $create->assertJsonPath('data.value_minor', 10000);
        $create->assertJsonMissingPath('data.id');

        $publicId = $this->requireStringJsonPath($create, 'data.public_id');

        $show = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('denomination-show')->plainTextToken])
            ->getJson('/api/v1/denominations/'.$publicId);

        $this->assertJsonSuccess($show);
        $show->assertJsonPath('data.public_id', $publicId);

        $update = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('denomination-update')->plainTextToken])
            ->patchJson('/api/v1/denominations/'.$publicId, [
                'status' => 'inactive',
                'label' => 'Billet 10000 XAF inactive',
            ]);

        $this->assertJsonSuccess($update);
        $update->assertJsonPath('data.status', 'inactive');

        $this->assertDatabaseHas('activity_log', [
            'event' => 'cash.denomination.created',
        ]);
        $this->assertNoCashWorkflowRecords();
    }

    public function test_denomination_validation_and_authorization_are_fail_closed(): void
    {
        $admin = $this->createUserWithRole('platform-admin');
        $staff = $this->createUserWithRole('staff');

        $this->postJson('/api/v1/denominations', [
            'code' => 'XAF-500',
            'label' => 'Piece 500 XAF',
            'value_minor' => 500,
            'currency' => 'XAF',
            'type' => 'coin',
        ])->assertUnauthorized();

        $this->withApiHeaders(['Authorization' => 'Bearer '.$staff->createToken('denomination-staff')->plainTextToken])
            ->postJson('/api/v1/denominations', [
                'code' => 'XAF-500',
                'label' => 'Piece 500 XAF',
                'value_minor' => 500,
                'currency' => 'XAF',
                'type' => 'coin',
            ])->assertForbidden();

        $invalid = $this->actingAsSanctum($admin)
            ->postJson('/api/v1/denominations', [
                'code' => 'XAF-0',
                'label' => 'Invalid',
                'value_minor' => 0,
                'currency' => 'XAF',
                'type' => 'coin',
            ]);
        $invalid->assertStatus(422);
        $invalid->assertJsonValidationErrors(['value_minor']);

        $first = $this->withApiHeaders(['Authorization' => 'Bearer '.$admin->createToken('denomination-first')->plainTextToken])
            ->postJson('/api/v1/denominations', [
                'code' => 'XAF-1000',
                'label' => 'Billet 1000 XAF',
                'value_minor' => 1000,
                'currency' => 'XAF',
                'type' => 'banknote',
            ]);
        $this->assertJsonSuccess($first, 201);

        $duplicateCode = $this->withApiHeaders(['Authorization' => 'Bearer '.$admin->createToken('denomination-duplicate-code')->plainTextToken])
            ->postJson('/api/v1/denominations', [
                'code' => 'XAF-1000',
                'label' => 'Duplicate code',
                'value_minor' => 2000,
                'currency' => 'XAF',
                'type' => 'banknote',
            ]);
        $duplicateCode->assertStatus(422);
        $duplicateCode->assertJsonValidationErrors(['code']);

        $duplicateValue = $this->withApiHeaders(['Authorization' => 'Bearer '.$admin->createToken('denomination-duplicate-value')->plainTextToken])
            ->postJson('/api/v1/denominations', [
                'code' => 'XAF-1000-ALT',
                'label' => 'Duplicate value',
                'value_minor' => 1000,
                'currency' => 'XAF',
                'type' => 'banknote',
            ]);
        $duplicateValue->assertStatus(422);
        $duplicateValue->assertJsonValidationErrors(['value_minor']);
    }

    public function test_agency_manager_can_manage_tills_only_inside_active_agency_scope(): void
    {
        $agencyA = $this->createAgency('CASH-A');
        $agencyB = $this->createAgency('CASH-B');
        $actor = $this->createUserWithRole('agency-manager', $agencyA['code'], $agencyA['name']);
        $assignedUser = $this->createUserWithRole('teller', $agencyA['code'], $agencyA['name']);
        $otherAgencyUser = $this->createUserWithRole('teller', $agencyB['code'], $agencyB['name']);
        $cashLedger = $this->createLedgerAccount($agencyA['id'], 'CASH-TILL-01', LedgerAccount::ACCOUNT_CLASS_ASSET);

        $create = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('till-create')->plainTextToken])
            ->postJson('/api/v1/tills', [
                'code' => 'TILL-01',
                'name' => 'Front Office Till',
                'type' => 'counter',
                'status' => 'active',
                'daily_state' => 'closed',
                'opening_balance_minor' => 125000,
                'last_closing_balance_minor' => 100000,
                'requires_denominations' => true,
                'nature' => 'front_office',
                'is_central_till' => false,
                'max_balance_limit_minor' => 5000000,
                'max_withdrawal_limit_minor' => 250000,
                'currency' => 'xaf',
                'assigned_user_public_id' => $assignedUser->public_id,
                'ledger_account_public_id' => $cashLedger['public_id'],
            ]);

        $this->assertJsonSuccess($create, 201);
        $create->assertJsonPath('data.agency_public_id', $agencyA['public_id']);
        $create->assertJsonPath('data.assigned_user_public_id', $assignedUser->public_id);
        $create->assertJsonPath('data.ledger_account_public_id', $cashLedger['public_id']);
        $create->assertJsonPath('data.daily_state', 'closed');
        $create->assertJsonPath('data.opening_balance_minor', 125000);
        $create->assertJsonPath('data.last_closing_balance_minor', 100000);
        $create->assertJsonPath('data.requires_denominations', true);
        $create->assertJsonPath('data.nature', 'front_office');
        $create->assertJsonPath('data.is_central_till', false);
        $create->assertJsonPath('data.max_balance_limit_minor', 5000000);
        $create->assertJsonPath('data.max_withdrawal_limit_minor', 250000);
        $create->assertJsonPath('data.currency', 'XAF');
        $create->assertJsonMissingPath('data.id');
        $create->assertJsonMissingPath('data.agency_id');
        $create->assertJsonMissingPath('data.assigned_user_id');
        $create->assertJsonMissingPath('data.ledger_account_id');

        $tillPublicId = $this->requireStringJsonPath($create, 'data.public_id');

        $show = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('till-show')->plainTextToken])
            ->getJson('/api/v1/tills/'.$tillPublicId);
        $this->assertJsonSuccess($show);

        $update = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('till-update')->plainTextToken])
            ->patchJson('/api/v1/tills/'.$tillPublicId, [
                'status' => 'inactive',
                'name' => 'Inactive Front Office Till',
                'requires_denominations' => false,
                'max_withdrawal_limit_minor' => 200000,
            ]);
        $this->assertJsonSuccess($update);
        $update->assertJsonPath('data.status', 'inactive');
        $update->assertJsonPath('data.requires_denominations', false);
        $update->assertJsonPath('data.max_withdrawal_limit_minor', 200000);

        $crossAgencyCreate = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('till-cross-agency')->plainTextToken])
            ->postJson('/api/v1/tills', [
                'agency_public_id' => $agencyB['public_id'],
                'code' => 'TILL-B',
                'name' => 'Other Agency Till',
            ]);
        $crossAgencyCreate->assertForbidden();

        $otherUserAssign = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('till-other-user')->plainTextToken])
            ->patchJson('/api/v1/tills/'.$tillPublicId, [
                'assigned_user_public_id' => $otherAgencyUser->public_id,
            ]);
        $otherUserAssign->assertStatus(422);
        $otherUserAssign->assertJsonValidationErrors(['assigned_user_public_id']);

        $this->assertDatabaseHas('activity_log', [
            'event' => 'cash.till.created',
        ]);
        $this->assertNoCashWorkflowRecords();
    }

    public function test_till_setup_rejects_duplicates_invalid_configuration_and_cross_agency_access(): void
    {
        $agencyA = $this->createAgency('CASH-C');
        $agencyB = $this->createAgency('CASH-D');
        $actor = $this->createUserWithRole('agency-manager', $agencyA['code'], $agencyA['name']);
        $otherActor = $this->createUserWithRole('agency-manager', $agencyB['code'], $agencyB['name']);
        $liabilityLedger = $this->createLedgerAccount($agencyA['id'], 'CASH-LIABILITY', LedgerAccount::ACCOUNT_CLASS_LIABILITY);
        $crossAgencyLedger = $this->createLedgerAccount($agencyB['id'], 'CASH-CROSS', LedgerAccount::ACCOUNT_CLASS_ASSET);

        $create = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('till-create-a')->plainTextToken])
            ->postJson('/api/v1/tills', [
                'code' => 'TILL-DUP',
                'name' => 'Till Duplicate A',
            ]);
        $this->assertJsonSuccess($create, 201);
        $tillPublicId = $this->requireStringJsonPath($create, 'data.public_id');

        $duplicate = $this->withApiHeaders([
            'Authorization' => 'Bearer '.$actor->createToken('till-duplicate')->plainTextToken,
            'X-Locale' => 'fr',
        ])
            ->postJson('/api/v1/tills', [
                'code' => 'TILL-DUP',
                'name' => 'Till Duplicate B',
            ]);
        $duplicate->assertStatus(422);
        $duplicate->assertJsonValidationErrors(['code']);
        $duplicate->assertJsonPath('errors.code.0', 'Le code est déjà utilisé pour cette agence.');

        $invalidLedgerClass = $this->withApiHeaders([
            'Authorization' => 'Bearer '.$actor->createToken('till-invalid-ledger')->plainTextToken,
            'X-Locale' => 'fr',
        ])
            ->postJson('/api/v1/tills', [
                'code' => 'TILL-BAL',
                'name' => 'Invalid Ledger Till',
                'ledger_account_public_id' => $liabilityLedger['public_id'],
            ]);
        $invalidLedgerClass->assertStatus(422);
        $invalidLedgerClass->assertJsonValidationErrors(['ledger_account_public_id']);
        $invalidLedgerClass->assertJsonPath('errors.ledger_account_public_id.0', 'Le compte du grand livre sélectionné doit être un compte du grand livre d\'actif actif dans l\'agence de la caisse.');

        $crossAgencyLedgerResponse = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('till-cross-ledger')->plainTextToken])
            ->postJson('/api/v1/tills', [
                'code' => 'TILL-CROSS-LEDGER',
                'name' => 'Cross Agency Ledger Till',
                'ledger_account_public_id' => $crossAgencyLedger['public_id'],
            ]);
        $crossAgencyLedgerResponse->assertStatus(422);
        $crossAgencyLedgerResponse->assertJsonValidationErrors(['ledger_account_public_id']);

        $invalidLimit = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('till-invalid-limit')->plainTextToken])
            ->postJson('/api/v1/tills', [
                'code' => 'TILL-LIMIT',
                'name' => 'Invalid Limit Till',
                'max_balance_limit_minor' => -1,
            ]);
        $invalidLimit->assertStatus(422);
        $invalidLimit->assertJsonValidationErrors(['max_balance_limit_minor']);

        $crossAgencyShow = $this->actingAsSanctum($otherActor)
            ->getJson('/api/v1/tills/'.$tillPublicId);
        $crossAgencyShow->assertForbidden();

        $crossAgencyUpdate = $this->actingAsSanctum($otherActor)
            ->patchJson('/api/v1/tills/'.$tillPublicId, [
                'name' => 'Compromised',
            ]);
        $crossAgencyUpdate->assertForbidden();

        $this->assertNoCashWorkflowRecords();
    }

    public function test_till_assignment_requires_teller_role_and_one_active_till_per_teller(): void
    {
        $agency = $this->createAgency('CASH-F');
        $actor = $this->createUserWithRole('agency-manager', $agency['code'], $agency['name']);
        $teller = $this->createUserWithRole('teller', $agency['code'], $agency['name']);
        $managerUser = $this->createUserWithRole('agency-manager', $agency['code'], $agency['name']);

        $managerAssignment = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('till-manager-assignment')->plainTextToken])
            ->postJson('/api/v1/tills', [
                'code' => 'TILL-MANAGER',
                'name' => 'Manager Till',
                'assigned_user_public_id' => $managerUser->public_id,
            ]);
        $managerAssignment->assertStatus(422);
        $managerAssignment->assertJsonValidationErrors(['assigned_user_public_id']);

        $first = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('till-first-active')->plainTextToken])
            ->postJson('/api/v1/tills', [
                'code' => 'TILL-FIRST',
                'name' => 'First Active Till',
                'assigned_user_public_id' => $teller->public_id,
            ]);
        $this->assertJsonSuccess($first, 201);

        $duplicateActive = $this->withApiHeaders([
            'Authorization' => 'Bearer '.$actor->createToken('till-duplicate-active')->plainTextToken,
            'X-Locale' => 'fr',
        ])
            ->postJson('/api/v1/tills', [
                'code' => 'TILL-DUP-ACTIVE',
                'name' => 'Duplicate Active Till',
                'assigned_user_public_id' => $teller->public_id,
            ]);
        $duplicateActive->assertStatus(422);
        $duplicateActive->assertJsonValidationErrors(['assigned_user_public_id']);
        $duplicateActive->assertJsonPath('errors.assigned_user_public_id.0', 'Le guichetier sélectionné est déjà affecté à une autre caisse active.');

        $inactive = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('till-inactive-assigned')->plainTextToken])
            ->postJson('/api/v1/tills', [
                'code' => 'TILL-INACTIVE',
                'name' => 'Inactive Till',
                'status' => 'inactive',
                'assigned_user_public_id' => $teller->public_id,
            ]);
        $this->assertJsonSuccess($inactive, 201);
        $inactivePublicId = $this->requireStringJsonPath($inactive, 'data.public_id');

        $activateConflict = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('till-activate-conflict')->plainTextToken])
            ->patchJson('/api/v1/tills/'.$inactivePublicId, [
                'status' => 'active',
            ]);
        $activateConflict->assertStatus(422);
        $activateConflict->assertJsonValidationErrors(['assigned_user_public_id']);

        $this->assertNoCashWorkflowRecords();
    }

    public function test_teller_session_opening_requires_valid_till_teller_and_balanced_denomination_count(): void
    {
        $agency = $this->createAgency('CASH-G');
        $actor = $this->createUserWithRole('agency-manager', $agency['code'], $agency['name']);
        $teller = $this->createUserWithRole('teller', $agency['code'], $agency['name']);
        $note1000 = $this->createDenomination('XAF-1000-OPEN', 1000);
        $note500 = $this->createDenomination('XAF-500-OPEN', 500);

        $till = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('session-till')->plainTextToken])
            ->postJson('/api/v1/tills', [
                'code' => 'TILL-SESSION',
                'name' => 'Session Till',
                'requires_denominations' => true,
                'assigned_user_public_id' => $teller->public_id,
            ]);
        $this->assertJsonSuccess($till, 201);
        $tillPublicId = $this->requireStringJsonPath($till, 'data.public_id');

        $missingCounts = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('session-missing-counts')->plainTextToken])
            ->postJson('/api/v1/teller-sessions', [
                'till_public_id' => $tillPublicId,
                'teller_user_public_id' => $teller->public_id,
                'business_date' => now()->toDateString(),
                'opening_declaration_minor' => 1500,
                'currency' => 'XAF',
            ]);
        $missingCounts->assertStatus(422);
        $missingCounts->assertJsonValidationErrors(['denomination_counts']);

        $mismatchedCounts = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('session-mismatch-counts')->plainTextToken])
            ->postJson('/api/v1/teller-sessions', [
                'till_public_id' => $tillPublicId,
                'teller_user_public_id' => $teller->public_id,
                'business_date' => now()->toDateString(),
                'opening_declaration_minor' => 1500,
                'currency' => 'XAF',
                'denomination_counts' => [
                    ['denomination_public_id' => $note1000['public_id'], 'count' => 1],
                ],
            ]);
        $mismatchedCounts->assertStatus(422);
        $mismatchedCounts->assertJsonValidationErrors(['denomination_counts']);

        $open = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('session-open')->plainTextToken])
            ->postJson('/api/v1/teller-sessions', [
                'till_public_id' => $tillPublicId,
                'teller_user_public_id' => $teller->public_id,
                'business_date' => now()->toDateString(),
                'opening_declaration_minor' => 1500,
                'currency' => 'xaf',
                'denomination_counts' => [
                    ['denomination_public_id' => $note1000['public_id'], 'count' => 1],
                    ['denomination_public_id' => $note500['public_id'], 'count' => 1],
                ],
            ]);
        $this->assertJsonSuccess($open, 201);
        $open->assertJsonPath('data.till_public_id', $tillPublicId);
        $open->assertJsonPath('data.teller_user_public_id', $teller->public_id);
        $open->assertJsonPath('data.opening_declaration_minor', 1500);
        $open->assertJsonPath('data.currency', 'XAF');
        $open->assertJsonPath('data.status', 'open');
        $open->assertJsonMissingPath('data.id');
        $open->assertJsonMissingPath('data.till_id');
        $open->assertJsonMissingPath('data.teller_user_id');

        $this->assertDatabaseHas('tills', [
            'public_id' => $tillPublicId,
            'daily_state' => 'open',
            'opening_balance_minor' => 1500,
            'currency' => 'XAF',
        ]);

        $duplicateOpen = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('session-duplicate')->plainTextToken])
            ->postJson('/api/v1/teller-sessions', [
                'till_public_id' => $tillPublicId,
                'teller_user_public_id' => $teller->public_id,
                'business_date' => now()->toDateString(),
                'opening_declaration_minor' => 1500,
                'currency' => 'XAF',
                'denomination_counts' => [
                    ['denomination_public_id' => $note1000['public_id'], 'count' => 1],
                    ['denomination_public_id' => $note500['public_id'], 'count' => 1],
                ],
            ]);
        $duplicateOpen->assertStatus(422);
        $duplicateOpen->assertJsonValidationErrors(['till_public_id']);
    }

    public function test_teller_session_closing_requires_balanced_count_no_pending_transactions_and_zero_difference(): void
    {
        $agency = $this->createAgency('CASH-H');
        $actor = $this->createUserWithRole('agency-manager', $agency['code'], $agency['name']);
        $teller = $this->createUserWithRole('teller', $agency['code'], $agency['name']);
        $note1000 = $this->createDenomination('XAF-1000-CLOSE', 1000);
        $note500 = $this->createDenomination('XAF-500-CLOSE', 500);

        $till = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('close-till')->plainTextToken])
            ->postJson('/api/v1/tills', [
                'code' => 'TILL-CLOSE',
                'name' => 'Close Till',
                'requires_denominations' => true,
                'assigned_user_public_id' => $teller->public_id,
            ]);
        $this->assertJsonSuccess($till, 201);
        $tillPublicId = $this->requireStringJsonPath($till, 'data.public_id');

        $open = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('close-open')->plainTextToken])
            ->postJson('/api/v1/teller-sessions', [
                'till_public_id' => $tillPublicId,
                'teller_user_public_id' => $teller->public_id,
                'business_date' => now()->toDateString(),
                'opening_declaration_minor' => 1500,
                'currency' => 'XAF',
                'denomination_counts' => [
                    ['denomination_public_id' => $note1000['public_id'], 'count' => 1],
                    ['denomination_public_id' => $note500['public_id'], 'count' => 1],
                ],
            ]);
        $this->assertJsonSuccess($open, 201);
        $sessionPublicId = $this->requireStringJsonPath($open, 'data.public_id');
        $sessionId = DB::table('teller_sessions')->where('public_id', $sessionPublicId)->value('id');
        self::assertIsInt($sessionId);

        $difference = $this->withApiHeaders([
            'Authorization' => 'Bearer '.$actor->createToken('close-difference')->plainTextToken,
            'X-Locale' => 'fr',
        ])
            ->postJson('/api/v1/teller-sessions/'.$sessionPublicId.'/close', [
                'closing_declaration_minor' => 1000,
                'currency' => 'XAF',
                'denomination_counts' => [
                    ['denomination_public_id' => $note1000['public_id'], 'count' => 1],
                ],
            ]);
        $difference->assertStatus(422);
        $difference->assertJsonValidationErrors(['closing_declaration_minor']);
        $difference->assertJsonPath('errors.closing_declaration_minor.0', 'La déclaration de clôture doit être égale au solde théorique de caisse enregistré.');

        DB::table('teller_transactions')->insert([
            'public_id' => (string) Str::ulid(),
            'teller_session_id' => $sessionId,
            'agency_id' => $agency['id'],
            'transaction_type' => 'cash_deposit',
            'amount_minor' => 500,
            'currency' => 'XAF',
            'status' => 'pending',
            'reference' => 'PENDING-CLOSE-1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $pending = $this->withApiHeaders([
            'Authorization' => 'Bearer '.$actor->createToken('close-pending')->plainTextToken,
            'X-Locale' => 'fr',
        ])
            ->postJson('/api/v1/teller-sessions/'.$sessionPublicId.'/close', [
                'closing_declaration_minor' => 1500,
                'currency' => 'XAF',
                'denomination_counts' => [
                    ['denomination_public_id' => $note1000['public_id'], 'count' => 1],
                    ['denomination_public_id' => $note500['public_id'], 'count' => 1],
                ],
            ]);
        $pending->assertStatus(422);
        $pending->assertJsonValidationErrors(['transactions']);
        $pending->assertJsonPath('errors.transactions.0', 'Les opérations de guichet en attente doivent être validées ou annulées avant la fermeture de la session.');

        DB::table('teller_transactions')
            ->where('reference', 'PENDING-CLOSE-1')
            ->update(['status' => 'cancelled']);

        $withoutReconciliation = $this->withApiHeaders([
            'Authorization' => 'Bearer '.$actor->createToken('close-without-reconciliation')->plainTextToken,
            'X-Locale' => 'fr',
        ])->postJson('/api/v1/teller-sessions/'.$sessionPublicId.'/close', [
            'closing_declaration_minor' => 1500,
            'currency' => 'XAF',
            'denomination_counts' => [
                ['denomination_public_id' => $note1000['public_id'], 'count' => 1],
                ['denomination_public_id' => $note500['public_id'], 'count' => 1],
            ],
        ]);
        $withoutReconciliation->assertStatus(422);
        $withoutReconciliation->assertJsonValidationErrors(['reconciliation']);
        $withoutReconciliation->assertJsonPath(
            'errors.reconciliation.0',
            'Un rapprochement de caisse équilibré et à jour doit être enregistré avant la fermeture de la session de caisse.'
        );
        self::assertSame('open', DB::table('teller_sessions')->where('id', $sessionId)->value('status'));

        $reconciliation = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('close-reconciliation')->plainTextToken])
            ->postJson('/api/v1/teller-sessions/'.$sessionPublicId.'/reconciliations', [
                'currency' => 'XAF',
                'denomination_counts' => [
                    ['denomination_public_id' => $note1000['public_id'], 'count' => 1],
                    ['denomination_public_id' => $note500['public_id'], 'count' => 1],
                ],
            ]);
        $this->assertJsonSuccess($reconciliation, 201);

        DB::table('till_reconciliations')
            ->where('teller_session_id', $sessionId)
            ->update(['actual_balance_minor' => 1000]);

        $staleReconciliation = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('close-stale-reconciliation')->plainTextToken])
            ->postJson('/api/v1/teller-sessions/'.$sessionPublicId.'/close', [
                'closing_declaration_minor' => 1500,
                'currency' => 'XAF',
                'denomination_counts' => [
                    ['denomination_public_id' => $note1000['public_id'], 'count' => 1],
                    ['denomination_public_id' => $note500['public_id'], 'count' => 1],
                ],
            ]);
        $staleReconciliation->assertStatus(422);
        $staleReconciliation->assertJsonValidationErrors(['reconciliation']);

        DB::table('till_reconciliations')
            ->where('teller_session_id', $sessionId)
            ->update(['actual_balance_minor' => 1500]);

        $close = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('close-success')->plainTextToken])
            ->postJson('/api/v1/teller-sessions/'.$sessionPublicId.'/close', [
                'closing_declaration_minor' => 1500,
                'currency' => 'xaf',
                'denomination_counts' => [
                    ['denomination_public_id' => $note1000['public_id'], 'count' => 1],
                    ['denomination_public_id' => $note500['public_id'], 'count' => 1],
                ],
            ]);
        $this->assertJsonSuccess($close);
        $close->assertJsonPath('data.status', 'closed');
        $close->assertJsonPath('data.closing_declaration_minor', 1500);
        $close->assertJsonPath('data.currency', 'XAF');

        $this->assertDatabaseHas('tills', [
            'public_id' => $tillPublicId,
            'daily_state' => 'closed',
            'last_closing_balance_minor' => 1500,
            'currency' => 'XAF',
        ]);
    }

    public function test_till_reconciliation_records_denomination_lines_and_enforces_zero_difference(): void
    {
        $agency = $this->createAgency('CASH-K');
        $actor = $this->createUserWithRole('agency-manager', $agency['code'], $agency['name']);
        $teller = $this->createUserWithRole('teller', $agency['code'], $agency['name']);
        $note1000 = $this->createDenomination('XAF-1000-REC', 1000);
        $note500 = $this->createDenomination('XAF-500-REC', 500);

        $till = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('recon-till')->plainTextToken])
            ->postJson('/api/v1/tills', [
                'code' => 'TILL-RECON',
                'name' => 'Reconciliation Till',
                'requires_denominations' => true,
                'assigned_user_public_id' => $teller->public_id,
            ]);
        $this->assertJsonSuccess($till, 201);
        $tillPublicId = $this->requireStringJsonPath($till, 'data.public_id');

        $open = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('recon-open')->plainTextToken])
            ->postJson('/api/v1/teller-sessions', [
                'till_public_id' => $tillPublicId,
                'teller_user_public_id' => $teller->public_id,
                'business_date' => now()->toDateString(),
                'opening_declaration_minor' => 1500,
                'currency' => 'XAF',
                'denomination_counts' => [
                    ['denomination_public_id' => $note1000['public_id'], 'count' => 1],
                    ['denomination_public_id' => $note500['public_id'], 'count' => 1],
                ],
            ]);
        $this->assertJsonSuccess($open, 201);
        $sessionPublicId = $this->requireStringJsonPath($open, 'data.public_id');
        $sessionId = DB::table('teller_sessions')->where('public_id', $sessionPublicId)->value('id');
        self::assertIsInt($sessionId);

        DB::table('teller_transactions')->insert([
            'public_id' => (string) Str::ulid(),
            'teller_session_id' => $sessionId,
            'agency_id' => $agency['id'],
            'transaction_type' => 'cash_deposit',
            'amount_minor' => 500,
            'currency' => 'XAF',
            'status' => 'pending',
            'reference' => 'PENDING-RECON-1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $pending = $this->withApiHeaders([
            'Authorization' => 'Bearer '.$actor->createToken('recon-pending')->plainTextToken,
            'X-Locale' => 'fr',
        ])
            ->postJson('/api/v1/teller-sessions/'.$sessionPublicId.'/reconciliations', [
                'currency' => 'XAF',
                'denomination_counts' => [
                    ['denomination_public_id' => $note1000['public_id'], 'count' => 1],
                    ['denomination_public_id' => $note500['public_id'], 'count' => 1],
                ],
            ]);
        $pending->assertStatus(422);
        $pending->assertJsonValidationErrors(['transactions']);
        $pending->assertJsonPath('errors.transactions.0', 'Les transactions de guichetier en attente doivent être comptabilisées ou annulées avant le rapprochement.');
        $this->assertDatabaseHas('user_notifications', [
            'recipient_type' => 'agency',
            'recipient_id' => $agency['id'],
            'category' => 'till_reconciliation_pending',
            'source_public_id' => $sessionPublicId,
        ]);

        DB::table('teller_transactions')->where('reference', 'PENDING-RECON-1')->update(['status' => 'cancelled']);

        $difference = $this->withApiHeaders([
            'Authorization' => 'Bearer '.$actor->createToken('recon-difference')->plainTextToken,
            'X-Locale' => 'fr',
        ])
            ->postJson('/api/v1/teller-sessions/'.$sessionPublicId.'/reconciliations', [
                'currency' => 'XAF',
                'denomination_counts' => [
                    ['denomination_public_id' => $note1000['public_id'], 'count' => 1],
                ],
            ]);
        $difference->assertStatus(422);
        $difference->assertJsonValidationErrors(['difference_minor']);
        $difference->assertJsonPath('errors.difference_minor.0', 'L\'écart de rapprochement doit être nul avant de pouvoir être enregistré.');
        $this->assertDatabaseHas('user_notifications', [
            'recipient_type' => 'agency',
            'recipient_id' => $agency['id'],
            'category' => 'till_reconciliation_rejected',
            'source_public_id' => $sessionPublicId,
        ]);

        $reconciliation = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('recon-success')->plainTextToken])
            ->postJson('/api/v1/teller-sessions/'.$sessionPublicId.'/reconciliations', [
                'currency' => 'xaf',
                'notes' => 'Balanced count',
                'denomination_counts' => [
                    ['denomination_public_id' => $note1000['public_id'], 'count' => 1],
                    ['denomination_public_id' => $note500['public_id'], 'count' => 1],
                ],
            ]);
        $this->assertJsonSuccess($reconciliation, 201);
        $reconciliation->assertJsonPath('data.actual_balance_minor', 1500);
        $reconciliation->assertJsonPath('data.theoretical_balance_minor', 1500);
        $reconciliation->assertJsonPath('data.difference_minor', 0);
        $reconciliation->assertJsonPath('data.status', 'balanced');
        $reconciliationPublicId = $this->requireStringJsonPath($reconciliation, 'data.public_id');

        $this->assertDatabaseHas('till_reconciliations', [
            'public_id' => $reconciliationPublicId,
            'actual_balance_minor' => 1500,
            'theoretical_balance_minor' => 1500,
            'difference_minor' => 0,
            'currency' => 'XAF',
        ]);
        $this->assertDatabaseHas('user_notifications', [
            'recipient_type' => 'agency',
            'recipient_id' => $agency['id'],
            'category' => 'till_reconciliation_accepted',
            'source_public_id' => $reconciliationPublicId,
        ]);
        $this->assertDatabaseCount('till_reconciliation_lines', 2);

        $index = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('recon-index')->plainTextToken])
            ->getJson('/api/v1/teller-sessions/'.$sessionPublicId.'/reconciliations');
        $this->assertJsonSuccess($index);
        $index->assertJsonPath('data.till_reconciliations.0.public_id', $reconciliationPublicId);
    }

    public function test_cash_close_batch_blocks_until_sessions_are_closed_and_reconciled(): void
    {
        $agency = $this->createAgency('CASH-L');
        $operator = $this->createUserWithRole('platform-admin');
        $manager = $this->createUserWithRole('agency-manager', $agency['code'], $agency['name']);
        $teller = $this->createUserWithRole('teller', $agency['code'], $agency['name']);
        $note1000 = $this->createDenomination('XAF-1000-BATCH', 1000);
        $note500 = $this->createDenomination('XAF-500-BATCH', 500);
        $procedure = $this->createBatchProcedure('cash_close_verification');

        $till = $this->withApiHeaders(['Authorization' => 'Bearer '.$manager->createToken('batch-till')->plainTextToken])
            ->postJson('/api/v1/tills', [
                'code' => 'TILL-BATCH',
                'name' => 'Batch Till',
                'requires_denominations' => true,
                'assigned_user_public_id' => $teller->public_id,
            ]);
        $this->assertJsonSuccess($till, 201);
        $tillPublicId = $this->requireStringJsonPath($till, 'data.public_id');

        $open = $this->withApiHeaders(['Authorization' => 'Bearer '.$manager->createToken('batch-open')->plainTextToken])
            ->postJson('/api/v1/teller-sessions', [
                'till_public_id' => $tillPublicId,
                'teller_user_public_id' => $teller->public_id,
                'business_date' => now()->toDateString(),
                'opening_declaration_minor' => 1500,
                'currency' => 'XAF',
                'denomination_counts' => [
                    ['denomination_public_id' => $note1000['public_id'], 'count' => 1],
                    ['denomination_public_id' => $note500['public_id'], 'count' => 1],
                ],
            ]);
        $this->assertJsonSuccess($open, 201);
        $sessionPublicId = $this->requireStringJsonPath($open, 'data.public_id');

        $run = $this->actingAsSanctum($operator)
            ->postJson('/api/v1/batch-runs', [
                'batch_procedure_public_id' => $procedure['public_id'],
                'business_date' => now()->toDateString(),
                'agency_code' => $agency['code'],
            ]);
        $this->assertJsonSuccess($run, 201);
        $runPublicId = $this->requireStringJsonPath($run, 'data.public_id');

        $blocked = $this->actingAsSanctum($operator)
            ->postJson('/api/v1/batch-runs/'.$runPublicId.'/execute');
        $this->assertJsonSuccess($blocked);
        $blocked->assertJsonPath('data.status', 'failed');
        $blocked->assertJsonPath('data.summary_payload.open_sessions', 1);

        $reconciliation = $this->actingAsSanctum($manager)
            ->postJson('/api/v1/teller-sessions/'.$sessionPublicId.'/reconciliations', [
                'currency' => 'XAF',
                'denomination_counts' => [
                    ['denomination_public_id' => $note1000['public_id'], 'count' => 1],
                    ['denomination_public_id' => $note500['public_id'], 'count' => 1],
                ],
            ]);
        $this->assertJsonSuccess($reconciliation, 201);

        $close = $this->actingAsSanctum($manager)
            ->postJson('/api/v1/teller-sessions/'.$sessionPublicId.'/close', [
                'closing_declaration_minor' => 1500,
                'currency' => 'XAF',
                'denomination_counts' => [
                    ['denomination_public_id' => $note1000['public_id'], 'count' => 1],
                    ['denomination_public_id' => $note500['public_id'], 'count' => 1],
                ],
            ]);
        $this->assertJsonSuccess($close);

        $succeeded = $this->actingAsSanctum($operator)
            ->postJson('/api/v1/batch-runs/'.$runPublicId.'/execute');
        $this->assertJsonSuccess($succeeded);
        $succeeded->assertJsonPath('data.status', 'succeeded');
        $succeeded->assertJsonPath('data.summary_payload.open_sessions', 0);
        $succeeded->assertJsonPath('data.summary_payload.pending_transactions', 0);
        $succeeded->assertJsonPath('data.summary_payload.unreconciled_closed_sessions', 0);
    }

    public function test_cash_deposit_requires_open_session_and_posts_balanced_accounting_entry(): void
    {
        $agency = $this->createAgency('CASH-I');
        $actor = $this->createUserWithRole('agency-manager', $agency['code'], $agency['name']);
        $teller = $this->createUserWithRole('teller', $agency['code'], $agency['name']);
        $cashLedger = $this->createLedgerAccount($agency['id'], 'CASH-DEPOSIT-TILL', LedgerAccount::ACCOUNT_CLASS_ASSET);
        $depositLedger = $this->createLedgerAccount($agency['id'], 'CUSTOMER-DEPOSIT-LIABILITY', LedgerAccount::ACCOUNT_CLASS_LIABILITY);
        $customerAccount = $this->createCustomerAccount($agency['id'], $depositLedger['id'], 'CASH-DEP-001');

        $till = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('deposit-till')->plainTextToken])
            ->postJson('/api/v1/tills', [
                'code' => 'TILL-DEPOSIT',
                'name' => 'Deposit Till',
                'requires_denominations' => false,
                'assigned_user_public_id' => $teller->public_id,
                'ledger_account_public_id' => $cashLedger['public_id'],
                'max_withdrawal_limit_minor' => 5000,
            ]);
        $this->assertJsonSuccess($till, 201);
        $tillPublicId = $this->requireStringJsonPath($till, 'data.public_id');

        $open = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('deposit-open')->plainTextToken])
            ->postJson('/api/v1/teller-sessions', [
                'till_public_id' => $tillPublicId,
                'teller_user_public_id' => $teller->public_id,
                'business_date' => now()->toDateString(),
                'opening_declaration_minor' => 5000,
                'currency' => 'XAF',
            ]);
        $this->assertJsonSuccess($open, 201);
        $sessionPublicId = $this->requireStringJsonPath($open, 'data.public_id');

        $fractionalCashDeposit = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('deposit-fractional')->plainTextToken])
            ->postJson('/api/v1/teller-sessions/'.$sessionPublicId.'/deposits', [
                'customer_account_public_id' => $customerAccount['public_id'],
                'amount_minor' => 83333,
                'currency' => 'XAF',
                'idempotency_key' => 'cash-deposit-fractional',
            ]);
        $fractionalCashDeposit->assertStatus(422);
        $fractionalCashDeposit->assertJsonValidationErrors(['amount_minor']);

        $deposit = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('deposit-post')->plainTextToken])
            ->postJson('/api/v1/teller-sessions/'.$sessionPublicId.'/deposits', [
                'customer_account_public_id' => $customerAccount['public_id'],
                'amount_minor' => 10000,
                'currency' => 'xaf',
                'idempotency_key' => 'cash-deposit-001',
                'operation_code' => 'cash_deposit',
                'depositor_name' => 'Awa Tester',
                'depositor_address' => 'Douala',
                'description' => 'Counter cash deposit',
            ]);
        $this->assertJsonSuccess($deposit, 201);
        $deposit->assertJsonPath('data.teller_transaction.amount_minor', 10000);
        $deposit->assertJsonPath('data.teller_transaction.currency', 'XAF');
        $deposit->assertJsonPath('data.teller_transaction.status', 'posted');
        $deposit->assertJsonPath('data.teller_transaction.customer_account_public_id', $customerAccount['public_id']);
        $deposit->assertJsonPath('data.journal_entry.status', 'posted');
        $deposit->assertJsonPath('data.journal_entry.source_type', 'cash_deposit');

        $transactionPublicId = $this->requireStringJsonPath($deposit, 'data.teller_transaction.public_id');
        $journalPublicId = $this->requireStringJsonPath($deposit, 'data.journal_entry.public_id');
        $journalId = DB::table('journal_entries')->where('public_id', $journalPublicId)->value('id');
        self::assertIsInt($journalId);

        $this->assertDatabaseHas('teller_transactions', [
            'public_id' => $transactionPublicId,
            'status' => 'posted',
            'amount_minor' => 10000,
            'idempotency_key' => 'cash-deposit-001',
        ]);
        $this->assertDatabaseHas('journal_lines', [
            'journal_entry_id' => $journalId,
            'ledger_account_id' => $cashLedger['id'],
            'debit_minor' => 10000,
            'credit_minor' => 0,
        ]);
        $this->assertDatabaseHas('journal_lines', [
            'journal_entry_id' => $journalId,
            'ledger_account_id' => $depositLedger['id'],
            'customer_account_id' => $customerAccount['id'],
            'debit_minor' => 0,
            'credit_minor' => 10000,
        ]);
        self::assertSame(10000, (int) DB::table('journal_lines')->where('journal_entry_id', $journalId)->sum('debit_minor'));
        self::assertSame(10000, (int) DB::table('journal_lines')->where('journal_entry_id', $journalId)->sum('credit_minor'));

        $replay = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('deposit-replay')->plainTextToken])
            ->postJson('/api/v1/teller-sessions/'.$sessionPublicId.'/deposits', [
                'customer_account_public_id' => $customerAccount['public_id'],
                'amount_minor' => 10000,
                'currency' => 'xaf',
                'idempotency_key' => 'cash-deposit-001',
            ]);
        $this->assertJsonSuccess($replay, 201);
        self::assertSame(1, DB::table('teller_transactions')->where('idempotency_key', 'cash-deposit-001')->count());
        self::assertSame(1, DB::table('journal_entries')->where('idempotency_key', 'cash-deposit-001')->count());

        $signature = $this->createVerifiedAccountSignature($agency['id'], $customerAccount['id']);

        $overLimit = $this->withApiHeaders([
            'Authorization' => 'Bearer '.$actor->createToken('withdraw-over-limit')->plainTextToken,
            'X-Locale' => 'fr',
        ])
            ->postJson('/api/v1/teller-sessions/'.$sessionPublicId.'/withdrawals', [
                'customer_account_public_id' => $customerAccount['public_id'],
                'amount_minor' => 6000,
                'currency' => 'XAF',
                'signature_public_id' => $signature['public_id'],
                'signature_verification_method' => 'visual_match',
                'idempotency_key' => 'cash-withdrawal-over-limit',
            ]);
        $overLimit->assertStatus(422);
        $overLimit->assertJsonValidationErrors(['amount_minor']);
        $overLimit->assertJsonPath('errors.amount_minor.0', 'Le montant du retrait dépasse la limite maximale de retrait de la caisse.');

        $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('withdraw-limit-update')->plainTextToken])
            ->patchJson('/api/v1/tills/'.$tillPublicId, [
                'max_withdrawal_limit_minor' => 20000,
            ])
            ->assertOk();

        $missingSignature = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('withdraw-missing-signature')->plainTextToken])
            ->postJson('/api/v1/teller-sessions/'.$sessionPublicId.'/withdrawals', [
                'customer_account_public_id' => $customerAccount['public_id'],
                'amount_minor' => 3000,
                'currency' => 'XAF',
                'idempotency_key' => 'cash-withdrawal-missing-signature',
            ]);
        $missingSignature->assertStatus(422);
        $missingSignature->assertJsonValidationErrors(['signature_public_id']);

        $fractionalCashWithdrawal = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('withdraw-fractional')->plainTextToken])
            ->postJson('/api/v1/teller-sessions/'.$sessionPublicId.'/withdrawals', [
                'customer_account_public_id' => $customerAccount['public_id'],
                'amount_minor' => 3333,
                'currency' => 'XAF',
                'signature_public_id' => $signature['public_id'],
                'signature_verification_method' => 'visual_match',
                'idempotency_key' => 'cash-withdrawal-fractional',
            ]);
        $fractionalCashWithdrawal->assertStatus(422);
        $fractionalCashWithdrawal->assertJsonValidationErrors(['amount_minor']);

        $withdrawal = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('withdraw-post')->plainTextToken])
            ->postJson('/api/v1/teller-sessions/'.$sessionPublicId.'/withdrawals', [
                'customer_account_public_id' => $customerAccount['public_id'],
                'amount_minor' => 3000,
                'currency' => 'XAF',
                'signature_public_id' => $signature['public_id'],
                'signature_verification_method' => 'visual_match',
                'operation_code' => 'cash_withdrawal',
                'description' => 'Counter cash withdrawal',
                'idempotency_key' => 'cash-withdrawal-001',
            ]);
        $this->assertJsonSuccess($withdrawal, 201);
        $withdrawal->assertJsonPath('data.teller_transaction.amount_minor', 3000);
        $withdrawal->assertJsonPath('data.teller_transaction.transaction_type', 'cash_withdrawal');
        $withdrawal->assertJsonPath('data.teller_transaction.customer_account_signature_public_id', $signature['public_id']);
        $withdrawal->assertJsonPath('data.teller_transaction.signature_checked_by_user_public_id', $actor->public_id);
        $withdrawal->assertJsonPath('data.teller_transaction.signature_verification_method', 'visual_match');
        $withdrawal->assertJsonPath('data.journal_entry.status', 'posted');
        $withdrawalTransactionPublicId = $this->requireStringJsonPath($withdrawal, 'data.teller_transaction.public_id');
        $withdrawalJournalPublicId = $this->requireStringJsonPath($withdrawal, 'data.journal_entry.public_id');
        $withdrawalJournalId = DB::table('journal_entries')->where('public_id', $withdrawalJournalPublicId)->value('id');
        self::assertIsInt($withdrawalJournalId);
        $this->assertDatabaseHas('journal_lines', [
            'journal_entry_id' => $withdrawalJournalId,
            'ledger_account_id' => $depositLedger['id'],
            'customer_account_id' => $customerAccount['id'],
            'debit_minor' => 3000,
            'credit_minor' => 0,
        ]);
        $this->assertDatabaseHas('journal_lines', [
            'journal_entry_id' => $withdrawalJournalId,
            'ledger_account_id' => $cashLedger['id'],
            'debit_minor' => 0,
            'credit_minor' => 3000,
        ]);

        $insufficientAvailable = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('withdraw-insufficient')->plainTextToken])
            ->postJson('/api/v1/teller-sessions/'.$sessionPublicId.'/withdrawals', [
                'customer_account_public_id' => $customerAccount['public_id'],
                'amount_minor' => 8000,
                'currency' => 'XAF',
                'signature_public_id' => $signature['public_id'],
                'signature_verification_method' => 'visual_match',
                'idempotency_key' => 'cash-withdrawal-insufficient',
            ]);
        $insufficientAvailable->assertStatus(422);
        $insufficientAvailable->assertJsonValidationErrors(['amount_minor']);

        $reversal = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('withdraw-reverse')->plainTextToken])
            ->postJson('/api/v1/teller-transactions/'.$withdrawalTransactionPublicId.'/reverse');
        $this->assertJsonSuccess($reversal, 201);
        $reversal->assertJsonPath('data.teller_transaction.transaction_type', 'cash_reversal');
        $reversal->assertJsonPath('data.teller_transaction.amount_minor', 3000);
        $reversal->assertJsonPath('data.journal_entry.status', 'posted');
        $this->assertDatabaseHas('teller_transactions', [
            'public_id' => $withdrawalTransactionPublicId,
            'status' => 'reversed',
        ]);
        $reversalJournalPublicId = $this->requireStringJsonPath($reversal, 'data.journal_entry.public_id');
        $reversalJournalId = DB::table('journal_entries')->where('public_id', $reversalJournalPublicId)->value('id');
        self::assertIsInt($reversalJournalId);
        $this->assertDatabaseHas('journal_lines', [
            'journal_entry_id' => $reversalJournalId,
            'ledger_account_id' => $depositLedger['id'],
            'customer_account_id' => $customerAccount['id'],
            'debit_minor' => 0,
            'credit_minor' => 3000,
        ]);
        $this->assertDatabaseHas('journal_lines', [
            'journal_entry_id' => $reversalJournalId,
            'ledger_account_id' => $cashLedger['id'],
            'debit_minor' => 3000,
            'credit_minor' => 0,
        ]);

        $duplicateReversal = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('withdraw-reverse-duplicate')->plainTextToken])
            ->postJson('/api/v1/teller-transactions/'.$withdrawalTransactionPublicId.'/reverse');
        $duplicateReversal->assertStatus(422);
        $duplicateReversal->assertJsonValidationErrors(['teller_transaction']);
    }

    public function test_manual_cash_journal_requires_balanced_lines_and_follows_journal_approval_workflow(): void
    {
        $agency = $this->createAgency('CASH-J');
        $maker = $this->createUserWithRole('agency-manager', $agency['code'], $agency['name']);
        $reviewer = $this->createUserWithRole('platform-admin');
        $reviewer->givePermissionTo('journal.entries.review', 'journal.entries.post');
        $teller = $this->createUserWithRole('teller', $agency['code'], $agency['name']);
        $cashLedger = $this->createLedgerAccount($agency['id'], 'CASH-OD-TILL', LedgerAccount::ACCOUNT_CLASS_ASSET);
        $expenseLedger = $this->createLedgerAccount($agency['id'], 'CASH-OD-EXPENSE', LedgerAccount::ACCOUNT_CLASS_EXPENSE);
        $operationCode = $this->createOperationCode('OD-CASH-EXPENSE', 'cash');

        $till = $this->withApiHeaders(['Authorization' => 'Bearer '.$maker->createToken('od-till')->plainTextToken])
            ->postJson('/api/v1/tills', [
                'code' => 'TILL-OD',
                'name' => 'OD Till',
                'requires_denominations' => false,
                'assigned_user_public_id' => $teller->public_id,
                'ledger_account_public_id' => $cashLedger['public_id'],
            ]);
        $this->assertJsonSuccess($till, 201);
        $tillPublicId = $this->requireStringJsonPath($till, 'data.public_id');

        $open = $this->withApiHeaders(['Authorization' => 'Bearer '.$maker->createToken('od-open')->plainTextToken])
            ->postJson('/api/v1/teller-sessions', [
                'till_public_id' => $tillPublicId,
                'teller_user_public_id' => $teller->public_id,
                'business_date' => now()->toDateString(),
                'opening_declaration_minor' => 5000,
                'currency' => 'XAF',
            ]);
        $this->assertJsonSuccess($open, 201);
        $sessionPublicId = $this->requireStringJsonPath($open, 'data.public_id');

        $unbalanced = $this->withApiHeaders(['Authorization' => 'Bearer '.$maker->createToken('od-unbalanced')->plainTextToken])
            ->postJson('/api/v1/teller-sessions/'.$sessionPublicId.'/manual-journal-entries', [
                'operation_code_public_id' => $operationCode['public_id'],
                'currency' => 'XAF',
                'lines' => [
                    ['ledger_account_public_id' => $expenseLedger['public_id'], 'direction' => 'debit', 'amount_minor' => 2000],
                    ['ledger_account_public_id' => $cashLedger['public_id'], 'direction' => 'credit', 'amount_minor' => 1500],
                ],
            ]);
        $unbalanced->assertStatus(422);
        $unbalanced->assertJsonValidationErrors(['lines']);

        $fractionalTillLine = $this->withApiHeaders(['Authorization' => 'Bearer '.$maker->createToken('od-fractional')->plainTextToken])
            ->postJson('/api/v1/teller-sessions/'.$sessionPublicId.'/manual-journal-entries', [
                'operation_code_public_id' => $operationCode['public_id'],
                'currency' => 'XAF',
                'lines' => [
                    ['ledger_account_public_id' => $expenseLedger['public_id'], 'direction' => 'debit', 'amount_minor' => 1234],
                    ['ledger_account_public_id' => $cashLedger['public_id'], 'direction' => 'credit', 'amount_minor' => 1234],
                ],
            ]);
        $fractionalTillLine->assertStatus(422);
        $fractionalTillLine->assertJsonValidationErrors(['lines.1.amount_minor']);

        $submitted = $this->withApiHeaders(['Authorization' => 'Bearer '.$maker->createToken('od-submit')->plainTextToken])
            ->postJson('/api/v1/teller-sessions/'.$sessionPublicId.'/manual-journal-entries', [
                'reference' => 'OD-CASH-001',
                'operation_code_public_id' => $operationCode['public_id'],
                'description' => 'Cash office expense',
                'currency' => 'XAF',
                'idempotency_key' => 'manual-cash-od-001',
                'lines' => [
                    ['ledger_account_public_id' => $expenseLedger['public_id'], 'direction' => 'debit', 'amount_minor' => 2000, 'memo' => 'Expense'],
                    ['ledger_account_public_id' => $cashLedger['public_id'], 'direction' => 'credit', 'amount_minor' => 2000, 'memo' => 'Cash out'],
                ],
            ]);
        $this->assertJsonSuccess($submitted, 201);
        $submitted->assertJsonPath('data.teller_transaction.transaction_type', 'cash_manual_journal');
        $submitted->assertJsonPath('data.teller_transaction.status', 'pending_review');
        $submitted->assertJsonPath('data.journal_entry.status', 'submitted');
        $journalPublicId = $this->requireStringJsonPath($submitted, 'data.journal_entry.public_id');
        $transactionPublicId = $this->requireStringJsonPath($submitted, 'data.teller_transaction.public_id');

        $approve = $this->actingAsSanctum($reviewer)
            ->postJson('/api/v1/journal-entries/'.$journalPublicId.'/approve', [
                'comment' => 'Approved',
            ]);
        $this->assertJsonSuccess($approve);
        $approve->assertJsonPath('data.status', 'approved');

        $post = $this->actingAsSanctum($reviewer)
            ->postJson('/api/v1/journal-entries/'.$journalPublicId.'/post');
        $this->assertJsonSuccess($post);
        $post->assertJsonPath('data.status', 'posted');
        $this->assertDatabaseHas('teller_transactions', [
            'public_id' => $transactionPublicId,
            'status' => 'posted',
        ]);

        $rejected = $this->actingAsSanctum($maker)
            ->postJson('/api/v1/teller-sessions/'.$sessionPublicId.'/manual-journal-entries', [
                'reference' => 'OD-CASH-002',
                'currency' => 'XAF',
                'lines' => [
                    ['ledger_account_public_id' => $expenseLedger['public_id'], 'direction' => 'debit', 'amount_minor' => 1000],
                    ['ledger_account_public_id' => $cashLedger['public_id'], 'direction' => 'credit', 'amount_minor' => 1000],
                ],
            ]);
        $this->assertJsonSuccess($rejected, 201);
        $rejectedJournalPublicId = $this->requireStringJsonPath($rejected, 'data.journal_entry.public_id');
        $rejectedTransactionPublicId = $this->requireStringJsonPath($rejected, 'data.teller_transaction.public_id');

        $reject = $this->actingAsSanctum($reviewer)
            ->postJson('/api/v1/journal-entries/'.$rejectedJournalPublicId.'/reject', [
                'reason' => 'Supporting document missing',
            ]);
        $this->assertJsonSuccess($reject);
        $reject->assertJsonPath('data.status', 'rejected');
        $this->assertDatabaseHas('teller_transactions', [
            'public_id' => $rejectedTransactionPublicId,
            'status' => 'cancelled',
        ]);
    }

    public function test_self_reversal_is_blocked_for_non_admin_session_teller(): void
    {
        $agency = $this->createAgency('CASH-REVS');
        $manager = $this->createUserWithRole('agency-manager', $agency['code'], $agency['name']);
        $teller = $this->createUserWithRole('teller', $agency['code'], $agency['name']);
        $teller->givePermissionTo('cash.transactions.reverse');
        $teller->refresh();

        $cashLedger = $this->createLedgerAccount($agency['id'], 'CASH-REVS-TILL', LedgerAccount::ACCOUNT_CLASS_ASSET);
        $depositLedger = $this->createLedgerAccount($agency['id'], 'CASH-REVS-DEPOSIT', LedgerAccount::ACCOUNT_CLASS_LIABILITY);
        $customerAccount = $this->createCustomerAccount($agency['id'], $depositLedger['id'], 'CASH-REVS-001');

        $till = $this->withApiHeaders()
            ->actingAsSanctum($manager)
            ->postJson('/api/v1/tills', [
                'code' => 'TILL-REVS',
                'name' => 'Reversal Till',
                'requires_denominations' => false,
                'assigned_user_public_id' => $teller->public_id,
                'ledger_account_public_id' => $cashLedger['public_id'],
            ]);
        $this->assertJsonSuccess($till, 201);
        $tillPublicId = $this->requireStringJsonPath($till, 'data.public_id');

        $session = $this->withApiHeaders()
            ->actingAsSanctum($manager)
            ->postJson('/api/v1/teller-sessions', [
                'till_public_id' => $tillPublicId,
                'teller_user_public_id' => $teller->public_id,
                'business_date' => now()->toDateString(),
                'opening_declaration_minor' => 0,
                'currency' => 'XAF',
            ]);
        $this->assertJsonSuccess($session, 201);
        $sessionPublicId = $this->requireStringJsonPath($session, 'data.public_id');

        $deposit = $this->withApiHeaders()
            ->actingAsSanctum($teller)
            ->postJson('/api/v1/teller-sessions/'.$sessionPublicId.'/deposits', [
                'customer_account_public_id' => $customerAccount['public_id'],
                'amount_minor' => 10000,
                'currency' => 'XAF',
                'idempotency_key' => 'cash-revs-001',
            ]);
        $this->assertJsonSuccess($deposit, 201);
        $transactionPublicId = $this->requireStringJsonPath($deposit, 'data.teller_transaction.public_id');

        // Self-reversal: same teller attempts to reverse their own session's transaction → blocked.
        $selfReversal = $this->withApiHeaders()
            ->actingAsSanctum($teller)
            ->postJson('/api/v1/teller-transactions/'.$transactionPublicId.'/reverse', [
                'reason' => 'Teller error.',
            ]);
        $selfReversal->assertForbidden();

        // Agency manager (different actor with the reverse permission) succeeds.
        $managerReversal = $this->withApiHeaders()
            ->actingAsSanctum($manager)
            ->postJson('/api/v1/teller-transactions/'.$transactionPublicId.'/reverse', [
                'reason' => 'Supervisor reversal.',
            ]);
        $this->assertJsonSuccess($managerReversal, 201);
    }

    public function test_cash_deposit_rejected_when_pushing_till_balance_above_max_balance_limit(): void
    {
        $agency = $this->createAgency('CASH-MAXB');
        $actor = $this->createUserWithRole('agency-manager', $agency['code'], $agency['name']);
        $teller = $this->createUserWithRole('teller', $agency['code'], $agency['name']);
        $cashLedger = $this->createLedgerAccount($agency['id'], 'CASH-MAXB-TILL', LedgerAccount::ACCOUNT_CLASS_ASSET);
        $depositLedger = $this->createLedgerAccount($agency['id'], 'CASH-MAXB-DEPOSIT', LedgerAccount::ACCOUNT_CLASS_LIABILITY);
        $customerAccount = $this->createCustomerAccount($agency['id'], $depositLedger['id'], 'CASH-MAXB-001');

        $till = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/tills', [
                'code' => 'TILL-MAXB',
                'name' => 'Max Balance Till',
                'requires_denominations' => false,
                'assigned_user_public_id' => $teller->public_id,
                'ledger_account_public_id' => $cashLedger['public_id'],
                'max_balance_limit_minor' => 25000,
            ]);
        $this->assertJsonSuccess($till, 201);
        $tillPublicId = $this->requireStringJsonPath($till, 'data.public_id');

        $open = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/teller-sessions', [
                'till_public_id' => $tillPublicId,
                'teller_user_public_id' => $teller->public_id,
                'business_date' => now()->toDateString(),
                'opening_declaration_minor' => 0,
                'currency' => 'XAF',
            ]);
        $this->assertJsonSuccess($open, 201);
        $sessionPublicId = $this->requireStringJsonPath($open, 'data.public_id');

        $allowed = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/teller-sessions/'.$sessionPublicId.'/deposits', [
                'customer_account_public_id' => $customerAccount['public_id'],
                'amount_minor' => 20000,
                'currency' => 'XAF',
                'idempotency_key' => 'cash-maxb-001',
            ]);
        $this->assertJsonSuccess($allowed, 201);

        $overLimit = $this->withApiHeaders(['X-Locale' => 'fr'])
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/teller-sessions/'.$sessionPublicId.'/deposits', [
                'customer_account_public_id' => $customerAccount['public_id'],
                'amount_minor' => 6000,
                'currency' => 'XAF',
                'idempotency_key' => 'cash-maxb-002',
            ]);
        $overLimit->assertStatus(422);
        $overLimit->assertJsonValidationErrors(['amount_minor']);
        $overLimit->assertJsonPath('errors.amount_minor.0', 'Le dépôt ferait dépasser à la caisse sa limite de solde maximale.');

        self::assertSame(1, DB::table('teller_transactions')->where('teller_session_id', DB::table('teller_sessions')->where('public_id', $sessionPublicId)->value('id'))->count());
    }

    public function test_till_reassignment_blocked_while_session_is_open(): void
    {
        $agency = $this->createAgency('CASH-RA');
        $manager = $this->createUserWithRole('agency-manager', $agency['code'], $agency['name']);
        $teller = $this->createUserWithRole('teller', $agency['code'], $agency['name']);
        $secondTeller = $this->createUserWithRole('teller', $agency['code'], $agency['name']);
        $cashLedger = $this->createLedgerAccount($agency['id'], 'CASH-RA-TILL', LedgerAccount::ACCOUNT_CLASS_ASSET);

        $till = $this->withApiHeaders()
            ->actingAsSanctum($manager)
            ->postJson('/api/v1/tills', [
                'code' => 'TILL-RA',
                'name' => 'Reassignment Till',
                'requires_denominations' => false,
                'assigned_user_public_id' => $teller->public_id,
                'ledger_account_public_id' => $cashLedger['public_id'],
            ]);
        $this->assertJsonSuccess($till, 201);
        $tillPublicId = $this->requireStringJsonPath($till, 'data.public_id');

        $session = $this->withApiHeaders()
            ->actingAsSanctum($manager)
            ->postJson('/api/v1/teller-sessions', [
                'till_public_id' => $tillPublicId,
                'teller_user_public_id' => $teller->public_id,
                'business_date' => now()->toDateString(),
                'opening_declaration_minor' => 0,
                'currency' => 'XAF',
            ]);
        $this->assertJsonSuccess($session, 201);
        $sessionPublicId = $this->requireStringJsonPath($session, 'data.public_id');

        $blocked = $this->withApiHeaders(['X-Locale' => 'fr'])
            ->actingAsSanctum($manager)
            ->patchJson('/api/v1/tills/'.$tillPublicId, [
                'assigned_user_public_id' => $secondTeller->public_id,
            ]);
        $blocked->assertStatus(422);
        $blocked->assertJsonValidationErrors(['till']);
        $blocked->assertJsonPath('errors.till.0', 'Fermez toutes les sessions de caisse ouvertes avant de modifier l\'affectation, l\'agence, le compte du grand livre ou la devise de la caisse.');

        // Reconcile and close the session to unlock the till.
        $this->createCurrentBalancedReconciliation($sessionPublicId, $manager, 0);
        $close = $this->withApiHeaders()
            ->actingAsSanctum($manager)
            ->postJson('/api/v1/teller-sessions/'.$sessionPublicId.'/close', [
                'closing_declaration_minor' => 0,
            ]);
        $this->assertJsonSuccess($close);

        $allowed = $this->withApiHeaders()
            ->actingAsSanctum($manager)
            ->patchJson('/api/v1/tills/'.$tillPublicId, [
                'assigned_user_public_id' => $secondTeller->public_id,
            ]);
        $this->assertJsonSuccess($allowed);
        $allowed->assertJsonPath('data.assigned_user_public_id', $secondTeller->public_id);
    }

    public function test_cash_withdrawal_enforces_proxy_mandate_for_non_holder_initiator(): void
    {
        $agency = $this->createAgency('CASH-PRX');
        $actor = $this->createUserWithRole('agency-manager', $agency['code'], $agency['name']);
        $teller = $this->createUserWithRole('teller', $agency['code'], $agency['name']);
        $cashLedger = $this->createLedgerAccount($agency['id'], 'CASH-PROXY-TILL', LedgerAccount::ACCOUNT_CLASS_ASSET);
        $depositLedger = $this->createLedgerAccount($agency['id'], 'CUSTOMER-DEPOSIT-PROXY', LedgerAccount::ACCOUNT_CLASS_LIABILITY);
        $customerAccount = $this->createCustomerAccount($agency['id'], $depositLedger['id'], 'CASH-PRX-001');
        $clientId = DB::table('customer_accounts')->where('id', $customerAccount['id'])->value('client_id');
        self::assertIsInt($clientId);

        $till = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/tills', [
                'code' => 'TILL-PROXY',
                'name' => 'Proxy Till',
                'requires_denominations' => false,
                'assigned_user_public_id' => $teller->public_id,
                'ledger_account_public_id' => $cashLedger['public_id'],
                'max_withdrawal_limit_minor' => 50000,
            ]);
        $this->assertJsonSuccess($till, 201);
        $tillPublicId = $this->requireStringJsonPath($till, 'data.public_id');

        $open = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/teller-sessions', [
                'till_public_id' => $tillPublicId,
                'teller_user_public_id' => $teller->public_id,
                'business_date' => now()->toDateString(),
                'opening_declaration_minor' => 100000,
                'currency' => 'XAF',
            ]);
        $this->assertJsonSuccess($open, 201);
        $sessionPublicId = $this->requireStringJsonPath($open, 'data.public_id');

        // Seed the account with a deposit so withdrawals have funds.
        $deposit = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/teller-sessions/'.$sessionPublicId.'/deposits', [
                'customer_account_public_id' => $customerAccount['public_id'],
                'amount_minor' => 80000,
                'currency' => 'XAF',
                'idempotency_key' => 'cash-prx-seed',
            ]);
        $this->assertJsonSuccess($deposit, 201);

        $verifiedProxyPublicId = (string) Str::ulid();
        DB::table('client_proxies')->insert([
            'public_id' => $verifiedProxyPublicId,
            'agency_id' => $agency['id'],
            'client_id' => $clientId,
            'customer_account_id' => $customerAccount['id'],
            'proxy_full_name' => 'Verified Proxy',
            'mandate_type' => 'general',
            'operation_types' => json_encode(['cash_withdrawal']),
            'max_amount_minor' => 30000,
            'limit_currency' => 'XAF',
            'starts_on' => now()->subDay()->toDateString(),
            'ends_on' => now()->addMonth()->toDateString(),
            'status' => 'active',
            'verification_status' => 'verified',
            'verified_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $proxySignature = $this->createVerifiedAccountSignature($agency['id'], $customerAccount['id'], $verifiedProxyPublicId, 'proxy');
        $holderSignature = $this->createVerifiedAccountSignature($agency['id'], $customerAccount['id'], null, 'primary_holder');

        // Authorised proxy withdrawal under the mandate limit must succeed.
        $proxyWithdrawal = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/teller-sessions/'.$sessionPublicId.'/withdrawals', [
                'customer_account_public_id' => $customerAccount['public_id'],
                'amount_minor' => 20000,
                'currency' => 'XAF',
                'idempotency_key' => 'cash-prx-wd-001',
                'initiator_type' => 'proxy',
                'initiator_proxy_public_id' => $verifiedProxyPublicId,
                'signature_public_id' => $proxySignature['public_id'],
                'signature_verification_method' => 'verified_proxy_mandate',
            ]);
        $this->assertJsonSuccess($proxyWithdrawal, 201);
        $proxyWithdrawal->assertJsonPath('data.teller_transaction.initiator_type', 'proxy');
        $proxyWithdrawal->assertJsonPath('data.teller_transaction.customer_account_signature_public_id', $proxySignature['public_id']);

        // Same proxy over the per-mandate amount limit must be rejected before any write.
        $overLimit = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/teller-sessions/'.$sessionPublicId.'/withdrawals', [
                'customer_account_public_id' => $customerAccount['public_id'],
                'amount_minor' => 40000,
                'currency' => 'XAF',
                'idempotency_key' => 'cash-prx-wd-002',
                'initiator_type' => 'proxy',
                'initiator_proxy_public_id' => $verifiedProxyPublicId,
                'signature_public_id' => $proxySignature['public_id'],
                'signature_verification_method' => 'verified_proxy_mandate',
            ]);
        $overLimit->assertStatus(422);
        $overLimit->assertJsonValidationErrors(['initiator_proxy_public_id']);

        // An unverified proxy must be rejected.
        $unverifiedProxyPublicId = (string) Str::ulid();
        DB::table('client_proxies')->insert([
            'public_id' => $unverifiedProxyPublicId,
            'agency_id' => $agency['id'],
            'client_id' => $clientId,
            'customer_account_id' => $customerAccount['id'],
            'proxy_full_name' => 'Unverified Proxy',
            'mandate_type' => 'general',
            'operation_types' => json_encode(['cash_withdrawal']),
            'starts_on' => now()->subDay()->toDateString(),
            'ends_on' => now()->addMonth()->toDateString(),
            'status' => 'active',
            'verification_status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $unverified = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/teller-sessions/'.$sessionPublicId.'/withdrawals', [
                'customer_account_public_id' => $customerAccount['public_id'],
                'amount_minor' => 5000,
                'currency' => 'XAF',
                'idempotency_key' => 'cash-prx-wd-003',
                'initiator_type' => 'proxy',
                'initiator_proxy_public_id' => $unverifiedProxyPublicId,
                'signature_public_id' => $proxySignature['public_id'],
                'signature_verification_method' => 'verified_proxy_mandate',
            ]);
        $unverified->assertStatus(422);
        $unverified->assertJsonValidationErrors(['initiator_proxy_public_id']);

        // initiator_type=proxy without a proxy public id is rejected.
        $missingId = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/teller-sessions/'.$sessionPublicId.'/withdrawals', [
                'customer_account_public_id' => $customerAccount['public_id'],
                'amount_minor' => 5000,
                'currency' => 'XAF',
                'idempotency_key' => 'cash-prx-wd-004',
                'initiator_type' => 'proxy',
                'signature_public_id' => $proxySignature['public_id'],
                'signature_verification_method' => 'verified_proxy_mandate',
            ]);
        $missingId->assertStatus(422);
        $missingId->assertJsonValidationErrors(['initiator_proxy_public_id']);

        // Holder-initiated withdrawal still works (no proxy needed).
        $holderWithdrawal = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/teller-sessions/'.$sessionPublicId.'/withdrawals', [
                'customer_account_public_id' => $customerAccount['public_id'],
                'amount_minor' => 3000,
                'currency' => 'XAF',
                'idempotency_key' => 'cash-prx-wd-005',
                'initiator_type' => 'holder',
                'signature_public_id' => $holderSignature['public_id'],
                'signature_verification_method' => 'visual_match',
            ]);
        $this->assertJsonSuccess($holderWithdrawal, 201);
        $holderWithdrawal->assertJsonPath('data.teller_transaction.initiator_type', 'holder');
    }

    public function test_platform_admin_can_explicitly_manage_tills_across_agencies(): void
    {
        $agency = $this->createAgency('CASH-E');
        $actor = $this->createUserWithRole('platform-admin');
        $assignedUser = $this->createUserWithRole('teller', $agency['code'], $agency['name']);

        $create = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('platform-till')->plainTextToken])
            ->postJson('/api/v1/tills', [
                'agency_public_id' => $agency['public_id'],
                'code' => 'TILL-PLATFORM',
                'name' => 'Platform Managed Till',
                'assigned_user_public_id' => $assignedUser->public_id,
            ]);

        $this->assertJsonSuccess($create, 201);
        $create->assertJsonPath('data.agency_public_id', $agency['public_id']);
    }

    // ─── Teller transaction list & session summary (FBI2-024) ──────────────

    public function test_teller_transaction_list_enforces_agency_and_teller_scope(): void
    {
        // Agency A: two tellers with their own sessions.
        $a = $this->openCashSession('SCOPE-A');
        $teller2 = $this->createUserWithRole('teller', $a['agency']['code'], $a['agency']['name']);
        $sessionA2 = $this->openSessionForTeller($a['manager'], $a['agency'], 'SCOPE-A2', $teller2);

        $this->postDeposit($a['teller'], $a['session_public_id'], $a['account_public_id'], 10000, 'a1-dep', 'Awa One');
        $this->postWithdrawal($a['teller'], $a['session_public_id'], $a['account_public_id'], $a['signature_public_id'], 3000, 'a1-wd');
        $this->postDeposit($teller2, $sessionA2['session_public_id'], $sessionA2['account_public_id'], 4000, 'a2-dep', 'Awa Two');

        // Agency B: an unrelated session/transaction.
        $b = $this->openCashSession('SCOPE-B');
        $depB = $this->postDeposit($b['teller'], $b['session_public_id'], $b['account_public_id'], 7000, 'b1-dep', 'Bob B');
        $bTransactionId = $this->requireStringJsonPath($depB, 'data.teller_transaction.public_id');

        // Agency-manager A sees every agency-A transaction (3) and none from B.
        $managerView = $this->withApiHeaders()->actingAsSanctum($a['manager'])->getJson('/api/v1/teller-transactions');
        $this->assertJsonSuccess($managerView);
        self::assertSame(3, $managerView->json('meta.pagination.total'));
        self::assertNotContains($bTransactionId, $this->transactionPublicIds($managerView));

        // Teller 1 sees only their own session (deposit + withdrawal = 2).
        $teller1View = $this->withApiHeaders()->actingAsSanctum($a['teller'])->getJson('/api/v1/teller-transactions');
        $this->assertJsonSuccess($teller1View);
        self::assertSame(2, $teller1View->json('meta.pagination.total'));

        // Teller 2 sees only their own session (1).
        $teller2View = $this->withApiHeaders()->actingAsSanctum($teller2)->getJson('/api/v1/teller-transactions');
        $this->assertJsonSuccess($teller2View);
        self::assertSame(1, $teller2View->json('meta.pagination.total'));

        // A teller cannot reach another teller's session even via an explicit filter.
        $teller1FilteringTeller2 = $this->withApiHeaders()->actingAsSanctum($a['teller'])
            ->getJson('/api/v1/teller-transactions?filter[teller_session_public_id]='.$sessionA2['session_public_id']);
        $this->assertJsonSuccess($teller1FilteringTeller2);
        self::assertSame(0, $teller1FilteringTeller2->json('meta.pagination.total'));

        // Cross-agency manager cannot see or infer agency-A transactions.
        $managerBView = $this->withApiHeaders()->actingAsSanctum($b['manager'])->getJson('/api/v1/teller-transactions');
        $this->assertJsonSuccess($managerBView);
        self::assertSame(1, $managerBView->json('meta.pagination.total'));

        // Platform-admin sees all agencies (3 + 1 = 4).
        $admin = $this->createUserWithRole('platform-admin');
        $adminView = $this->withApiHeaders()->actingAsSanctum($admin)->getJson('/api/v1/teller-transactions');
        $this->assertJsonSuccess($adminView);
        self::assertSame(4, $adminView->json('meta.pagination.total'));
    }

    public function test_teller_session_summary_matches_totals_and_close_rules(): void
    {
        $ctx = $this->openCashSession('SUMMARY');
        $this->postDeposit($ctx['teller'], $ctx['session_public_id'], $ctx['account_public_id'], 10000, 'sum-dep');
        $this->postWithdrawal($ctx['teller'], $ctx['session_public_id'], $ctx['account_public_id'], $ctx['signature_public_id'], 3000, 'sum-wd');
        $session = DB::table('teller_sessions')->where('public_id', $ctx['session_public_id'])->first(['id', 'agency_id', 'till_id']);
        self::assertIsObject($session);
        self::assertIsInt($session->id);
        self::assertIsInt($session->agency_id);
        self::assertIsInt($session->till_id);

        DB::table('teller_transactions')->insert([
            'public_id' => (string) Str::ulid(),
            'teller_session_id' => $session->id,
            'agency_id' => $session->agency_id,
            'transaction_date' => now()->toDateString(),
            'till_id' => $session->till_id,
            'transaction_type' => TellerTransaction::TYPE_MANUAL_JOURNAL,
            'amount_minor' => 1000,
            'currency' => 'XAF',
            'status' => 'pending',
            'reference' => 'SUMMARY-PENDING-1',
            'initiator_type' => TellerTransaction::INITIATOR_SYSTEM,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $show = $this->withApiHeaders()->actingAsSanctum($ctx['manager'])
            ->getJson('/api/v1/teller-sessions/'.$ctx['session_public_id']);
        $this->assertJsonSuccess($show);

        $show->assertJsonPath('data.summary.deposits_total_minor', 10000);
        $show->assertJsonPath('data.summary.withdrawals_total_minor', 3000);
        $show->assertJsonPath('data.summary.manual_journals_total_minor', 0);
        $show->assertJsonPath('data.summary.reversals_total_minor', 0);
        $show->assertJsonPath('data.summary.transaction_count', 3);
        $show->assertJsonPath('data.summary.posted_transaction_count', 2);
        $show->assertJsonPath('data.summary.pending_transaction_count', 1);
        // opening 5000 + deposits 10000 - withdrawals 3000.
        $show->assertJsonPath('data.summary.expected_cash_balance_minor', 12000);
        self::assertNotNull($show->json('data.summary.last_transaction_at'));

        // Index summary must equal the show summary (no N+1 drift between paths).
        $index = $this->withApiHeaders()->actingAsSanctum($ctx['manager'])->getJson('/api/v1/teller-sessions');
        $this->assertJsonSuccess($index);
        $index->assertJsonPath('data.teller_sessions.0.summary.expected_cash_balance_minor', 12000);

        DB::table('teller_transactions')->where('reference', 'SUMMARY-PENDING-1')->update(['status' => TellerTransaction::STATUS_CANCELLED]);

        // The close validation accepts exactly the summary's expected balance,
        // proving the dashboard summary and close use the same direction rules.
        $this->createCurrentBalancedReconciliation($ctx['session_public_id'], $ctx['manager'], 12000);
        $close = $this->withApiHeaders()->actingAsSanctum($ctx['manager'])
            ->postJson('/api/v1/teller-sessions/'.$ctx['session_public_id'].'/close', [
                'closing_declaration_minor' => 12000,
                'currency' => 'XAF',
            ]);
        $this->assertJsonSuccess($close);
        $close->assertJsonPath('data.status', 'closed');
    }

    public function test_reversed_deposit_is_excluded_from_expected_balance_and_counted_as_reversal(): void
    {
        $ctx = $this->openCashSession('REV');
        $deposit = $this->postDeposit($ctx['teller'], $ctx['session_public_id'], $ctx['account_public_id'], 10000, 'rev-dep');
        $transactionPublicId = $this->requireStringJsonPath($deposit, 'data.teller_transaction.public_id');

        // The agency manager (with reverse permission) reverses the deposit.
        $reverse = $this->withApiHeaders()->actingAsSanctum($ctx['manager'])
            ->postJson('/api/v1/teller-transactions/'.$transactionPublicId.'/reverse', [
                'reason' => 'Posted in error',
            ]);
        $this->assertJsonSuccess($reverse, 201);

        $show = $this->withApiHeaders()->actingAsSanctum($ctx['manager'])
            ->getJson('/api/v1/teller-sessions/'.$ctx['session_public_id']);
        $this->assertJsonSuccess($show);

        // Reversed deposit no longer counts toward posted deposits or expected cash.
        $show->assertJsonPath('data.summary.deposits_total_minor', 0);
        $show->assertJsonPath('data.summary.reversals_total_minor', 10000);
        $show->assertJsonPath('data.summary.expected_cash_balance_minor', 5000);
        $show->assertJsonPath('data.summary.posted_transaction_count', 1);
        $show->assertJsonPath('data.summary.transaction_count', 2);
    }

    public function test_teller_transaction_filters_compose_and_reject_unknown_filters(): void
    {
        $ctx = $this->openCashSession('FILTER');
        $this->postDeposit($ctx['teller'], $ctx['session_public_id'], $ctx['account_public_id'], 10000, 'flt-dep', 'Filterable Depositor');
        $this->postWithdrawal($ctx['teller'], $ctx['session_public_id'], $ctx['account_public_id'], $ctx['signature_public_id'], 2000, 'flt-wd');

        // Filter by transaction type.
        $deposits = $this->withApiHeaders()->actingAsSanctum($ctx['manager'])
            ->getJson('/api/v1/teller-transactions?filter[transaction_type]='.TellerTransaction::TYPE_CASH_DEPOSIT);
        $this->assertJsonSuccess($deposits);
        self::assertSame(1, $deposits->json('meta.pagination.total'));
        $deposits->assertJsonPath('data.teller_transactions.0.transaction_type', TellerTransaction::TYPE_CASH_DEPOSIT);

        // Filter by status + exact transaction date compose together.
        $byStatusAndDate = $this->withApiHeaders()->actingAsSanctum($ctx['manager'])
            ->getJson('/api/v1/teller-transactions?filter[status]=posted&filter[transaction_date]='.now()->toDateString());
        $this->assertJsonSuccess($byStatusAndDate);
        self::assertSame(2, $byStatusAndDate->json('meta.pagination.total'));

        // Filter by customer account.
        $byAccount = $this->withApiHeaders()->actingAsSanctum($ctx['manager'])
            ->getJson('/api/v1/teller-transactions?filter[customer_account_public_id]='.$ctx['account_public_id']);
        $this->assertJsonSuccess($byAccount);
        self::assertSame(2, $byAccount->json('meta.pagination.total'));

        // Relation filters compose with the actor's agency scope.
        $bySession = $this->withApiHeaders()->actingAsSanctum($ctx['manager'])
            ->getJson('/api/v1/teller-transactions?filter[teller_session_public_id]='.$ctx['session_public_id']);
        $this->assertJsonSuccess($bySession);
        self::assertSame(2, $bySession->json('meta.pagination.total'));

        $byTill = $this->withApiHeaders()->actingAsSanctum($ctx['manager'])
            ->getJson('/api/v1/teller-transactions?filter[till_public_id]='.$ctx['till_public_id']);
        $this->assertJsonSuccess($byTill);
        self::assertSame(2, $byTill->json('meta.pagination.total'));

        $byTeller = $this->withApiHeaders()->actingAsSanctum($ctx['manager'])
            ->getJson('/api/v1/teller-transactions?filter[teller_user_public_id]='.$ctx['teller']->public_id);
        $this->assertJsonSuccess($byTeller);
        self::assertSame(2, $byTeller->json('meta.pagination.total'));

        // A future from-date returns nothing.
        $future = $this->withApiHeaders()->actingAsSanctum($ctx['manager'])
            ->getJson('/api/v1/teller-transactions?filter[transaction_date_from]='.now()->addDay()->toDateString());
        $this->assertJsonSuccess($future);
        self::assertSame(0, $future->json('meta.pagination.total'));

        $pastRange = $this->withApiHeaders()->actingAsSanctum($ctx['manager'])
            ->getJson('/api/v1/teller-transactions?filter[transaction_date_from]='.now()->subDay()->toDateString().'&filter[transaction_date_to]='.now()->toDateString());
        $this->assertJsonSuccess($pastRange);
        self::assertSame(2, $pastRange->json('meta.pagination.total'));

        $loanPublicId = $this->createLoanLinkedTellerTransaction($ctx);
        $byLoan = $this->withApiHeaders()->actingAsSanctum($ctx['manager'])
            ->getJson('/api/v1/teller-transactions?filter[loan_public_id]='.$loanPublicId);
        $this->assertJsonSuccess($byLoan);
        self::assertSame(1, $byLoan->json('meta.pagination.total'));
        $byLoan->assertJsonPath('data.teller_transactions.0.reference', 'FILTER-LOAN-1');

        // Unknown filter keys are rejected rather than silently widening the query.
        $unknown = $this->withApiHeaders()->actingAsSanctum($ctx['manager'])
            ->getJson('/api/v1/teller-transactions?filter[agency_id]=1');
        $this->assertJsonError($unknown, 422);
        $unknown->assertJsonPath('message', 'Unsupported filter parameters.');
    }

    public function test_teller_transaction_search_matches_reference_and_depositor(): void
    {
        $ctx = $this->openCashSession('SEARCH');
        $this->postDeposit($ctx['teller'], $ctx['session_public_id'], $ctx['account_public_id'], 10000, 'srch-dep', 'Unique Searchterm Name');

        $bySearch = $this->withApiHeaders()->actingAsSanctum($ctx['manager'])
            ->getJson('/api/v1/teller-transactions?search=Searchterm');
        $this->assertJsonSuccess($bySearch);
        self::assertSame(1, $bySearch->json('meta.pagination.total'));

        $noMatch = $this->withApiHeaders()->actingAsSanctum($ctx['manager'])
            ->getJson('/api/v1/teller-transactions?search=zzz-no-such-term');
        $this->assertJsonSuccess($noMatch);
        self::assertSame(0, $noMatch->json('meta.pagination.total'));
    }

    public function test_teller_transaction_list_requires_cash_transactions_view(): void
    {
        $ctx = $this->openCashSession('PERM');
        $this->postDeposit($ctx['teller'], $ctx['session_public_id'], $ctx['account_public_id'], 10000, 'perm-dep');

        // loan-officer has no cash.transactions.view permission.
        $loanOfficer = $this->createUserWithRole('loan-officer', $ctx['agency']['code'], $ctx['agency']['name']);
        $denied = $this->withApiHeaders()->actingAsSanctum($loanOfficer)->getJson('/api/v1/teller-transactions');
        $this->assertJsonError($denied, 403);
    }

    public function test_teller_remains_denied_on_operational_dashboard(): void
    {
        $agency = $this->createAgency('DASH-DENY');
        $teller = $this->createUserWithRole('teller', $agency['code'], $agency['name']);

        $response = $this->withApiHeaders()->actingAsSanctum($teller)->getJson('/api/v1/dashboards/operational');
        $this->assertJsonError($response, 403);
    }

    // ─── Teller session and reconciliation consultation (FBI2-029) ─────────

    public function test_teller_session_exact_filters_find_historical_rows_without_page_walking(): void
    {
        $ctx = $this->openCashSession('CONSULT');
        $targetPublicId = '';
        $targetDate = now()->subYears(2)->toDateString();

        for ($i = 0; $i < 105; $i++) {
            $date = now()->subYears(2)->addDays($i)->toDateString();
            $sessionPublicId = $this->createHistoricalTellerSession($ctx, $date, 'closed');
            if ($date === $targetDate) {
                $targetPublicId = $sessionPublicId;
            }
        }

        self::assertNotSame('', $targetPublicId);

        $filtered = $this->withApiHeaders()->actingAsSanctum($ctx['manager'])
            ->getJson('/api/v1/teller-sessions?per_page=5&filter[business_date]='.$targetDate.'&filter[status]=closed&filter[till_public_id]='.$ctx['till_public_id'].'&filter[teller_user_public_id]='.$ctx['teller']->public_id);
        $this->assertJsonSuccess($filtered);
        self::assertSame(1, $filtered->json('meta.pagination.total'));
        $filtered->assertJsonPath('data.teller_sessions.0.public_id', $targetPublicId);

        $range = $this->withApiHeaders()->actingAsSanctum($ctx['manager'])
            ->getJson('/api/v1/teller-sessions?filter[business_date_from]='.now()->subYears(2)->toDateString().'&filter[business_date_to]='.now()->subYears(2)->addDays(2)->toDateString().'&sort=business_date');
        $this->assertJsonSuccess($range);
        self::assertSame(3, $range->json('meta.pagination.total'));

        $unknownFilter = $this->withApiHeaders(['X-Locale' => 'fr'])->actingAsSanctum($ctx['manager'])
            ->getJson('/api/v1/teller-sessions?filter[agency_id]=1');
        $this->assertJsonError($unknownFilter, 422);
        $unknownFilter->assertJsonPath('message', 'Paramètres de filtre non pris en charge.');
        $unknownFilter->assertJsonPath('errors.filter.0', 'Les clés de filtre suivantes ne sont pas prises en charge : agency_id');

        $unknownSort = $this->withApiHeaders()->actingAsSanctum($ctx['manager'])
            ->getJson('/api/v1/teller-sessions?sort=created_at');
        $this->assertJsonError($unknownSort, 422);
    }

    public function test_teller_session_and_reconciliation_filters_are_agency_scope_safe(): void
    {
        $a = $this->openCashSession('CONSULT-A');
        $b = $this->openCashSession('CONSULT-B');
        $this->createTillReconciliation($a);
        $reconciliationB = $this->createTillReconciliation($b);

        $foreignAgencyFilter = $this->withApiHeaders()->actingAsSanctum($a['manager'])
            ->getJson('/api/v1/teller-sessions?filter[agency_public_id]='.$b['agency']['public_id']);
        $this->assertJsonError($foreignAgencyFilter, 403);

        $foreignTillFilter = $this->withApiHeaders()->actingAsSanctum($a['manager'])
            ->getJson('/api/v1/teller-sessions?filter[till_public_id]='.$b['till_public_id']);
        $this->assertJsonSuccess($foreignTillFilter);
        self::assertSame(0, $foreignTillFilter->json('meta.pagination.total'));

        $foreignReconciliationAgency = $this->withApiHeaders()->actingAsSanctum($a['manager'])
            ->getJson('/api/v1/till-reconciliations?filter[agency_public_id]='.$b['agency']['public_id']);
        $this->assertJsonError($foreignReconciliationAgency, 403);

        $foreignReconciliationTill = $this->withApiHeaders()->actingAsSanctum($a['manager'])
            ->getJson('/api/v1/till-reconciliations?filter[till_public_id]='.$b['till_public_id']);
        $this->assertJsonSuccess($foreignReconciliationTill);
        self::assertSame(0, $foreignReconciliationTill->json('meta.pagination.total'));

        $admin = $this->createUserWithRole('platform-admin');
        $adminFiltered = $this->withApiHeaders()->actingAsSanctum($admin)
            ->getJson('/api/v1/till-reconciliations?filter[agency_public_id]='.$b['agency']['public_id']);
        $this->assertJsonSuccess($adminFiltered);
        self::assertSame(1, $adminFiltered->json('meta.pagination.total'));
        $adminFiltered->assertJsonPath('data.till_reconciliations.0.public_id', $reconciliationB);
    }

    public function test_standalone_reconciliation_index_matches_nested_session_index(): void
    {
        $ctx = $this->openCashSession('RECON-IDX');
        $reconciliationPublicId = $this->createTillReconciliation($ctx);

        $nested = $this->withApiHeaders()->actingAsSanctum($ctx['manager'])
            ->getJson('/api/v1/teller-sessions/'.$ctx['session_public_id'].'/reconciliations');
        $this->assertJsonSuccess($nested);
        self::assertSame(1, $nested->json('meta.pagination.total'));
        $nested->assertJsonPath('data.till_reconciliations.0.public_id', $reconciliationPublicId);

        $standalone = $this->withApiHeaders()->actingAsSanctum($ctx['manager'])
            ->getJson('/api/v1/till-reconciliations?filter[teller_session_public_id]='.$ctx['session_public_id'].'&filter[till_public_id]='.$ctx['till_public_id'].'&filter[teller_user_public_id]='.$ctx['teller']->public_id.'&filter[business_date]='.now()->toDateString().'&filter[status]=balanced');
        $this->assertJsonSuccess($standalone);
        self::assertSame(1, $standalone->json('meta.pagination.total'));
        $standalone->assertJsonPath('data.till_reconciliations.0.public_id', $reconciliationPublicId);

        $unknownFilter = $this->withApiHeaders(['X-Locale' => 'fr'])->actingAsSanctum($ctx['manager'])
            ->getJson('/api/v1/till-reconciliations?filter[session_id]=1');
        $this->assertJsonError($unknownFilter, 422);
        $unknownFilter->assertJsonPath('message', 'Paramètres de filtre non pris en charge.');
        $unknownFilter->assertJsonPath('errors.filter.0', 'Les clés de filtre suivantes ne sont pas prises en charge : session_id');
    }

    // ─── Deposit / withdrawal tender payload contract (FBI2-025) ──────────

    public function test_cash_transaction_options_catalog_matches_validation_contract(): void
    {
        $ctx = $this->openCashSession('TENDER-OPT');

        $options = $this->withApiHeaders()->actingAsSanctum($ctx['teller'])
            ->getJson('/api/v1/reference/cash-transaction-options?teller_session_public_id='.$ctx['session_public_id']);
        $this->assertJsonSuccess($options);
        self::assertSame(['cash', 'cheque', 'transfer', 'mixed'], $options->json('data.payment_methods'));
        $chequeFields = $options->json('data.required_fields_by_payment_method.cheque');
        $notificationChannels = $options->json('data.notification_channels');
        self::assertIsArray($chequeFields);
        self::assertIsArray($notificationChannels);
        self::assertContains('cheque_number', $chequeFields);
        self::assertContains('sms', $notificationChannels);

        $invalid = $this->withApiHeaders()->actingAsSanctum($ctx['teller'])
            ->postJson('/api/v1/teller-sessions/'.$ctx['session_public_id'].'/deposits', [
                'customer_account_public_id' => $ctx['account_public_id'],
                'amount_minor' => 1000,
                'currency' => 'XAF',
                'payment_method' => 'card',
            ]);
        $invalid->assertStatus(422);
        $invalid->assertJsonValidationErrors(['payment_method']);
    }

    public function test_cash_tender_validates_denominations_and_rejects_frontend_fee_override(): void
    {
        $ctx = $this->openCashSession('TENDER-CASH');
        $note500 = $this->createDenomination('TENDER-CASH-500', 500);

        $bad = $this->withApiHeaders()->actingAsSanctum($ctx['teller'])
            ->postJson('/api/v1/teller-sessions/'.$ctx['session_public_id'].'/deposits', [
                'customer_account_public_id' => $ctx['account_public_id'],
                'amount_minor' => 1000,
                'currency' => 'XAF',
                'payment_method' => 'cash',
                'cash_amount_minor' => 1000,
                'denomination_counts' => [
                    ['denomination_public_id' => $note500['public_id'], 'count' => 1],
                ],
            ]);
        $bad->assertStatus(422);
        $bad->assertJsonValidationErrors(['denomination_counts']);

        $feeOverride = $this->withApiHeaders()->actingAsSanctum($ctx['teller'])
            ->postJson('/api/v1/teller-sessions/'.$ctx['session_public_id'].'/deposits', [
                'customer_account_public_id' => $ctx['account_public_id'],
                'amount_minor' => 1000,
                'currency' => 'XAF',
                'fee_amount_minor' => 999,
            ]);
        $feeOverride->assertStatus(422);
        $feeOverride->assertJsonValidationErrors(['fee_amount_minor']);

        $ok = $this->withApiHeaders()->actingAsSanctum($ctx['teller'])
            ->postJson('/api/v1/teller-sessions/'.$ctx['session_public_id'].'/deposits', [
                'customer_account_public_id' => $ctx['account_public_id'],
                'amount_minor' => 1000,
                'currency' => 'XAF',
                'payment_method' => 'cash',
                'cash_amount_minor' => 1000,
                'denomination_counts' => [
                    ['denomination_public_id' => $note500['public_id'], 'count' => 2],
                ],
            ]);
        $this->assertJsonSuccess($ok, 201);
        $ok->assertJsonPath('data.teller_transaction.payment_method', 'cash');
        $ok->assertJsonPath('data.teller_transaction.fees_applied', false);
        $ok->assertJsonPath('data.teller_transaction.fee_amount_minor', 0);
        $ok->assertJsonPath('data.teller_transaction.tenders.0.method', 'cash');
    }

    public function test_cheque_transfer_and_mixed_deposits_persist_tenders_without_moving_till_cash(): void
    {
        $ctx = $this->openCashSession('TENDER-DEP');
        $this->configureCashTenderMappings($ctx['agency']['id']);

        $missingChequeMetadata = $this->withApiHeaders()->actingAsSanctum($ctx['teller'])
            ->postJson('/api/v1/teller-sessions/'.$ctx['session_public_id'].'/deposits', [
                'customer_account_public_id' => $ctx['account_public_id'],
                'amount_minor' => 2000,
                'currency' => 'XAF',
                'payment_method' => 'cheque',
            ]);
        $missingChequeMetadata->assertStatus(422);
        $missingChequeMetadata->assertJsonValidationErrors(['cheque_number', 'cheque_bank_name', 'cheque_issue_date']);

        $cheque = $this->withApiHeaders()->actingAsSanctum($ctx['teller'])
            ->postJson('/api/v1/teller-sessions/'.$ctx['session_public_id'].'/deposits', [
                'customer_account_public_id' => $ctx['account_public_id'],
                'amount_minor' => 2000,
                'currency' => 'XAF',
                'payment_method' => 'cheque',
                'cheque_number' => 'CHQ-100',
                'cheque_bank_name' => 'Bank A',
                'cheque_issue_date' => now()->toDateString(),
                'notify_customer' => true,
                'notification_channels' => ['sms'],
            ]);
        $this->assertJsonSuccess($cheque, 201);
        $cheque->assertJsonPath('data.teller_transaction.cash_amount_minor', 0);
        $cheque->assertJsonPath('data.teller_transaction.cheque_amount_minor', 2000);
        $cheque->assertJsonPath('data.teller_transaction.notification_status', 'queued');
        $chequePublicId = $this->requireStringJsonPath($cheque, 'data.teller_transaction.public_id');
        $this->assertDatabaseHas('teller_transaction_tenders', [
            'method' => 'cheque',
            'amount_minor' => 2000,
            'cheque_number' => 'CHQ-100',
        ]);
        $this->assertDatabaseHas('notification_deliveries', [
            'category' => 'cash_deposit_posted',
            'idempotency_key' => 'cash-'.$chequePublicId.'-sms',
        ]);

        $transfer = $this->withApiHeaders(['Idempotency-Key' => 'wd-transfer-1'])->actingAsSanctum($ctx['teller'])
            ->postJson('/api/v1/teller-sessions/'.$ctx['session_public_id'].'/deposits', [
                'customer_account_public_id' => $ctx['account_public_id'],
                'amount_minor' => 3000,
                'currency' => 'XAF',
                'payment_method' => 'transfer',
                'external_reference' => 'TRF-DEP-1',
                'channel' => 'bank_transfer',
            ]);
        $this->assertJsonSuccess($transfer, 201);
        $transfer->assertJsonPath('data.teller_transaction.transfer_amount_minor', 3000);

        $mixed = $this->withApiHeaders(['Idempotency-Key' => 'wd-mixed-1'])->actingAsSanctum($ctx['teller'])
            ->postJson('/api/v1/teller-sessions/'.$ctx['session_public_id'].'/deposits', [
                'customer_account_public_id' => $ctx['account_public_id'],
                'amount_minor' => 5000,
                'currency' => 'XAF',
                'payment_method' => 'mixed',
                'cash_amount_minor' => 1000,
                'transfer_amount_minor' => 4000,
                'external_reference' => 'TRF-MIX-1',
                'channel' => 'internal_transfer',
            ]);
        $this->assertJsonSuccess($mixed, 201);
        $mixed->assertJsonPath('data.teller_transaction.payment_method', 'mixed');
        $mixedDepositTenders = $mixed->json('data.teller_transaction.tenders');
        self::assertIsArray($mixedDepositTenders);
        self::assertCount(2, $mixedDepositTenders);

        $session = $this->withApiHeaders()->actingAsSanctum($ctx['teller'])
            ->getJson('/api/v1/teller-sessions/'.$ctx['session_public_id']);
        $this->assertJsonSuccess($session);
        $session->assertJsonPath('data.summary.expected_cash_balance_minor', 6000);
    }

    public function test_cheque_transfer_and_mixed_withdrawals_persist_tenders_and_idempotency_contract(): void
    {
        $ctx = $this->openCashSession('TENDER-WD');
        $this->configureCashTenderMappings($ctx['agency']['id']);

        $deposit = $this->postDeposit($ctx['teller'], $ctx['session_public_id'], $ctx['account_public_id'], 12000, 'tender-wd-fund');
        $this->assertJsonSuccess($deposit, 201);

        $cheque = $this->withApiHeaders(['Idempotency-Key' => 'wd-cheque-1'])->actingAsSanctum($ctx['teller'])
            ->postJson('/api/v1/teller-sessions/'.$ctx['session_public_id'].'/withdrawals', [
                'customer_account_public_id' => $ctx['account_public_id'],
                'amount_minor' => 2000,
                'currency' => 'XAF',
                'payment_method' => 'cheque',
                'cheque_number' => 'CHQ-WD-1',
                'cheque_bank_name' => 'Bank B',
                'cheque_issue_date' => now()->toDateString(),
                'signature_public_id' => $ctx['signature_public_id'],
                'signature_verification_method' => 'visual_match',
            ]);
        $this->assertJsonSuccess($cheque, 201);
        $cheque->assertJsonPath('data.teller_transaction.cash_amount_minor', 0);

        $replay = $this->withApiHeaders(['Idempotency-Key' => 'wd-cheque-1', 'X-Locale' => 'fr'])->actingAsSanctum($ctx['teller'])
            ->postJson('/api/v1/teller-sessions/'.$ctx['session_public_id'].'/withdrawals', [
                'customer_account_public_id' => $ctx['account_public_id'],
                'amount_minor' => 2000,
                'currency' => 'XAF',
                'payment_method' => 'transfer',
                'transfer_amount_minor' => 2000,
                'external_reference' => 'TRF-WD-REPLAY',
                'signature_public_id' => $ctx['signature_public_id'],
                'signature_verification_method' => 'visual_match',
            ]);
        $replay->assertStatus(422);
        $replay->assertJsonValidationErrors(['idempotency_key']);
        $replay->assertJsonPath('errors.idempotency_key.0', 'La clé Idempotency-Key a déjà été utilisée pour une autre répartition de paiement.');

        $transfer = $this->withApiHeaders(['Idempotency-Key' => 'wd-transfer-1'])->actingAsSanctum($ctx['teller'])
            ->postJson('/api/v1/teller-sessions/'.$ctx['session_public_id'].'/withdrawals', [
                'customer_account_public_id' => $ctx['account_public_id'],
                'amount_minor' => 3000,
                'currency' => 'XAF',
                'payment_method' => 'transfer',
                'external_reference' => 'TRF-WD-1',
                'channel' => 'bank_transfer',
                'signature_public_id' => $ctx['signature_public_id'],
                'signature_verification_method' => 'visual_match',
            ]);
        $this->assertJsonSuccess($transfer, 201);
        $transfer->assertJsonPath('data.teller_transaction.transfer_amount_minor', 3000);

        $mixed = $this->withApiHeaders(['Idempotency-Key' => 'wd-mixed-1'])->actingAsSanctum($ctx['teller'])
            ->postJson('/api/v1/teller-sessions/'.$ctx['session_public_id'].'/withdrawals', [
                'customer_account_public_id' => $ctx['account_public_id'],
                'amount_minor' => 4000,
                'currency' => 'XAF',
                'payment_method' => 'mixed',
                'cash_amount_minor' => 1000,
                'transfer_amount_minor' => 3000,
                'external_reference' => 'TRF-WD-MIX',
                'signature_public_id' => $ctx['signature_public_id'],
                'signature_verification_method' => 'visual_match',
            ]);
        $this->assertJsonSuccess($mixed, 201);
        $mixedWithdrawalTenders = $mixed->json('data.teller_transaction.tenders');
        self::assertIsArray($mixedWithdrawalTenders);
        self::assertCount(2, $mixedWithdrawalTenders);

        $session = $this->withApiHeaders()->actingAsSanctum($ctx['teller'])
            ->getJson('/api/v1/teller-sessions/'.$ctx['session_public_id']);
        $this->assertJsonSuccess($session);
        $session->assertJsonPath('data.summary.expected_cash_balance_minor', 16000);
        self::assertSame(5, DB::table('teller_transaction_tenders')->count());
    }

    /**
     * @param  array{id:int, code:string, name:string, public_id:string}  $agency
     * @return array{session_public_id:string, till_public_id:string, account_public_id:string}
     */
    private function openSessionForTeller(User $manager, array $agency, string $code, User $teller): array
    {
        $cashLedger = $this->createLedgerAccount($agency['id'], $code.'-TILL-LED', LedgerAccount::ACCOUNT_CLASS_ASSET);
        $depositLedger = $this->createLedgerAccount($agency['id'], $code.'-DEP-LED', LedgerAccount::ACCOUNT_CLASS_LIABILITY);
        $account = $this->createCustomerAccount($agency['id'], $depositLedger['id'], $code.'-ACC');

        $till = $this->withApiHeaders()->actingAsSanctum($manager)->postJson('/api/v1/tills', [
            'code' => $code.'-TILL',
            'name' => $code.' Till',
            'requires_denominations' => false,
            'assigned_user_public_id' => $teller->public_id,
            'ledger_account_public_id' => $cashLedger['public_id'],
            'max_withdrawal_limit_minor' => 100000,
        ]);
        $this->assertJsonSuccess($till, 201);
        $tillPublicId = $this->requireStringJsonPath($till, 'data.public_id');

        $open = $this->withApiHeaders()->actingAsSanctum($manager)->postJson('/api/v1/teller-sessions', [
            'till_public_id' => $tillPublicId,
            'teller_user_public_id' => $teller->public_id,
            'business_date' => now()->toDateString(),
            'opening_declaration_minor' => 5000,
            'currency' => 'XAF',
        ]);
        $this->assertJsonSuccess($open, 201);

        return [
            'session_public_id' => $this->requireStringJsonPath($open, 'data.public_id'),
            'till_public_id' => $tillPublicId,
            'account_public_id' => $account['public_id'],
        ];
    }

    /**
     * @return array{agency: array{id:int, code:string, name:string, public_id:string}, manager: User, teller: User, session_public_id: string, till_public_id: string, account_public_id: string, signature_public_id: string}
     */
    private function openCashSession(string $code): array
    {
        $agency = $this->createAgency($code);
        $manager = $this->createUserWithRole('agency-manager', $agency['code'], $agency['name']);
        $teller = $this->createUserWithRole('teller', $agency['code'], $agency['name']);

        $session = $this->openSessionForTeller($manager, $agency, $code, $teller);
        $accountId = DB::table('customer_accounts')->where('public_id', $session['account_public_id'])->value('id');
        self::assertIsInt($accountId);
        $signature = $this->createVerifiedAccountSignature($agency['id'], $accountId);

        return [
            'agency' => $agency,
            'manager' => $manager,
            'teller' => $teller,
            'session_public_id' => $session['session_public_id'],
            'till_public_id' => $session['till_public_id'],
            'account_public_id' => $session['account_public_id'],
            'signature_public_id' => $signature['public_id'],
        ];
    }

    /**
     * @param  array{agency: array{id:int, code:string, name:string, public_id:string}, manager: User, teller: User, session_public_id: string, till_public_id: string, account_public_id: string, signature_public_id: string}  $ctx
     */
    private function createHistoricalTellerSession(array $ctx, string $businessDate, string $status): string
    {
        $tillId = DB::table('tills')->where('public_id', $ctx['till_public_id'])->value('id');
        self::assertIsInt($tillId);

        $publicId = (string) Str::ulid();
        DB::table('teller_sessions')->insert([
            'public_id' => $publicId,
            'till_id' => $tillId,
            'agency_id' => $ctx['agency']['id'],
            'teller_user_id' => $ctx['teller']->id,
            'business_date' => $businessDate,
            'opened_at' => $businessDate.' 08:00:00',
            'closed_at' => $businessDate.' 17:00:00',
            'opening_declaration_minor' => 5000,
            'closing_declaration_minor' => 5000,
            'currency' => 'XAF',
            'status' => $status,
            'created_at' => $businessDate.' 08:00:00',
            'updated_at' => $businessDate.' 17:00:00',
        ]);

        return $publicId;
    }

    /**
     * @param  array{agency: array{id:int, code:string, name:string, public_id:string}, manager: User, teller: User, session_public_id: string, till_public_id: string, account_public_id: string, signature_public_id: string}  $ctx
     */
    private function createTillReconciliation(array $ctx): string
    {
        $sessionId = DB::table('teller_sessions')->where('public_id', $ctx['session_public_id'])->value('id');
        self::assertIsInt($sessionId);

        $publicId = (string) Str::ulid();
        DB::table('till_reconciliations')->insert([
            'public_id' => $publicId,
            'teller_session_id' => $sessionId,
            'counted_by_user_id' => $ctx['manager']->id,
            'counted_at' => now(),
            'reconciliation_date' => now(),
            'theoretical_balance_minor' => 5000,
            'actual_balance_minor' => 5000,
            'difference_minor' => 0,
            'currency' => 'XAF',
            'status' => 'balanced',
            'notes' => 'Consultation fixture',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $publicId;
    }

    private function createCurrentBalancedReconciliation(
        string $sessionPublicId,
        User $countedBy,
        int $balanceMinor,
    ): string {
        $sessionId = DB::table('teller_sessions')->where('public_id', $sessionPublicId)->value('id');
        self::assertIsInt($sessionId);

        $publicId = (string) Str::ulid();
        DB::table('till_reconciliations')->insert([
            'public_id' => $publicId,
            'teller_session_id' => $sessionId,
            'counted_by_user_id' => $countedBy->id,
            'counted_at' => now(),
            'reconciliation_date' => now(),
            'theoretical_balance_minor' => $balanceMinor,
            'actual_balance_minor' => $balanceMinor,
            'difference_minor' => 0,
            'currency' => 'XAF',
            'status' => 'balanced',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $publicId;
    }

    private function postDeposit(User $actor, string $sessionPublicId, string $accountPublicId, int $amount, string $idempotencyKey, ?string $depositorName = null): TestResponse
    {
        $response = $this->withApiHeaders()->actingAsSanctum($actor)
            ->postJson('/api/v1/teller-sessions/'.$sessionPublicId.'/deposits', [
                'customer_account_public_id' => $accountPublicId,
                'amount_minor' => $amount,
                'currency' => 'XAF',
                'idempotency_key' => $idempotencyKey,
                'operation_code' => 'cash_deposit',
                'depositor_name' => $depositorName ?? 'Counter Depositor',
                'description' => 'Counter cash deposit',
            ]);
        $this->assertJsonSuccess($response, 201);

        return $response;
    }

    private function postWithdrawal(User $actor, string $sessionPublicId, string $accountPublicId, string $signaturePublicId, int $amount, string $idempotencyKey): TestResponse
    {
        $response = $this->withApiHeaders()->actingAsSanctum($actor)
            ->postJson('/api/v1/teller-sessions/'.$sessionPublicId.'/withdrawals', [
                'customer_account_public_id' => $accountPublicId,
                'amount_minor' => $amount,
                'currency' => 'XAF',
                'signature_public_id' => $signaturePublicId,
                'signature_verification_method' => 'visual_match',
                'idempotency_key' => $idempotencyKey,
            ]);
        $this->assertJsonSuccess($response, 201);

        return $response;
    }

    /**
     * @param  array{agency: array{id:int, code:string, name:string, public_id:string}, manager: User, teller: User, session_public_id: string, till_public_id: string, account_public_id: string, signature_public_id: string}  $ctx
     */
    private function createLoanLinkedTellerTransaction(array $ctx): string
    {
        $session = DB::table('teller_sessions')->where('public_id', $ctx['session_public_id'])->first(['id', 'agency_id', 'till_id']);
        $account = DB::table('customer_accounts')->where('public_id', $ctx['account_public_id'])->first(['client_id']);
        self::assertIsObject($session);
        self::assertIsObject($account);
        self::assertIsInt($session->id);
        self::assertIsInt($session->agency_id);
        self::assertIsInt($session->till_id);
        self::assertIsInt($account->client_id);

        $ledger = $this->createLedgerAccount($ctx['agency']['id'], 'FILTER-LOAN-LED', LedgerAccount::ACCOUNT_CLASS_ASSET);
        $productId = DB::table('loan_products')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'ledger_account_id' => $ledger['id'],
            'code' => 'FILTER-LOAN-PRODUCT',
            'name' => 'Filter Loan Product',
            'status' => 'active',
            'min_amount_minor' => 100000,
            'max_amount_minor' => 1000000,
            'min_term_count' => 3,
            'max_term_count' => 12,
            'term_unit' => 'month',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $loanPublicId = (string) Str::ulid();
        $loanId = DB::table('loans')->insertGetId([
            'public_id' => $loanPublicId,
            'client_id' => $account->client_id,
            'agency_id' => $ctx['agency']['id'],
            'loan_product_id' => $productId,
            'loan_number' => 'FILTER-LOAN-1',
            'requested_amount_minor' => 150000,
            'currency' => 'XAF',
            'applied_on' => now()->toDateString(),
            'status' => 'application',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('teller_transactions')->insert([
            'public_id' => (string) Str::ulid(),
            'teller_session_id' => $session->id,
            'agency_id' => $session->agency_id,
            'transaction_date' => now()->toDateString(),
            'till_id' => $session->till_id,
            'transaction_type' => TellerTransaction::TYPE_MANUAL_JOURNAL,
            'loan_id' => $loanId,
            'amount_minor' => 150000,
            'currency' => 'XAF',
            'status' => TellerTransaction::STATUS_POSTED,
            'reference' => 'FILTER-LOAN-1',
            'initiator_type' => TellerTransaction::INITIATOR_SYSTEM,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $loanPublicId;
    }

    /**
     * @return array<int, string>
     */
    private function transactionPublicIds(TestResponse $response): array
    {
        $rows = $response->json('data.teller_transactions');
        $ids = [];
        if (is_array($rows)) {
            foreach ($rows as $row) {
                if (is_array($row) && isset($row['public_id']) && is_string($row['public_id'])) {
                    $ids[] = $row['public_id'];
                }
            }
        }

        return $ids;
    }

    private function assertNoCashWorkflowRecords(): void
    {
        $this->assertDatabaseCount('teller_sessions', 0);
        $this->assertDatabaseCount('teller_transactions', 0);
        $this->assertDatabaseCount('till_reconciliations', 0);
        $this->assertDatabaseCount('till_reconciliation_lines', 0);
        $this->assertDatabaseCount('journal_entries', 0);
        $this->assertDatabaseCount('journal_lines', 0);
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
    private function createLedgerAccount(int $agencyId, string $code, string $accountClass, string $status = LedgerAccount::STATUS_ACTIVE): array
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
            'status' => $status,
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
    private function createDenomination(string $code, int $valueMinor, string $currency = 'XAF'): array
    {
        $id = DB::table('denominations')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'code' => $code,
            'label' => $code,
            'value_minor' => $valueMinor,
            'currency' => $currency,
            'type' => 'banknote',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $denomination = DB::table('denominations')->where('id', $id)->first(['public_id']);

        return [
            'id' => $id,
            'public_id' => is_object($denomination) && is_string($denomination->public_id) ? $denomination->public_id : '',
        ];
    }

    /**
     * @return array{id:int, public_id:string}
     */
    private function createOperationCode(string $code, string $module): array
    {
        $id = DB::table('operation_codes')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'code' => $code,
            'label' => $code,
            'module' => $module,
            'operation_type' => 'manual_journal',
            'direction' => null,
            'status' => 'active',
            'metadata' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $operationCode = DB::table('operation_codes')->where('id', $id)->first(['public_id']);

        return [
            'id' => $id,
            'public_id' => is_object($operationCode) && is_string($operationCode->public_id) ? $operationCode->public_id : '',
        ];
    }

    private function configureCashTenderMappings(int $agencyId): void
    {
        foreach ([
            'cash_deposit_cheque' => LedgerAccount::ACCOUNT_CLASS_ASSET,
            'cash_deposit_transfer' => LedgerAccount::ACCOUNT_CLASS_ASSET,
            'cash_withdrawal_cheque' => LedgerAccount::ACCOUNT_CLASS_LIABILITY,
            'cash_withdrawal_transfer' => LedgerAccount::ACCOUNT_CLASS_LIABILITY,
        ] as $operationCode => $accountClass) {
            $operation = $this->createOperationCode($operationCode, 'cash');
            $ledger = $this->createLedgerAccount($agencyId, strtoupper(str_replace('_', '-', $operationCode)), $accountClass);

            DB::table('operation_account_mappings')->insert([
                'public_id' => (string) Str::ulid(),
                'operation_code_id' => $operation['id'],
                'agency_id' => $agencyId,
                'debit_ledger_account_id' => str_starts_with($operationCode, 'cash_deposit') ? $ledger['id'] : null,
                'credit_ledger_account_id' => str_starts_with($operationCode, 'cash_withdrawal') ? $ledger['id'] : null,
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
        }
    }

    /**
     * @return array{id:int, public_id:string}
     */
    private function createBatchProcedure(string $code): array
    {
        $id = DB::table('batch_procedures')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'code' => $code,
            'name' => $code,
            'description' => null,
            'schedule_type' => 'manual',
            'schedule_metadata' => null,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $procedure = DB::table('batch_procedures')->where('id', $id)->first(['public_id']);

        return [
            'id' => $id,
            'public_id' => is_object($procedure) && is_string($procedure->public_id) ? $procedure->public_id : '',
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
            'phone_number' => '+237650000000',
            'email' => 'cash-'.$accountNumber.'@example.test',
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
     * @return array{id:int, public_id:string}
     */
    private function createVerifiedAccountSignature(int $agencyId, int $customerAccountId, ?string $proxyPublicId = null, string $signatureType = 'primary_holder'): array
    {
        $account = DB::table('customer_accounts')->where('id', $customerAccountId)->first(['client_id']);
        self::assertIsObject($account);
        self::assertIsInt($account->client_id);
        $proxyId = null;
        if ($proxyPublicId !== null) {
            $proxyId = DB::table('client_proxies')->where('public_id', $proxyPublicId)->value('id');
            self::assertIsInt($proxyId);
        }

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
            'client_proxy_id' => $proxyId,
            'signature_type' => $signatureType,
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
