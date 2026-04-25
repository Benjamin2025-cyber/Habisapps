<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Support\ApiResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class IdempotencyMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(['api', 'api.version'])->post('/api/v1/test/idempotency', function () {
            Cache::add('idempotency-counter', 0, now()->addMinutes(5));
            $sequence = Cache::increment('idempotency-counter');

            return ApiResponse::created([
                'sequence' => $sequence,
            ], 'Mutation applied');
        });
    }

    public function test_same_key_and_same_payload_replays_the_original_response(): void
    {
        $headers = ['Idempotency-Key' => 'transfer-001'];
        $payload = ['amount' => '1500.00', 'currency' => 'XAF'];

        $firstResponse = $this->withApiHeaders($headers)->postJson('/api/v1/test/idempotency', $payload);
        $secondResponse = $this->withApiHeaders($headers)->postJson('/api/v1/test/idempotency', $payload);

        $this->assertJsonSuccess($firstResponse, 201);
        $this->assertJsonSuccess($secondResponse, 201);
        $firstResponse->assertJsonPath('data.sequence', 1);
        $secondResponse->assertJsonPath('data.sequence', 1);
        $secondResponse->assertHeader('Idempotency-Replayed', 'true');
    }

    public function test_same_key_with_different_payload_is_rejected(): void
    {
        $headers = ['Idempotency-Key' => 'transfer-002'];

        $this->withApiHeaders($headers)->postJson('/api/v1/test/idempotency', [
            'amount' => '1500.00',
            'currency' => 'XAF',
        ])->assertCreated();

        $response = $this->withApiHeaders($headers)->postJson('/api/v1/test/idempotency', [
            'amount' => '2300.00',
            'currency' => 'XAF',
        ]);

        $this->assertJsonError($response, 409, 'Idempotency-Key has already been used for a different request.');
    }

    public function test_locked_request_returns_a_conflict_response(): void
    {
        $key = 'transfer-003';
        $lockKey = 'idempotency:lock:'.hash('sha256', 'POST|api/v1/test/idempotency|'.$key);
        $lock = Cache::lock($lockKey, 30);

        $this->assertTrue($lock->get());

        try {
            $response = $this->withApiHeaders(['Idempotency-Key' => $key])->postJson('/api/v1/test/idempotency', [
                'amount' => '1500.00',
                'currency' => 'XAF',
            ]);

            $this->assertJsonError($response, 409, 'A request with this Idempotency-Key is already being processed.');
        } finally {
            $lock->release();
        }
    }
}
