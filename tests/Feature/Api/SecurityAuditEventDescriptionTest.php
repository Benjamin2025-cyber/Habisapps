<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\BatchProcedure;
use App\Models\User;
use App\Support\Security\SecurityEventCatalog;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

final class SecurityAuditEventDescriptionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_audit_events_expose_machine_event_code_separately_from_readable_description(): void
    {
        $actor = $this->createUserWithRole('platform-admin');

        $createResponse = $this->actingWith($actor)->postJson('/api/v1/batch-procedures', [
            'code' => 'audit_demo_procedure',
            'name' => 'Audit Demo Procedure',
            'schedule_type' => 'manual',
        ]);
        $this->assertJsonSuccess($createResponse, 201);

        $response = $this->actingWith($actor)->getJson('/api/v1/audit-events?event=batch.procedure.created');
        $this->assertJsonSuccess($response);

        $event = $this->firstEventWhere($response, 'batch.procedure.created');

        self::assertNotNull($event, 'Expected a batch.procedure.created audit event.');
        self::assertSame('batch.procedure.created', $event['event']);
        self::assertSame('Batch procedure created', $event['description']);
        self::assertNotSame($event['event'], $event['description']);
    }

    public function test_audit_event_exposes_list_valued_changed_fields_property(): void
    {
        $actor = $this->createUserWithRole('platform-admin');

        $procedure = BatchProcedure::query()->create([
            'public_id' => (string) Str::ulid(),
            'code' => 'audit_changed_fields_demo',
            'name' => 'Audit Changed Fields Demo',
            'schedule_type' => 'manual',
            'status' => BatchProcedure::STATUS_ACTIVE,
        ]);

        $updateResponse = $this->actingWith($actor)->patchJson('/api/v1/batch-procedures/'.$procedure->public_id, [
            'name' => 'Renamed Procedure',
            'description' => 'Updated description',
        ]);
        $this->assertJsonSuccess($updateResponse);

        $response = $this->actingWith($actor)->getJson('/api/v1/audit-events?event=batch.procedure.updated');
        $this->assertJsonSuccess($response);

        $event = $this->firstEventWhere($response, 'batch.procedure.updated');

        self::assertNotNull($event);
        self::assertIsArray($event['properties']);
        self::assertArrayHasKey('changed_fields', $event['properties']);
        self::assertEqualsCanonicalizing(['name', 'description'], $event['properties']['changed_fields']);
    }

    public function test_audit_model_crud_events_keep_their_readable_verbs(): void
    {
        // Creating a model that uses HasAuditLog writes a model-scoped activity
        // row whose description is the standard Spatie verb. The security-audit
        // description change must not affect it.
        BatchProcedure::query()->create([
            'public_id' => (string) Str::ulid(),
            'code' => 'regression_model_crud',
            'name' => 'Regression Model CRUD',
            'schedule_type' => 'manual',
            'status' => BatchProcedure::STATUS_ACTIVE,
        ]);

        $modelActivity = Activity::query()
            ->where('log_name', 'batchprocedure')
            ->latest('id')
            ->first();

        self::assertInstanceOf(Activity::class, $modelActivity);
        self::assertSame('created', $modelActivity->event);
        self::assertSame('created', $modelActivity->description);
    }

    public function test_audit_event_description_for_unmapped_security_event_is_deterministic(): void
    {
        $actor = $this->createUserWithRole('platform-admin');

        activity('security')->event('some.unmapped.security_event')->log(
            SecurityEventCatalog::describe('some.unmapped.security_event')
        );

        $response = $this->actingWith($actor)->getJson('/api/v1/audit-events?event=some.unmapped.security_event');
        $this->assertJsonSuccess($response);

        $event = $this->firstEventWhere($response, 'some.unmapped.security_event');

        self::assertNotNull($event);
        self::assertSame('Some Unmapped Security Event', $event['description']);
    }

    /**
     * @return array<string, mixed>|null
     */
    /**
     * @return array<string, mixed>|null
     */
    private function firstEventWhere(TestResponse $response, string $eventCode): ?array
    {
        $events = $response->json('data.events');
        self::assertIsArray($events);

        foreach ($events as $event) {
            self::assertIsArray($event);
            if (($event['event'] ?? null) === $eventCode) {
                /** @var array<string, mixed> $event */
                return $event;
            }
        }

        return null;
    }

    private function actingWith(User $actor): self
    {
        return $this->withApiHeaders([
            'Authorization' => 'Bearer '.$actor->createToken('audit-test')->plainTextToken,
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
}
