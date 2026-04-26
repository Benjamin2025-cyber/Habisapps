<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Document;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
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
        $actor = $this->createUserWithRole('platform-admin');

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
        $actor = $this->createUserWithRole('staff');

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
