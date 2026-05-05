<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Accounting;

use App\Application\Accounting\ReleaseAccountHold;
use App\Models\AccountHold;
use App\Models\Client;
use App\Models\CustomerAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class ReleaseAccountHoldTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_releases_an_active_hold_with_actor_and_reference(): void
    {
        $actor = User::factory()->createOne([
            'status' => User::STATUS_ACTIVE,
            'phone_verified_at' => now(),
        ]);
        $hold = $this->createAccountHold(AccountHold::STATUS_ACTIVE);

        $released = app(ReleaseAccountHold::class)->handle($hold, $actor, 'REL-SVC-1');

        self::assertSame(AccountHold::STATUS_RELEASED, $released->status);
        self::assertSame($actor->id, $released->released_by_user_id);
        self::assertSame('REL-SVC-1', $released->reference);
        self::assertNotNull($released->released_at);
    }

    public function test_it_rejects_non_active_holds(): void
    {
        $actor = User::factory()->createOne([
            'status' => User::STATUS_ACTIVE,
            'phone_verified_at' => now(),
        ]);
        $hold = $this->createAccountHold(AccountHold::STATUS_RELEASED);

        $this->expectException(ValidationException::class);

        app(ReleaseAccountHold::class)->handle($hold, $actor, 'REL-SVC-2');
    }

    private function createAccountHold(string $status): AccountHold
    {
        $agencyId = DB::table('agencies')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'code' => 'SVC-ACCT',
            'name' => 'Service Accounting',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $client = Client::query()->create([
            'public_id' => (string) Str::ulid(),
            'agency_id' => $agencyId,
            'client_reference' => 'SVC-CLI-'.Str::ulid(),
            'first_name' => 'Service',
            'last_name' => 'Client',
            'status' => Client::STATUS_ACTIVE,
            'kyc_status' => Client::KYC_STATUS_VERIFIED,
        ]);

        $account = CustomerAccount::query()->create([
            'public_id' => (string) Str::ulid(),
            'client_id' => $client->id,
            'agency_id' => $agencyId,
            'account_number' => 'SVC-CA-'.Str::upper(Str::random(8)),
            'opened_on' => now()->toDateString(),
            'status' => CustomerAccount::STATUS_ACTIVE,
        ]);

        return AccountHold::query()->create([
            'public_id' => (string) Str::ulid(),
            'customer_account_id' => $account->id,
            'amount_minor' => 1000,
            'currency' => 'XAF',
            'reason_type' => 'test',
            'status' => $status,
            'placed_at' => now(),
        ]);
    }
}
