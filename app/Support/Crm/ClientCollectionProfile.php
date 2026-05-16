<?php

declare(strict_types=1);

namespace App\Support\Crm;

use App\Models\Client;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

final class ClientCollectionProfile
{
    /**
     * @return array{
     *     client_id:int,
     *     agency_id:int,
     *     collection_agent_id:int|null,
     *     collection_type:string|null,
     *     collection_frequency:string|null,
     *     collection_target_amount:string|null
     * }
     */
    public function forClient(Client $client): array
    {
        return [
            'client_id' => $client->id,
            'agency_id' => $client->agency_id,
            'collection_agent_id' => $client->collection_agent_id,
            'collection_type' => $client->collection_type,
            'collection_frequency' => $client->collection_frequency,
            'collection_target_amount' => $this->collectionTargetAmount($client->collection_target_amount),
        ];
    }

    private function collectionTargetAmount(mixed $amount): ?string
    {
        if ($amount === null || $amount === '') {
            return null;
        }

        if (! is_int($amount) && ! is_float($amount) && ! is_string($amount)) {
            return null;
        }

        return BigDecimal::of((string) $amount)
            ->toScale(2, RoundingMode::UNNECESSARY)
            ->__toString();
    }
}
