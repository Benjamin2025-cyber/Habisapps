<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Application\Notifications\ClientAlertProducer;
use App\Application\Notifications\InternalAlertProducer;
use App\Application\Notifications\NotificationConsentManager;
use App\Application\Notifications\NotificationDeliveryRetryManager;
use App\Application\Notifications\NotificationOutbox;
use App\Application\Notifications\NotificationTemplateManager;
use App\Application\Notifications\UserNotificationFeed;
use App\Models\AccountingDay;
use App\Models\LedgerAccount;
use App\Models\LoanProduct;
use App\Models\StaffAgencyAssignment;
use App\Models\User;
use App\Models\UserNotification;
use Carbon\Carbon;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class NotificationsFoundationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_consent_opt_in_then_opt_out_records_both_timestamps(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $agency = $this->createAgency('NTF01');
        $client = $this->createClient($agency['id']);

        $optIn = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/clients/'.$client['public_id'].'/notification-consents', [
                'channel' => 'sms',
                'category' => 'loan_due',
                'language' => 'fr',
                'status' => 'opted_in',
            ]);
        $this->assertJsonSuccess($optIn);
        $optIn->assertJsonPath('data.status', 'opted_in');

        $this->assertDatabaseHas('client_notification_consents', [
            'client_id' => $client['id'],
            'channel' => 'sms',
            'category' => 'loan_due',
            'status' => 'opted_in',
        ]);

        $optOut = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/clients/'.$client['public_id'].'/notification-consents', [
                'channel' => 'sms',
                'category' => 'loan_due',
                'language' => 'fr',
                'status' => 'opted_out',
            ]);
        $this->assertJsonSuccess($optOut);
        $optOut->assertJsonPath('data.status', 'opted_out');

        $row = DB::table('client_notification_consents')
            ->where('client_id', $client['id'])
            ->where('channel', 'sms')
            ->where('category', 'loan_due')
            ->first();
        self::assertIsObject($row);
        self::assertNotNull(((array) $row)['opted_in_at']);
        self::assertNotNull(((array) $row)['opted_out_at']);

        self::assertSame(1, DB::table('client_notification_consents')
            ->where('client_id', $client['id'])
            ->count());
    }

    public function test_notification_feed_scopes_user_agency_and_platform_notifications(): void
    {
        $agencyA = $this->createAgency('NTF-A');
        $agencyB = $this->createAgency('NTF-B');
        $tellerA = $this->createUserWithRole('teller', $agencyA['id']);
        $tellerB = $this->createUserWithRole('teller', $agencyB['id']);
        $loanOfficer = $this->createUserWithRole('loan-officer', $agencyA['id']);
        $platformAdmin = $this->createUserWithRole('platform-admin');
        $feed = app(UserNotificationFeed::class);

        $own = $feed->notifyUser(
            user: $loanOfficer,
            type: UserNotification::TYPE_INFO,
            category: 'loan_ready_for_disbursement',
            title: 'Loan ready',
            message: 'Loan LN-1 is ready for disbursement.',
            sourceType: 'loan',
            sourcePublicId: 'LN-1',
            agencyId: $agencyA['id'],
        );
        $agency = $feed->notifyAgency(
            agencyId: $agencyA['id'],
            type: UserNotification::TYPE_SUCCESS,
            category: 'cash_deposit_posted',
            title: 'Cash deposit posted',
            message: 'A cash deposit was posted.',
            sourceType: 'teller_transaction',
            sourcePublicId: 'TXN-A',
        );
        $feed->notifyAgency(
            agencyId: $agencyB['id'],
            type: UserNotification::TYPE_WARNING,
            category: 'cash_withdrawal_posted',
            title: 'Cash withdrawal posted',
            message: 'A cash withdrawal was posted.',
            sourceType: 'teller_transaction',
            sourcePublicId: 'TXN-B',
        );
        $platform = $feed->notifyPlatform(
            type: UserNotification::TYPE_ERROR,
            category: 'report_run_generated',
            title: 'Platform report generated',
            message: 'A platform report was generated.',
            sourceType: 'report_run',
            sourcePublicId: 'RUN-1',
        );

        $loanOfficerFeed = $this->withApiHeaders()
            ->actingAsSanctum($loanOfficer)
            ->getJson('/api/v1/notifications');
        $this->assertJsonSuccess($loanOfficerFeed);
        self::assertSame([$own->public_id], $this->notificationPublicIds($loanOfficerFeed->json('data.notifications')));

        $tellerAFeed = $this->withApiHeaders()
            ->actingAsSanctum($tellerA)
            ->getJson('/api/v1/notifications?filter[category]=cash_deposit_posted');
        $this->assertJsonSuccess($tellerAFeed);
        $tellerAFeed->assertJsonPath('data.notifications.0.public_id', $agency->public_id);
        $tellerAFeed->assertJsonPath('data.notifications.0.agency_public_id', $agencyA['public_id']);

        $tellerBFeed = $this->withApiHeaders()
            ->actingAsSanctum($tellerB)
            ->getJson('/api/v1/notifications?filter[category]=cash_deposit_posted');
        $this->assertJsonSuccess($tellerBFeed);
        self::assertSame([], $tellerBFeed->json('data.notifications'));

        $platformFeed = $this->withApiHeaders()
            ->actingAsSanctum($platformAdmin)
            ->getJson('/api/v1/notifications?filter[category]=report_run_generated');
        $this->assertJsonSuccess($platformFeed);
        $platformFeed->assertJsonPath('data.notifications.0.public_id', $platform->public_id);
    }

    public function test_notification_read_state_is_per_user_and_visibility_safe(): void
    {
        $agencyA = $this->createAgency('NTF-R-A');
        $agencyB = $this->createAgency('NTF-R-B');
        $teller = $this->createUserWithRole('teller', $agencyA['id']);
        $manager = $this->createUserWithRole('agency-manager', $agencyA['id']);
        $otherTeller = $this->createUserWithRole('teller', $agencyB['id']);

        $notification = app(UserNotificationFeed::class)->notifyAgency(
            agencyId: $agencyA['id'],
            type: UserNotification::TYPE_WARNING,
            category: 'till_reconciliation_rejected',
            title: 'Reconciliation rejected',
            message: 'A till reconciliation requires correction.',
            sourceType: 'till_reconciliation',
            sourcePublicId: 'REC-1',
        );

        $read = $this->withApiHeaders()
            ->actingAsSanctum($teller)
            ->postJson('/api/v1/notifications/'.$notification->public_id.'/read');
        $this->assertJsonSuccess($read);
        self::assertNotNull($read->json('data.notification.read_at'));

        $tellerRead = $this->withApiHeaders()
            ->actingAsSanctum($teller)
            ->getJson('/api/v1/notifications?filter[read]=true');
        $this->assertJsonSuccess($tellerRead);
        self::assertSame([$notification->public_id], $this->notificationPublicIds($tellerRead->json('data.notifications')));

        $managerUnread = $this->withApiHeaders()
            ->actingAsSanctum($manager)
            ->getJson('/api/v1/notifications?filter[read]=false');
        $this->assertJsonSuccess($managerUnread);
        self::assertSame([$notification->public_id], $this->notificationPublicIds($managerUnread->json('data.notifications')));
        $managerUnread->assertJsonPath('data.notifications.0.read_at', null);

        $this->withApiHeaders()
            ->actingAsSanctum($otherTeller)
            ->postJson('/api/v1/notifications/'.$notification->public_id.'/read')
            ->assertStatus(404);
    }

    public function test_marking_notifications_read_is_not_blocked_when_the_accounting_day_is_closed(): void
    {
        // Consultation-only mode (day closed, auto-open disabled): a user may
        // still mark their own notifications read. These routes are allowlisted
        // out of the registration lock; a closed agency day would otherwise
        // block any registration write.
        config(['security.accounting_day.auto_open_on_missing' => false]);

        $agency = $this->createAgency('NTF-LOCK');
        $teller = $this->createUserWithRole('teller', $agency['id']);

        AccountingDay::query()->create([
            'public_id' => (string) Str::ulid(),
            'scope_type' => AccountingDay::SCOPE_AGENCY,
            'agency_id' => $agency['id'],
            'business_date' => '2026-06-01',
            'status' => AccountingDay::STATUS_CLOSED,
            'calendar_opened_at' => now(),
            'calendar_closed_at' => now(),
            'origin' => AccountingDay::ORIGIN_MANUAL,
        ]);

        $notification = app(UserNotificationFeed::class)->notifyAgency(
            agencyId: $agency['id'],
            type: UserNotification::TYPE_INFO,
            category: 'cash_session_opened',
            title: 'Session opened',
            message: 'A teller session was opened.',
            sourceType: 'teller_session',
            sourcePublicId: 'SES-LOCK',
        );

        $read = $this->withApiHeaders()
            ->actingAsSanctum($teller)
            ->postJson('/api/v1/notifications/'.$notification->public_id.'/read');
        $this->assertJsonSuccess($read);
        self::assertNotNull($read->json('data.notification.read_at'));

        $readAll = $this->withApiHeaders()
            ->actingAsSanctum($teller)
            ->postJson('/api/v1/notifications/read-all');
        $this->assertJsonSuccess($readAll);
    }

    public function test_notification_read_all_and_filters_only_apply_to_visible_rows(): void
    {
        $agencyA = $this->createAgency('NTF-RA-A');
        $agencyB = $this->createAgency('NTF-RA-B');
        $teller = $this->createUserWithRole('teller', $agencyA['id']);
        $feed = app(UserNotificationFeed::class);

        $feed->notifyAgency(
            agencyId: $agencyA['id'],
            type: UserNotification::TYPE_SUCCESS,
            category: 'cash_session_opened',
            title: 'Session opened',
            message: 'A teller session was opened.',
            sourceType: 'teller_session',
            sourcePublicId: 'SES-A',
        );
        $feed->notifyAgency(
            agencyId: $agencyB['id'],
            type: UserNotification::TYPE_SUCCESS,
            category: 'cash_session_opened',
            title: 'Other session opened',
            message: 'Another agency opened a teller session.',
            sourceType: 'teller_session',
            sourcePublicId: 'SES-B',
        );

        $readAll = $this->withApiHeaders()
            ->actingAsSanctum($teller)
            ->postJson('/api/v1/notifications/read-all');
        $this->assertJsonSuccess($readAll);
        $readAll->assertJsonPath('data.marked_read_count', 1);

        $read = $this->withApiHeaders()
            ->actingAsSanctum($teller)
            ->getJson('/api/v1/notifications?filter[read]=true&filter[type]=success&search=Session');
        $this->assertJsonSuccess($read);
        $notifications = $read->json('data.notifications');
        self::assertIsArray($notifications);
        self::assertCount(1, $notifications);
        $read->assertJsonPath('data.notifications.0.category', 'cash_session_opened');
    }

    public function test_notification_feed_does_not_duplicate_same_source_category_recipient(): void
    {
        $agency = $this->createAgency('NTF-IDEM');
        $feed = app(UserNotificationFeed::class);

        $first = $feed->notifyAgency(
            agencyId: $agency['id'],
            type: UserNotification::TYPE_SUCCESS,
            category: 'cash_transaction_reversed',
            title: 'Transaction reversed',
            message: 'A teller transaction was reversed.',
            sourceType: 'teller_transaction',
            sourcePublicId: 'TXN-REV-1',
        );
        $second = $feed->notifyAgency(
            agencyId: $agency['id'],
            type: UserNotification::TYPE_SUCCESS,
            category: 'cash_transaction_reversed',
            title: 'Transaction reversed again',
            message: 'Duplicate producer replay.',
            sourceType: 'teller_transaction',
            sourcePublicId: 'TXN-REV-1',
        );

        self::assertSame($first->public_id, $second->public_id);
        self::assertSame(1, DB::table('user_notifications')
            ->where('recipient_type', UserNotification::RECIPIENT_AGENCY)
            ->where('recipient_id', $agency['id'])
            ->where('source_type', 'teller_transaction')
            ->where('source_public_id', 'TXN-REV-1')
            ->where('category', 'cash_transaction_reversed')
            ->count());
    }

    public function test_default_notification_permissions_are_exposed_to_roles(): void
    {
        $agency = $this->createAgency('NTF-PERM');
        $teller = $this->createUserWithRole('teller', $agency['id']);
        $manager = $this->createUserWithRole('agency-manager', $agency['id']);
        $platformAdmin = $this->createUserWithRole('platform-admin');

        self::assertTrue($teller->can('notifications.view'));
        self::assertFalse($teller->can('notifications.manage'));
        self::assertTrue($manager->can('notifications.view'));
        self::assertFalse($manager->can('notifications.manage'));
        self::assertTrue($platformAdmin->can('notifications.view'));
        self::assertTrue($platformAdmin->can('notifications.manage'));
    }

    public function test_consent_is_tracked_per_language(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $agency = $this->createAgency('NTF-LANG');
        $client = $this->createClient($agency['id']);

        foreach (['fr', 'en'] as $language) {
            $response = $this->withApiHeaders()
                ->actingAsSanctum($actor)
                ->postJson('/api/v1/clients/'.$client['public_id'].'/notification-consents', [
                    'channel' => 'sms',
                    'category' => 'loan_due',
                    'language' => $language,
                    'status' => 'opted_in',
                ]);
            $this->assertJsonSuccess($response);
            $response->assertJsonPath('data.language', $language);
        }

        self::assertSame(2, DB::table('client_notification_consents')
            ->where('client_id', $client['id'])
            ->where('channel', 'sms')
            ->where('category', 'loan_due')
            ->count());
        self::assertTrue(app(NotificationConsentManager::class)->hasOptedInForLanguage($client['id'], 'sms', 'loan_due', 'fr'));
        self::assertTrue(app(NotificationConsentManager::class)->hasOptedInForLanguage($client['id'], 'sms', 'loan_due', 'en'));
    }

    public function test_template_creation_increments_version_per_code(): void
    {
        $templates = app(NotificationTemplateManager::class);

        $v1 = $templates->createVersion(
            code: 'loan_due_alert',
            channel: 'sms',
            category: 'loan_due',
            language: 'fr',
            bodyTemplate: 'Bonjour {{client_name}}, votre échéance de {{amount}} FCFA est due le {{due_date}}.',
            subject: null,
            variablesAllowlist: ['client_name', 'amount', 'due_date'],
        );
        self::assertSame(1, (int) (((array) $v1)['version'] ?? 0));

        $v2 = $templates->createVersion(
            code: 'loan_due_alert',
            channel: 'sms',
            category: 'loan_due',
            language: 'fr',
            bodyTemplate: 'Rappel: {{client_name}} doit {{amount}} le {{due_date}}.',
            subject: null,
            variablesAllowlist: ['client_name', 'amount', 'due_date'],
        );
        self::assertSame(2, (int) (((array) $v2)['version'] ?? 0));

        $resolved = $templates->resolveActive('loan_due_alert', 'loan_due', 'fr');
        self::assertIsObject($resolved);
        self::assertSame(2, (int) (((array) $resolved)['version'] ?? 0));
    }

    public function test_template_with_unknown_variable_is_rejected_at_creation(): void
    {
        $templates = app(NotificationTemplateManager::class);

        $this->expectException(InvalidArgumentException::class);
        $templates->createVersion(
            code: 'invalid_alert',
            channel: 'sms',
            category: 'loan_due',
            language: 'fr',
            bodyTemplate: 'Hello {{client_name}}, your secret is {{leaked_password}}.',
            subject: null,
            variablesAllowlist: ['client_name'],
        );
    }

    public function test_inactive_template_cannot_be_resolved_or_rendered(): void
    {
        $templates = app(NotificationTemplateManager::class);

        $template = $templates->createVersion(
            code: 'paused_alert',
            channel: 'sms',
            category: 'loan_due',
            language: 'fr',
            bodyTemplate: 'Hello {{client_name}}.',
            subject: null,
            variablesAllowlist: ['client_name'],
            status: NotificationTemplateManager::STATUS_INACTIVE,
        );

        self::assertNull($templates->resolveActive('paused_alert', 'loan_due', 'fr'));

        $this->expectException(InvalidArgumentException::class);
        $templates->render($template, ['client_name' => 'Jean']);
    }

    public function test_render_substitutes_allowed_variables_and_fails_on_unknown(): void
    {
        $templates = app(NotificationTemplateManager::class);

        $template = $templates->createVersion(
            code: 'render_test',
            channel: 'sms',
            category: 'loan_due',
            language: 'fr',
            bodyTemplate: 'Bonjour {{client_name}}, montant: {{amount}}.',
            subject: null,
            variablesAllowlist: ['client_name', 'amount'],
        );

        $rendered = $templates->render($template, [
            'client_name' => 'Marie',
            'amount' => '15000',
        ]);
        self::assertSame('Bonjour Marie, montant: 15000.', $rendered);

        $this->expectException(InvalidArgumentException::class);
        $templates->render($template, [
            'client_name' => 'Marie',
            'amount' => '15000',
            'account_balance' => '999999999',
        ]);
    }

    public function test_template_creation_rejects_unsupported_channel_category_and_status(): void
    {
        $templates = app(NotificationTemplateManager::class);

        $this->expectException(InvalidArgumentException::class);
        $templates->createVersion(
            code: 'bad_channel',
            channel: 'fax',
            category: 'loan_due',
            language: 'fr',
            bodyTemplate: 'Bonjour {{client_name}}.',
            subject: null,
            variablesAllowlist: ['client_name'],
        );
    }

    public function test_outbox_idempotency_key_suppresses_duplicate_enqueue(): void
    {
        $templates = app(NotificationTemplateManager::class);
        $outbox = app(NotificationOutbox::class);

        $templates->createVersion(
            code: 'idem_alert',
            channel: 'sms',
            category: 'loan_due',
            language: 'fr',
            bodyTemplate: 'Bonjour {{client_name}}.',
            subject: null,
            variablesAllowlist: ['client_name'],
        );

        $first = $outbox->enqueue(
            templateCode: 'idem_alert',
            category: 'loan_due',
            channel: 'sms',
            destination: '+237600000001',
            idempotencyKey: 'loan_due:client-1:2026-05-20',
            variables: ['client_name' => 'Jean'],
        );
        $second = $outbox->enqueue(
            templateCode: 'idem_alert',
            category: 'loan_due',
            channel: 'sms',
            destination: '+237600000001',
            idempotencyKey: 'loan_due:client-1:2026-05-20',
            variables: ['client_name' => 'Jean'],
        );

        self::assertSame(
            (int) (((array) $first)['id'] ?? 0),
            (int) (((array) $second)['id'] ?? 0),
        );
        self::assertSame(1, DB::table('notification_deliveries')
            ->where('idempotency_key', 'loan_due:client-1:2026-05-20')
            ->count());
    }

    public function test_outbox_failed_message_retries_up_to_max_attempts(): void
    {
        $templates = app(NotificationTemplateManager::class);
        $outbox = app(NotificationOutbox::class);
        $retry = app(NotificationDeliveryRetryManager::class);

        $templates->createVersion(
            code: 'retry_alert',
            channel: 'sms',
            category: 'loan_due',
            language: 'fr',
            bodyTemplate: 'Test {{client_name}}.',
            subject: null,
            variablesAllowlist: ['client_name'],
        );

        $delivery = $outbox->enqueue(
            templateCode: 'retry_alert',
            category: 'loan_due',
            channel: 'sms',
            destination: '+237600000002',
            idempotencyKey: 'retry-test:1',
            variables: ['client_name' => 'Marie'],
            maxAttempts: 3,
        );
        $deliveryId = (int) (((array) $delivery)['id'] ?? 0);

        $afterFirst = $retry->recordFailure('notification_deliveries', $deliveryId, 'provider timeout');
        self::assertSame('failed', (string) (((array) $afterFirst)['status'] ?? ''));
        self::assertSame(1, (int) (((array) $afterFirst)['retry_count'] ?? 0));
        self::assertNotNull(((array) $afterFirst)['next_attempt_at']);

        $afterSecond = $retry->recordFailure('notification_deliveries', $deliveryId, 'provider timeout');
        self::assertSame('failed', (string) (((array) $afterSecond)['status'] ?? ''));
        self::assertSame(2, (int) (((array) $afterSecond)['retry_count'] ?? 0));

        $afterThird = $retry->recordFailure('notification_deliveries', $deliveryId, 'provider timeout');
        self::assertSame('permanently_failed', (string) (((array) $afterThird)['status'] ?? ''));
        self::assertSame(3, (int) (((array) $afterThird)['retry_count'] ?? 0));
        self::assertNull(((array) $afterThird)['next_attempt_at']);
    }

    public function test_loan_due_producer_creates_one_alert_for_consented_client(): void
    {
        $this->seedLoanDueTemplate();
        $context = $this->seedLoanDueToday(amountMinor: 12000);
        $consents = app(NotificationConsentManager::class);
        $consents->setConsent(
            clientId: $context['client_id'],
            agencyId: $context['agency_id'],
            channel: 'sms',
            category: 'loan_due',
            language: 'fr',
            status: NotificationConsentManager::STATUS_OPTED_IN,
            changedBy: null,
        );

        $producer = app(ClientAlertProducer::class);
        $created = $producer->produceLoanDueAlerts(now());

        self::assertSame(1, $created);
        $this->assertDatabaseHas('notification_deliveries', [
            'category' => 'loan_due',
            'channel' => 'sms',
            'destination' => $context['phone_number'],
            'status' => 'pending',
        ]);
        self::assertSame(1, DB::table('notification_deliveries')->where('category', 'loan_due')->count());
    }

    public function test_loan_due_producer_does_not_duplicate_on_rerun(): void
    {
        $this->seedLoanDueTemplate();
        $context = $this->seedLoanDueToday(amountMinor: 12000);
        $consents = app(NotificationConsentManager::class);
        $consents->setConsent(
            clientId: $context['client_id'],
            agencyId: $context['agency_id'],
            channel: 'sms',
            category: 'loan_due',
            language: 'fr',
            status: NotificationConsentManager::STATUS_OPTED_IN,
            changedBy: null,
        );

        $producer = app(ClientAlertProducer::class);
        self::assertSame(1, $producer->produceLoanDueAlerts(now()));
        self::assertSame(0, $producer->produceLoanDueAlerts(now()));
        self::assertSame(1, DB::table('notification_deliveries')->where('category', 'loan_due')->count());
    }

    public function test_loan_due_producer_skips_clients_without_consent(): void
    {
        $this->seedLoanDueTemplate();
        $this->seedLoanDueToday(amountMinor: 12000);

        $producer = app(ClientAlertProducer::class);
        self::assertSame(0, $producer->produceLoanDueAlerts(now()));
        self::assertSame(0, DB::table('notification_deliveries')->where('category', 'loan_due')->count());
    }

    public function test_loan_due_producer_uses_only_active_schedules_for_active_loans(): void
    {
        $this->seedLoanDueTemplate();
        $context = $this->seedLoanDueToday(amountMinor: 12000);
        app(NotificationConsentManager::class)->setConsent(
            clientId: $context['client_id'],
            agencyId: $context['agency_id'],
            channel: 'sms',
            category: 'loan_due',
            language: 'fr',
            status: NotificationConsentManager::STATUS_OPTED_IN,
            changedBy: null,
        );

        DB::table('loan_schedule_snapshots')
            ->where('loan_id', $context['loan_id'])
            ->update(['status' => 'superseded']);

        $producer = app(ClientAlertProducer::class);
        self::assertSame(0, $producer->produceLoanDueAlerts(now()));

        DB::table('loan_schedule_snapshots')
            ->where('loan_id', $context['loan_id'])
            ->update(['status' => 'active']);
        DB::table('loans')
            ->where('id', $context['loan_id'])
            ->update(['status' => 'closed']);

        self::assertSame(0, $producer->produceLoanDueAlerts(now()));
        self::assertSame(0, DB::table('notification_deliveries')->where('category', 'loan_due')->count());
    }

    private function seedLoanDueTemplate(): void
    {
        app(NotificationTemplateManager::class)->createVersion(
            code: ClientAlertProducer::TEMPLATE_LOAN_DUE,
            channel: 'sms',
            category: 'loan_due',
            language: 'fr',
            bodyTemplate: 'Bonjour {{client_name}}, votre échéance {{loan_number}} de {{amount}} est due le {{due_date}}.',
            subject: null,
            variablesAllowlist: ['client_name', 'amount', 'due_date', 'loan_number'],
        );
    }

    /**
     * @return array{
     *     agency_id:int,
     *     client_id:int,
     *     loan_id:int,
     *     phone_number:string,
     * }
     */
    private function seedLoanDueToday(int $amountMinor): array
    {
        $agency = $this->createAgency('NTF-L-'.Str::random(3));
        $clientPublicId = (string) Str::ulid();
        $phoneNumber = '+23760'.random_int(1000000, 9999999);
        $clientId = DB::table('clients')->insertGetId([
            'public_id' => $clientPublicId,
            'agency_id' => $agency['id'],
            'client_reference' => 'CLI-'.Str::ulid(),
            'first_name' => 'Loan',
            'last_name' => 'Borrower',
            'status' => 'active',
            'kyc_status' => 'verified',
            'phone_number' => $phoneNumber,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $ledgerId = DB::table('ledger_accounts')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'agency_id' => $agency['id'],
            'code' => 'LP-'.Str::ulid(),
            'name' => 'Loan Product Ledger',
            'account_class' => LedgerAccount::ACCOUNT_CLASS_ASSET,
            'normal_balance_side' => LedgerAccount::NORMAL_BALANCE_DEBIT,
            'status' => LedgerAccount::STATUS_ACTIVE,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $product = LoanProduct::query()->create([
            'public_id' => (string) Str::ulid(),
            'ledger_account_id' => $ledgerId,
            'code' => 'LP-'.Str::ulid(),
            'name' => 'Loan Product',
            'status' => LoanProduct::STATUS_ACTIVE,
            'min_amount_minor' => 100000,
            'max_amount_minor' => 10000000,
            'min_term_count' => 3,
            'max_term_count' => 24,
            'term_unit' => LoanProduct::TERM_UNIT_MONTH,
        ]);

        $loanId = DB::table('loans')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'client_id' => $clientId,
            'agency_id' => $agency['id'],
            'loan_product_id' => $product->id,
            'loan_number' => 'LN-'.Str::ulid(),
            'requested_amount_minor' => 500000,
            'approved_principal_minor' => 500000,
            'currency' => 'XAF',
            'applied_on' => now()->subDays(30)->toDateString(),
            'approved_on' => now()->subDays(28)->toDateString(),
            'disbursed_on' => now()->subDays(25)->toDateString(),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $snapshotId = DB::table('loan_schedule_snapshots')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'loan_id' => $loanId,
            'formula_engine_key' => 'flat_interest_v1',
            'policy_snapshot_hash' => str_repeat('a', 64),
            'status' => 'active',
            'generated_at' => now()->subDays(25),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('loan_schedule_lines')->insert([
            'loan_schedule_snapshot_id' => $snapshotId,
            'installment_number' => 1,
            'due_date' => now()->toDateString(),
            'principal_minor' => (int) ($amountMinor * 0.8),
            'interest_minor' => (int) ($amountMinor * 0.2),
            'fees_minor' => 0,
            'insurance_minor' => 0,
            'tax_minor' => 0,
            'currency' => 'XAF',
            'status' => 'scheduled',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [
            'agency_id' => $agency['id'],
            'client_id' => $clientId,
            'loan_id' => $loanId,
            'phone_number' => $phoneNumber,
        ];
    }

    public function test_report_deadline_producer_targets_configured_roles(): void
    {
        $this->seedInternalAlertTemplates();
        $businessDate = Carbon::parse('2026-05-10');
        $this->seedReportDefinitionWithDeadline([
            'frequency' => 'monthly',
            'due_day_of_month' => 12,
            'alert_lead_days' => 5,
            'target_roles' => ['platform-admin'],
        ]);

        $producer = app(InternalAlertProducer::class);
        $created = $producer->produceReportDeadlineAlerts($businessDate);

        self::assertSame(1, $created);
        $this->assertDatabaseHas('notification_deliveries', [
            'category' => 'report_deadline',
            'channel' => 'in_app',
            'destination' => 'platform-admin',
            'recipient_type' => Role::class,
            'status' => 'pending',
        ]);
    }

    public function test_failed_report_producer_escalates_to_target_roles(): void
    {
        $this->seedInternalAlertTemplates();
        $definitionId = $this->seedReportDefinitionWithDeadline([
            'frequency' => 'monthly',
            'due_day_of_month' => 10,
            'failure_target_roles' => ['platform-admin'],
        ]);

        $runPublicId = (string) Str::ulid();
        DB::table('report_runs')->insert([
            'public_id' => $runPublicId,
            'report_definition_id' => $definitionId,
            'agency_id' => null,
            'period_starts_on' => Carbon::today()->subMonth()->startOfMonth()->toDateString(),
            'period_ends_on' => Carbon::today()->subMonth()->endOfMonth()->toDateString(),
            'status' => 'failed',
            'generated_at' => now(),
            'parameters' => null,
            'summary' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $producer = app(InternalAlertProducer::class);
        $created = $producer->produceFailedReportAlerts(Carbon::today());

        self::assertSame(1, $created);
        $alert = DB::table('notification_deliveries')
            ->where('category', 'report_failed')
            ->where('destination', 'platform-admin')
            ->first();
        self::assertIsObject($alert);
        $metadata = json_decode((string) (((array) $alert)['metadata'] ?? '{}'), true);
        self::assertIsArray($metadata);
        self::assertTrue($metadata['escalated'] ?? false);
        self::assertSame($runPublicId, $metadata['report_run_public_id'] ?? null);
    }

    public function test_failed_report_producer_is_idempotent_on_rerun(): void
    {
        $this->seedInternalAlertTemplates();
        $definitionId = $this->seedReportDefinitionWithDeadline([
            'frequency' => 'monthly',
            'due_day_of_month' => 10,
            'failure_target_roles' => ['platform-admin'],
        ]);

        DB::table('report_runs')->insert([
            'public_id' => (string) Str::ulid(),
            'report_definition_id' => $definitionId,
            'period_starts_on' => null,
            'period_ends_on' => null,
            'status' => 'failed',
            'generated_at' => now(),
            'parameters' => null,
            'summary' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $producer = app(InternalAlertProducer::class);
        self::assertSame(1, $producer->produceFailedReportAlerts(Carbon::today()));
        self::assertSame(0, $producer->produceFailedReportAlerts(Carbon::today()));
        self::assertSame(1, DB::table('notification_deliveries')->where('category', 'report_failed')->count());
    }

    private function seedInternalAlertTemplates(): void
    {
        $templates = app(NotificationTemplateManager::class);
        $templates->createVersion(
            code: InternalAlertProducer::TEMPLATE_REPORT_DEADLINE,
            channel: 'in_app',
            category: 'report_deadline',
            language: 'fr',
            bodyTemplate: 'Report {{report_code}} ({{report_name}}) is due on {{deadline}} ({{days_until}} days).',
            subject: 'Report deadline approaching',
            variablesAllowlist: ['report_code', 'report_name', 'deadline', 'days_until'],
        );
        $templates->createVersion(
            code: InternalAlertProducer::TEMPLATE_REPORT_FAILED,
            channel: 'in_app',
            category: 'report_failed',
            language: 'fr',
            bodyTemplate: 'Report run {{run_public_id}} for {{report_code}} ended in {{run_status}}.',
            subject: 'Scheduled report failed',
            variablesAllowlist: ['report_code', 'report_name', 'run_status', 'run_public_id'],
        );
    }

    /**
     * @param  array<string, mixed>  $schedule
     */
    private function seedReportDefinitionWithDeadline(array $schedule): int
    {
        return DB::table('report_definitions')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'code' => 'COBAC-MONTHLY-'.Str::random(4),
            'name' => 'COBAC Monthly Statement',
            'report_type' => 'cobac_monthly',
            'module' => 'reporting',
            'status' => 'active',
            'definition' => json_encode(['schedule' => $schedule], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_loan_overdue_producer_creates_alert_for_consented_client(): void
    {
        $this->seedLoanOverdueTemplate();
        $context = $this->seedLoanArrearOverdue(unpaidMinor: 8000, daysOverdue: 3);
        app(NotificationConsentManager::class)->setConsent(
            clientId: $context['client_id'],
            agencyId: $context['agency_id'],
            channel: 'sms',
            category: 'loan_overdue',
            language: 'fr',
            status: NotificationConsentManager::STATUS_OPTED_IN,
            changedBy: null,
        );

        $created = app(ClientAlertProducer::class)->produceLoanOverdueAlerts(Carbon::today());

        self::assertSame(1, $created);
        $this->assertDatabaseHas('notification_deliveries', [
            'category' => 'loan_overdue',
            'channel' => 'sms',
            'destination' => $context['phone_number'],
            'status' => 'pending',
        ]);
    }

    public function test_loan_overdue_producer_does_not_duplicate_on_rerun(): void
    {
        $this->seedLoanOverdueTemplate();
        $context = $this->seedLoanArrearOverdue(unpaidMinor: 8000, daysOverdue: 3);
        app(NotificationConsentManager::class)->setConsent(
            clientId: $context['client_id'],
            agencyId: $context['agency_id'],
            channel: 'sms',
            category: 'loan_overdue',
            language: 'fr',
            status: NotificationConsentManager::STATUS_OPTED_IN,
            changedBy: null,
        );

        $producer = app(ClientAlertProducer::class);
        self::assertSame(1, $producer->produceLoanOverdueAlerts(Carbon::today()));
        self::assertSame(0, $producer->produceLoanOverdueAlerts(Carbon::today()));
        self::assertSame(1, DB::table('notification_deliveries')->where('category', 'loan_overdue')->count());
    }

    public function test_loan_overdue_producer_skips_clients_without_consent(): void
    {
        $this->seedLoanOverdueTemplate();
        $this->seedLoanArrearOverdue(unpaidMinor: 8000, daysOverdue: 3);

        self::assertSame(0, app(ClientAlertProducer::class)->produceLoanOverdueAlerts(Carbon::today()));
        self::assertSame(0, DB::table('notification_deliveries')->where('category', 'loan_overdue')->count());
    }

    public function test_insurance_premium_due_producer_creates_alert_for_consented_client(): void
    {
        $this->seedInsurancePremiumDueTemplate();
        $context = $this->seedInsurancePremiumDueToday(premiumMinor: 5000);
        app(NotificationConsentManager::class)->setConsent(
            clientId: $context['client_id'],
            agencyId: $context['agency_id'],
            channel: 'sms',
            category: 'insurance_premium_due',
            language: 'fr',
            status: NotificationConsentManager::STATUS_OPTED_IN,
            changedBy: null,
        );

        $created = app(ClientAlertProducer::class)->produceInsurancePremiumDueAlerts(Carbon::today());

        self::assertSame(1, $created);
        $this->assertDatabaseHas('notification_deliveries', [
            'category' => 'insurance_premium_due',
            'channel' => 'sms',
            'destination' => $context['phone_number'],
            'status' => 'pending',
        ]);
    }

    public function test_insurance_premium_due_producer_does_not_duplicate_on_rerun(): void
    {
        $this->seedInsurancePremiumDueTemplate();
        $context = $this->seedInsurancePremiumDueToday(premiumMinor: 5000);
        app(NotificationConsentManager::class)->setConsent(
            clientId: $context['client_id'],
            agencyId: $context['agency_id'],
            channel: 'sms',
            category: 'insurance_premium_due',
            language: 'fr',
            status: NotificationConsentManager::STATUS_OPTED_IN,
            changedBy: null,
        );

        $producer = app(ClientAlertProducer::class);
        self::assertSame(1, $producer->produceInsurancePremiumDueAlerts(Carbon::today()));
        self::assertSame(0, $producer->produceInsurancePremiumDueAlerts(Carbon::today()));
        self::assertSame(1, DB::table('notification_deliveries')->where('category', 'insurance_premium_due')->count());
    }

    public function test_insurance_premium_due_producer_skips_clients_without_consent(): void
    {
        $this->seedInsurancePremiumDueTemplate();
        $this->seedInsurancePremiumDueToday(premiumMinor: 5000);

        self::assertSame(0, app(ClientAlertProducer::class)->produceInsurancePremiumDueAlerts(Carbon::today()));
        self::assertSame(0, DB::table('notification_deliveries')->where('category', 'insurance_premium_due')->count());
    }

    public function test_claim_decision_producer_creates_alert_for_consented_client(): void
    {
        $this->seedClaimDecisionTemplate();
        $context = $this->seedInsuranceClaimWithDecision(status: 'approved');
        app(NotificationConsentManager::class)->setConsent(
            clientId: $context['client_id'],
            agencyId: $context['agency_id'],
            channel: 'sms',
            category: 'insurance_claim_decision',
            language: 'fr',
            status: NotificationConsentManager::STATUS_OPTED_IN,
            changedBy: null,
        );

        $created = app(ClientAlertProducer::class)->produceInsuranceClaimDecisionAlerts(Carbon::today());

        self::assertSame(1, $created);
        $this->assertDatabaseHas('notification_deliveries', [
            'category' => 'insurance_claim_decision',
            'channel' => 'sms',
            'destination' => $context['phone_number'],
            'status' => 'pending',
        ]);
    }

    public function test_claim_decision_producer_does_not_duplicate_on_rerun(): void
    {
        $this->seedClaimDecisionTemplate();
        $context = $this->seedInsuranceClaimWithDecision(status: 'rejected');
        app(NotificationConsentManager::class)->setConsent(
            clientId: $context['client_id'],
            agencyId: $context['agency_id'],
            channel: 'sms',
            category: 'insurance_claim_decision',
            language: 'fr',
            status: NotificationConsentManager::STATUS_OPTED_IN,
            changedBy: null,
        );

        $producer = app(ClientAlertProducer::class);
        self::assertSame(1, $producer->produceInsuranceClaimDecisionAlerts(Carbon::today()));
        self::assertSame(0, $producer->produceInsuranceClaimDecisionAlerts(Carbon::today()));
        self::assertSame(1, DB::table('notification_deliveries')->where('category', 'insurance_claim_decision')->count());
    }

    public function test_claim_decision_producer_skips_clients_without_consent(): void
    {
        $this->seedClaimDecisionTemplate();
        $this->seedInsuranceClaimWithDecision(status: 'settled');

        self::assertSame(0, app(ClientAlertProducer::class)->produceInsuranceClaimDecisionAlerts(Carbon::today()));
        self::assertSame(0, DB::table('notification_deliveries')->where('category', 'insurance_claim_decision')->count());
    }

    public function test_consent_service_rejects_unsupported_channel_and_category(): void
    {
        $consents = app(NotificationConsentManager::class);
        $agency = $this->createAgency('NTF02');
        $client = $this->createClient($agency['id']);

        $this->expectException(InvalidArgumentException::class);
        $consents->setConsent(
            clientId: $client['id'],
            agencyId: $agency['id'],
            channel: 'fax',
            category: 'loan_due',
            language: 'fr',
            status: NotificationConsentManager::STATUS_OPTED_IN,
            changedBy: null,
        );
    }

    private function seedLoanOverdueTemplate(): void
    {
        app(NotificationTemplateManager::class)->createVersion(
            code: ClientAlertProducer::TEMPLATE_LOAN_OVERDUE,
            channel: 'sms',
            category: 'loan_overdue',
            language: 'fr',
            bodyTemplate: 'Bonjour {{client_name}}, votre prêt {{loan_number}} a {{amount}} FCFA en retard depuis {{days_overdue}} jours.',
            subject: null,
            variablesAllowlist: ['client_name', 'amount', 'days_overdue', 'loan_number'],
        );
    }

    /**
     * @return array{agency_id:int, client_id:int, phone_number:string}
     */
    private function seedLoanArrearOverdue(int $unpaidMinor, int $daysOverdue): array
    {
        $agency = $this->createAgency('NTF-OD-'.Str::random(3));
        $phoneNumber = '+23760'.random_int(1000000, 9999999);
        $clientId = DB::table('clients')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'agency_id' => $agency['id'],
            'client_reference' => 'CLI-'.Str::ulid(),
            'first_name' => 'Overdue',
            'last_name' => 'Borrower',
            'status' => 'active',
            'kyc_status' => 'verified',
            'phone_number' => $phoneNumber,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $ledgerId = DB::table('ledger_accounts')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'agency_id' => $agency['id'],
            'code' => 'LP-OD-'.Str::ulid(),
            'name' => 'Overdue Loan Ledger',
            'account_class' => LedgerAccount::ACCOUNT_CLASS_ASSET,
            'normal_balance_side' => LedgerAccount::NORMAL_BALANCE_DEBIT,
            'status' => LedgerAccount::STATUS_ACTIVE,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $product = LoanProduct::query()->create([
            'public_id' => (string) Str::ulid(),
            'ledger_account_id' => $ledgerId,
            'code' => 'LP-OD-'.Str::ulid(),
            'name' => 'Overdue Loan Product',
            'status' => LoanProduct::STATUS_ACTIVE,
            'min_amount_minor' => 100000,
            'max_amount_minor' => 10000000,
            'min_term_count' => 3,
            'max_term_count' => 24,
            'term_unit' => LoanProduct::TERM_UNIT_MONTH,
        ]);

        $loanId = DB::table('loans')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'client_id' => $clientId,
            'agency_id' => $agency['id'],
            'loan_product_id' => $product->id,
            'loan_number' => 'LN-OD-'.Str::ulid(),
            'requested_amount_minor' => 500000,
            'approved_principal_minor' => 500000,
            'currency' => 'XAF',
            'applied_on' => now()->subDays(60)->toDateString(),
            'approved_on' => now()->subDays(58)->toDateString(),
            'disbursed_on' => now()->subDays(55)->toDateString(),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('loan_arrears')->insert([
            'public_id' => (string) Str::ulid(),
            'loan_id' => $loanId,
            'due_on' => now()->subDays($daysOverdue)->toDateString(),
            'original_due_minor' => $unpaidMinor,
            'paid_minor' => 0,
            'unpaid_minor' => $unpaidMinor,
            'currency' => 'XAF',
            'status' => 'open',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [
            'agency_id' => $agency['id'],
            'client_id' => $clientId,
            'phone_number' => $phoneNumber,
        ];
    }

    private function seedInsurancePremiumDueTemplate(): void
    {
        app(NotificationTemplateManager::class)->createVersion(
            code: ClientAlertProducer::TEMPLATE_INSURANCE_PREMIUM_DUE,
            channel: 'sms',
            category: 'insurance_premium_due',
            language: 'fr',
            bodyTemplate: 'Bonjour {{client_name}}, votre prime d\'assurance {{subscription_number}} de {{amount}} FCFA est due le {{due_date}}.',
            subject: null,
            variablesAllowlist: ['client_name', 'amount', 'due_date', 'subscription_number'],
        );
    }

    /**
     * @return array{agency_id:int, client_id:int, phone_number:string}
     */
    private function seedInsurancePremiumDueToday(int $premiumMinor): array
    {
        $agency = $this->createAgency('NTF-INS-'.Str::random(3));
        $phoneNumber = '+23760'.random_int(1000000, 9999999);
        $clientId = DB::table('clients')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'agency_id' => $agency['id'],
            'client_reference' => 'CLI-'.Str::ulid(),
            'first_name' => 'Insurance',
            'last_name' => 'Subscriber',
            'status' => 'active',
            'kyc_status' => 'verified',
            'phone_number' => $phoneNumber,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $productId = DB::table('insurance_products')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'code' => 'INS-'.Str::ulid(),
            'name' => 'Test Insurance Product',
            'product_type' => 'borrower',
            'currency' => 'XAF',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $subscriptionId = DB::table('insurance_subscriptions')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'client_id' => $clientId,
            'agency_id' => $agency['id'],
            'insurance_product_id' => $productId,
            'subscription_number' => 'SUB-'.Str::ulid(),
            'starts_on' => now()->subMonth()->toDateString(),
            'ends_on' => now()->addYear()->toDateString(),
            'currency' => 'XAF',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('insurance_premium_assessments')->insert([
            'public_id' => (string) Str::ulid(),
            'insurance_subscription_id' => $subscriptionId,
            'premium_amount_minor' => $premiumMinor,
            'currency' => 'XAF',
            'due_on' => now()->toDateString(),
            'assessed_at' => now(),
            'status' => 'assessed',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [
            'agency_id' => $agency['id'],
            'client_id' => $clientId,
            'phone_number' => $phoneNumber,
        ];
    }

    private function seedClaimDecisionTemplate(): void
    {
        app(NotificationTemplateManager::class)->createVersion(
            code: ClientAlertProducer::TEMPLATE_CLAIM_DECISION,
            channel: 'sms',
            category: 'insurance_claim_decision',
            language: 'fr',
            bodyTemplate: 'Bonjour {{client_name}}, votre déclaration de sinistre {{claim_number}} a été {{decision}}.',
            subject: null,
            variablesAllowlist: ['client_name', 'claim_number', 'decision'],
        );
    }

    /**
     * @return array{agency_id:int, client_id:int, phone_number:string}
     */
    private function seedInsuranceClaimWithDecision(string $status): array
    {
        $agency = $this->createAgency('NTF-CLM-'.Str::random(3));
        $phoneNumber = '+23760'.random_int(1000000, 9999999);
        $clientId = DB::table('clients')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'agency_id' => $agency['id'],
            'client_reference' => 'CLI-'.Str::ulid(),
            'first_name' => 'Claim',
            'last_name' => 'Client',
            'status' => 'active',
            'kyc_status' => 'verified',
            'phone_number' => $phoneNumber,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $productId = DB::table('insurance_products')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'code' => 'INS-CLM-'.Str::ulid(),
            'name' => 'Claim Test Product',
            'product_type' => 'life',
            'currency' => 'XAF',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $subscriptionId = DB::table('insurance_subscriptions')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'client_id' => $clientId,
            'agency_id' => $agency['id'],
            'insurance_product_id' => $productId,
            'subscription_number' => 'SUB-CLM-'.Str::ulid(),
            'starts_on' => now()->subYear()->toDateString(),
            'ends_on' => now()->addYear()->toDateString(),
            'currency' => 'XAF',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('insurance_claims')->insert([
            'public_id' => (string) Str::ulid(),
            'client_id' => $clientId,
            'agency_id' => $agency['id'],
            'insurance_subscription_id' => $subscriptionId,
            'claim_number' => 'CLM-'.Str::ulid(),
            'claim_type' => 'death',
            'incident_date' => now()->subDays(10)->toDateString(),
            'status' => $status,
            'currency' => 'XAF',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [
            'agency_id' => $agency['id'],
            'client_id' => $clientId,
            'phone_number' => $phoneNumber,
        ];
    }

    private function createUserWithRole(string $role, ?int $agencyId = null): User
    {
        $user = User::factory()->createOne([
            'agency_id' => $agencyId,
            'status' => User::STATUS_ACTIVE,
            'phone_verified_at' => now(),
        ]);
        $user->assignRole($role);

        if ($agencyId !== null) {
            DB::table('staff_agency_assignments')->insert([
                'public_id' => (string) Str::ulid(),
                'user_id' => $user->id,
                'agency_id' => $agencyId,
                'role_at_agency' => $role,
                'starts_on' => now()->subDay()->toDateString(),
                'ends_on' => null,
                'is_primary' => true,
                'status' => StaffAgencyAssignment::STATUS_ACTIVE,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $user;
    }

    /**
     * @return array<int, string>
     */
    private function notificationPublicIds(mixed $notifications): array
    {
        if (! is_array($notifications)) {
            return [];
        }

        return collect($notifications)
            ->pluck('public_id')
            ->filter(static fn (mixed $publicId): bool => is_string($publicId))
            ->values()
            ->all();
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
    private function createClient(int $agencyId): array
    {
        $publicId = (string) Str::ulid();
        $id = DB::table('clients')->insertGetId([
            'public_id' => $publicId,
            'agency_id' => $agencyId,
            'client_reference' => 'CLI-'.Str::ulid(),
            'first_name' => 'Notification',
            'last_name' => 'Client',
            'status' => 'active',
            'kyc_status' => 'verified',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return ['id' => $id, 'public_id' => $publicId];
    }
}
