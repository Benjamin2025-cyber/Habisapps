<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Client;
use App\Models\User;
use DateTimeInterface;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Client
 */
final class ClientResource extends JsonResource
{
/**
 * @return array<string, mixed>
 */
public function toArray(Request $request): array
{
    /** @var Client $client */
    $client = $this->resource;

    $showPii = $this->canViewPii($request);

        return [
            'public_id' => $client->public_id,
            'agency_public_id' => $client->relationLoaded('agency') ? $client->agency?->public_id : null,
            'prospector_public_id' => $client->relationLoaded('prospector') ? $client->prospector?->public_id : null,
            'collection_agent_public_id' => $client->relationLoaded('collectionAgent') ? $client->collectionAgent?->public_id : null,
            'client_reference' => $client->client_reference,
            'first_name' => $showPii ? $client->first_name : $this->maskName($client->first_name),
            'last_name' => $showPii ? $client->last_name : $this->maskName($client->last_name),
            'middle_name' => $showPii ? $client->middle_name : $this->maskName($client->middle_name),
            'date_of_birth' => $showPii ? $this->formatDate($client->date_of_birth) : null,
            'place_of_birth' => $showPii ? $client->place_of_birth : null,
            'gender' => $showPii ? $client->gender : null,
            'phone_number' => $showPii ? $client->phone_number : $this->maskPhone($client->phone_number),
            'email' => $showPii ? $client->email : $this->maskEmail($client->email),
            'address_line_1' => $showPii ? $client->address_line_1 : null,
            'address_line_2' => $showPii ? $client->address_line_2 : null,
            'city' => $showPii ? $client->city : null,
            'region' => $showPii ? $client->region : null,
            'occupation' => $showPii ? $client->occupation : null,
            'employer_name' => $showPii ? $client->employer_name : null,
            'collection_type' => $client->collection_type,
            'collection_frequency' => $client->collection_frequency,
            'collection_target_amount' => $client->collection_target_amount,
            'status' => $client->status,
            'kyc_status' => $client->kyc_status,
            'onboarded_on' => $this->formatDate($client->onboarded_on),
            'kyc_submitted_at' => $this->formatDate($client->kyc_submitted_at),
            'kyc_verified_at' => $this->formatDate($client->kyc_verified_at),
            'kyc_rejected_at' => $this->formatDate($client->kyc_rejected_at),
            'kyc_rejection_reason' => $client->kyc_rejection_reason,
            'kyc_suspended_at' => $this->formatDate($client->kyc_suspended_at),
            'kyc_archived_at' => $this->formatDate($client->kyc_archived_at),
            'created_at' => $this->formatDate($client->created_at),
            'updated_at' => $this->formatDate($client->updated_at),
        ];
    }

    private function maskName(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return $value;
        }

        return mb_substr($value, 0, 1).str_repeat('*', max(0, mb_strlen($value) - 1));
    }

    private function canViewPii(Request $request): bool
    {
        $user = $request->user();

        return $user instanceof User && $user->hasPermissionTo('crm.pii.view');
    }

    private function maskPhone(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return $value;
        }

        $length = strlen($value);
        if ($length <= 4) {
            return str_repeat('*', $length);
        }

        return str_repeat('*', $length - 4).substr($value, -4);
    }

    private function maskEmail(?string $value): ?string
    {
        if ($value === null || $value === '' || ! str_contains($value, '@')) {
            return $value;
        }

        [$local, $domain] = explode('@', $value, 2);
        if ($local === '') {
            return '*@'.$domain;
        }

        return substr($local, 0, 1).str_repeat('*', max(0, strlen($local) - 1)).'@'.$domain;
    }

    private function formatDate(mixed $value): ?string
    {
        if (! $value instanceof DateTimeInterface) {
            return null;
        }

        return $value->format(DateTimeInterface::ATOM);
    }
}
