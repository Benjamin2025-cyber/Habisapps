<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Security;

use App\Models\User;
use App\Support\Security\SecurityAudit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

final class SecurityAuditDescriptionTest extends TestCase
{
    use RefreshDatabase;

    public function test_audit_record_stores_machine_event_and_distinct_readable_description(): void
    {
        $actor = $this->actor();

        app(SecurityAudit::class)->record('crm.client.pii_viewed', actor: $actor, subject: $actor);

        $activity = $this->latestSecurityActivity();

        self::assertSame('crm.client.pii_viewed', $activity->event);
        self::assertSame('Client PII record viewed', $activity->description);
        self::assertNotSame($activity->event, $activity->description);
    }

    public function test_audit_record_for_pii_list_keeps_readable_description_and_hashed_request_properties(): void
    {
        $actor = $this->actor();
        $request = Request::create('/api/v1/clients', 'GET', server: [
            'REMOTE_ADDR' => '203.0.113.42',
            'HTTP_USER_AGENT' => 'AuditAgent/9.9',
        ]);

        app(SecurityAudit::class)->record(
            'crm.client.pii_list_viewed',
            actor: $actor,
            properties: ['result_count' => 7],
            request: $request,
        );

        $activity = $this->latestSecurityActivity();
        self::assertInstanceOf(Collection::class, $activity->properties);
        $properties = $activity->properties->all();

        self::assertSame('crm.client.pii_list_viewed', $activity->event);
        self::assertSame('Client PII list viewed', $activity->description);

        // Request context is stored hashed, never as the raw IP/user agent.
        self::assertSame(hash('sha256', '203.0.113.42'), $properties['ip_hash']);
        self::assertSame(hash('sha256', 'AuditAgent/9.9'), $properties['user_agent_hash']);
        self::assertArrayNotHasKey('ip', $properties);
        self::assertStringNotContainsString('203.0.113.42', (string) json_encode($properties));
        self::assertStringNotContainsString('AuditAgent/9.9', (string) json_encode($properties));
    }

    public function test_unmapped_event_falls_back_to_deterministic_humanized_description(): void
    {
        $actor = $this->actor();

        app(SecurityAudit::class)->record('some.brand.new_unmapped_event', actor: $actor);
        $first = $this->latestSecurityActivity();

        self::assertSame('some.brand.new_unmapped_event', $first->event);
        self::assertSame('Some Brand New Unmapped Event', $first->description);

        // Deterministic: the same code always yields the same description.
        app(SecurityAudit::class)->record('some.brand.new_unmapped_event', actor: $actor);
        $second = $this->latestSecurityActivity();
        self::assertSame($first->description, $second->description);
    }

    public function test_audit_description_does_not_leak_sensitive_properties(): void
    {
        $actor = $this->actor();
        $subject = $this->actor();
        $request = Request::create('/api/v1/clients', 'GET', server: [
            'REMOTE_ADDR' => '198.51.100.7',
            'HTTP_USER_AGENT' => 'SecretBrowser/1.0',
        ]);

        app(SecurityAudit::class)->record(
            'crm.client.pii_viewed',
            actor: $actor,
            subject: $subject,
            properties: [
                'phone_number' => '+237600123456',
                'otp' => '982134',
                'access_token' => 'tok_supersecret',
                'client_internal_id' => 424242,
            ],
            request: $request,
        );

        $activity = $this->latestSecurityActivity();
        $description = $activity->description;

        self::assertSame('Client PII record viewed', $description);

        foreach (['+237600123456', '982134', 'tok_supersecret', '424242', '198.51.100.7', 'SecretBrowser/1.0'] as $sensitive) {
            self::assertStringNotContainsString($sensitive, $description);
        }
    }

    public function test_explicit_description_overrides_catalog_label(): void
    {
        $actor = $this->actor();

        app(SecurityAudit::class)->record('batch.procedure.created', actor: $actor, description: 'Custom contextual description');

        $activity = $this->latestSecurityActivity();

        self::assertSame('batch.procedure.created', $activity->event);
        self::assertSame('Custom contextual description', $activity->description);
    }

    public function test_explicit_description_rejects_sensitive_runtime_values(): void
    {
        $actor = $this->actor();

        foreach ([
            'Viewed client +237600123456',
            'Used OTP 982134',
            'Issued token tok_supersecret',
            'Request from 198.51.100.7',
            'Internal record 424242',
        ] as $unsafeDescription) {
            try {
                app(SecurityAudit::class)->record('batch.procedure.created', actor: $actor, description: $unsafeDescription);
                self::fail('Unsafe audit description was accepted: '.$unsafeDescription);
            } catch (InvalidArgumentException $exception) {
                self::assertSame('Audit event descriptions must not contain sensitive values.', $exception->getMessage());
            }
        }
    }

    private function actor(): User
    {
        return User::factory()->createOne([
            'status' => User::STATUS_ACTIVE,
            'phone_verified_at' => now(),
        ]);
    }

    private function latestSecurityActivity(): Activity
    {
        $activity = Activity::query()->where('log_name', 'security')->latest('id')->first();
        self::assertInstanceOf(Activity::class, $activity);

        return $activity;
    }
}
