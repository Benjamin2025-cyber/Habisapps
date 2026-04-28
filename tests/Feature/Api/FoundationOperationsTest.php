<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Document;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

final class FoundationOperationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_authorized_user_can_upload_and_archive_document_metadata(): void
    {
        Storage::fake('local');
        $agency = $this->createAgency('DOC');
        $actor = $this->createUserWithRole('platform-admin', $agency['code'], $agency['name']);

        $uploadResponse = $this
            ->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('test-token')->plainTextToken])
            ->postJson('/api/v1/documents', [
                'category' => 'kyc',
                'title' => 'National ID',
                'metadata' => ['side' => 'front'],
                'file' => UploadedFile::fake()->image('national-id.jpg', 640, 480),
            ]);

        $this->assertJsonSuccess($uploadResponse, 201);
        $uploadResponse->assertJsonPath('message', 'Document uploaded successfully');
        $uploadResponse->assertJsonPath('data.document.category', 'kyc');
        $uploadResponse->assertJsonMissingPath('data.document.path');

        $document = Document::query()->firstOrFail();
        $this->assertDatabaseHas('documents', [
            'id' => $document->id,
            'agency_id' => $agency['id'],
        ]);
        Storage::disk('local')->assertExists($document->path);
        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'security',
            'event' => 'document.created',
        ]);

        $archiveResponse = $this
            ->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('test-token')->plainTextToken])
            ->patchJson('/api/v1/documents/'.$document->public_id.'/archive');

        $this->assertJsonSuccess($archiveResponse);
        $archiveResponse->assertJsonPath('data.document.status', Document::STATUS_ARCHIVED);
        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'security',
            'event' => 'document.archived',
        ]);
    }

    public function test_staff_without_document_permission_cannot_upload_document(): void
    {
        $agency = $this->createAgency('STA');
        $actor = $this->createUserWithRole('staff', $agency['code'], $agency['name']);

        $response = $this
            ->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('test-token')->plainTextToken])
            ->postJson('/api/v1/documents', [
                'category' => 'kyc',
                'title' => 'National ID',
                'file' => UploadedFile::fake()->image('national-id.jpg'),
            ]);

        $response->assertForbidden();
        $this->assertDatabaseCount('documents', 0);
    }

    public function test_document_scope_uses_active_assignment_not_cached_user_agency(): void
    {
        Storage::fake('local');
        $assignedAgency = $this->createAgency('ASN');
        $cachedAgency = $this->createAgency('CAC');
        $actor = $this->createUserWithRole('agency-manager', $assignedAgency['code'], $assignedAgency['name']);
        $actor->forceFill([
            'agency_id' => $cachedAgency['id'],
            'agency_code' => $cachedAgency['code'],
            'agency_name' => $cachedAgency['name'],
        ])->save();

        $response = $this
            ->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('test-token')->plainTextToken])
            ->postJson('/api/v1/documents', [
                'category' => 'kyc',
                'title' => 'Assigned Scope Document',
                'file' => UploadedFile::fake()->image('assigned-scope.jpg', 320, 240),
            ]);

        $this->assertJsonSuccess($response, 201);
        $this->assertDatabaseHas('documents', [
            'agency_id' => $assignedAgency['id'],
            'title' => 'Assigned Scope Document',
        ]);
        $this->assertDatabaseMissing('documents', [
            'agency_id' => $cachedAgency['id'],
            'title' => 'Assigned Scope Document',
        ]);
    }

    public function test_authorized_user_can_reserve_reference_numbers_sequentially(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $headers = ['Authorization' => 'Bearer '.$actor->createToken('test-token')->plainTextToken];

        $first = $this->withApiHeaders($headers)->postJson('/api/v1/reference-numbers', [
            'key' => 'loan',
        ]);
        $second = $this->withApiHeaders($headers)->postJson('/api/v1/reference-numbers', [
            'key' => 'loan',
        ]);

        $this->assertJsonSuccess($first, 201);
        $this->assertJsonSuccess($second, 201);
        $first->assertJsonPath('data.reference', 'LOA00000001');
        $second->assertJsonPath('data.reference', 'LOA00000002');
        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'security',
            'event' => 'reference.reserved',
        ]);
    }

    public function test_auditor_can_browse_security_audit_events(): void
    {
        $actor = $this->createUserWithRole('auditor');
        activity('security')->event('test.event')->log('test.event');

        $response = $this
            ->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('test-token')->plainTextToken])
            ->getJson('/api/v1/audit-events?log_name=security');

        $this->assertJsonSuccess($response);
        $response->assertJsonPath('data.events.0.log_name', 'security');
        $response->assertJsonPath('data.events.0.event', 'test.event');
    }

    public function test_staff_without_audit_permission_cannot_browse_audit_events(): void
    {
        $actor = $this->createUserWithRole('staff');

        $response = $this
            ->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('test-token')->plainTextToken])
            ->getJson('/api/v1/audit-events');

        $response->assertForbidden();
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
     * @return array{id:int, code:string, name:string}
     */
    private function createAgency(string $code, ?string $name = null): array
    {
        $name ??= $code.' Agency';
        $id = DB::table('agencies')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'code' => $code,
            'name' => $name,
            'status' => 'active',
        ]);

        return ['id' => $id, 'code' => $code, 'name' => $name];
    }
}
