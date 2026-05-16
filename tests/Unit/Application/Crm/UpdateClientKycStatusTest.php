<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Crm;

use App\Application\Crm\UpdateClientKycStatus;
use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class UpdateClientKycStatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_updates_client_status_and_creates_review(): void
    {
        $actor = User::factory()->createOne([
            'status' => User::STATUS_ACTIVE,
            'phone_verified_at' => now(),
        ]);
        $client = $this->createClient(Client::KYC_STATUS_DRAFT);

        $updated = app(UpdateClientKycStatus::class)->handle(
            $client,
            $actor,
            Client::KYC_STATUS_PENDING_REVIEW,
            comment: 'Ready for review',
        );

        self::assertSame(Client::KYC_STATUS_PENDING_REVIEW, $updated->kyc_status);
        self::assertNotNull($updated->kyc_submitted_at);
        self::assertSame($actor->id, $updated->kyc_submitted_by_user_id);

        $this->assertDatabaseHas('client_kyc_reviews', [
            'client_id' => $client->id,
            'agency_id' => $client->agency_id,
            'previous_kyc_status' => Client::KYC_STATUS_DRAFT,
            'new_kyc_status' => Client::KYC_STATUS_PENDING_REVIEW,
            'comment' => 'Ready for review',
            'acted_by_user_id' => $actor->id,
        ]);
    }

    public function test_it_rejects_invalid_transitions(): void
    {
        $actor = User::factory()->createOne([
            'status' => User::STATUS_ACTIVE,
            'phone_verified_at' => now(),
        ]);
        $client = $this->createClient(Client::KYC_STATUS_DRAFT);

        $this->expectException(ValidationException::class);

        app(UpdateClientKycStatus::class)->handle($client, $actor, Client::KYC_STATUS_VERIFIED);
    }

    public function test_it_does_not_create_duplicate_review_when_status_already_applied(): void
    {
        $actor = User::factory()->createOne([
            'status' => User::STATUS_ACTIVE,
            'phone_verified_at' => now(),
        ]);
        $client = $this->createClient(Client::KYC_STATUS_PENDING_REVIEW);

        app(UpdateClientKycStatus::class)->handle($client, $actor, Client::KYC_STATUS_PENDING_REVIEW);

        self::assertSame(0, DB::table('client_kyc_reviews')->count());
    }

    private function createClient(string $kycStatus): Client
    {
        $agencyId = DB::table('agencies')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'code' => 'SVC-CRM',
            'name' => 'Service CRM',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return Client::query()->create([
            'public_id' => (string) Str::ulid(),
            'agency_id' => $agencyId,
            'client_reference' => 'SVC-CLI-'.Str::ulid(),
            'first_name' => 'Service',
            'last_name' => 'Client',
            'status' => Client::STATUS_ACTIVE,
            'kyc_status' => $kycStatus,
        ]);
    }
}
