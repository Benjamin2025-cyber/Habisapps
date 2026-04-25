<?php

declare(strict_types=1);

namespace Tests\Traits;

use App\Models\User;
use Laravel\Sanctum\Sanctum;

trait HasApiHeaders
{
    /** @var array<array-key, string> */
    private array $apiDefaultHeaders = [
        'Accept' => 'application/json',
        'X-API-Version' => '1',
    ];

    /** @param array<array-key, string> $headers */
    public function withApiHeaders(array $headers = []): static
    {
        $allHeaders = array_merge($this->apiDefaultHeaders, $headers);

        return $this->withHeaders($allHeaders);
    }

    /** @param array<string> $abilities */
    public function actingAsSanctum(?User $user = null, array $abilities = ['*']): static
    {
        $authenticatedUser = $user;

        if ($authenticatedUser === null) {
            $authenticatedUser = User::factory()->createOne();
        }

        Sanctum::actingAs($authenticatedUser, $abilities);

        return $this;
    }
}
