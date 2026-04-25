<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Testing\TestResponse;
use Tests\Traits\AssertsFinancialData;
use Tests\Traits\HasApiHeaders;

abstract class TestCase extends BaseTestCase
{
    use AssertsFinancialData;
    use HasApiHeaders;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withApiHeaders();
    }

    protected function assertJsonSuccess(TestResponse $response, int $status = 200): TestResponse
    {
        $response->assertStatus($status);
        $response->assertJsonPath('success', true);

        return $response;
    }

    protected function assertJsonError(TestResponse $response, int $status = 400, ?string $messageKey = null): TestResponse
    {
        $response->assertStatus($status);
        $response->assertJsonPath('success', false);

        if ($messageKey !== null) {
            $response->assertJsonPath('message', $messageKey);
        }

        return $response;
    }
}
