<?php

declare(strict_types=1);

namespace App\Application\Crm;

use App\Models\Client;
use App\Support\References\ReferenceNumberGenerator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class CreateClient
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function execute(int $agencyId, array $attributes, ReferenceNumberGenerator $referenceNumberGenerator): Client
    {
        return DB::transaction(function () use ($agencyId, $attributes, $referenceNumberGenerator): Client {
            $clientReference = $referenceNumberGenerator->reserve('client');

            return Client::query()->create([
                'public_id' => (string) Str::ulid(),
                'agency_id' => $agencyId,
                'profile_photo_document_id' => $attributes['profile_photo_document_id'] ?? null,
                'prospector_id' => $attributes['prospector_id'] ?? null,
                'collection_agent_id' => $attributes['collection_agent_id'] ?? null,
                'sector_id' => $attributes['sector_id'] ?? null,
                'sub_sector_id' => $attributes['sub_sector_id'] ?? null,
                'client_reference' => $clientReference,
                'first_name' => $attributes['first_name'],
                'last_name' => $attributes['last_name'],
                'middle_name' => $attributes['middle_name'] ?? null,
                'father_name' => $attributes['father_name'] ?? null,
                'mother_name' => $attributes['mother_name'] ?? null,
                'date_of_birth' => $attributes['date_of_birth'] ?? null,
                'place_of_birth' => $attributes['place_of_birth'] ?? null,
                'gender' => $attributes['gender'] ?? null,
                'phone_number' => $attributes['phone_number'] ?? null,
                'home_phone_number' => $attributes['home_phone_number'] ?? null,
                'email' => $attributes['email'] ?? null,
                'address_line_1' => $attributes['address_line_1'] ?? null,
                'address_line_2' => $attributes['address_line_2'] ?? null,
                'city' => $attributes['city'] ?? null,
                'region' => $attributes['region'] ?? null,
                'occupation' => $attributes['occupation'] ?? null,
                'employer_name' => $attributes['employer_name'] ?? null,
                'business_started_on' => $attributes['business_started_on'] ?? null,
                'business_activity_started_on' => $attributes['business_activity_started_on'] ?? null,
                'business_address_line_1' => $attributes['business_address_line_1'] ?? null,
                'business_address_line_2' => $attributes['business_address_line_2'] ?? null,
                'business_city' => $attributes['business_city'] ?? null,
                'business_region' => $attributes['business_region'] ?? null,
                'collection_type' => $attributes['collection_type'] ?? null,
                'collection_frequency' => $attributes['collection_frequency'] ?? null,
                'collection_target_amount' => $attributes['collection_target_amount'] ?? null,
                'status' => $attributes['status'] ?? Client::STATUS_ACTIVE,
                'kyc_status' => Client::KYC_STATUS_DRAFT,
                'onboarded_on' => $attributes['onboarded_on'] ?? null,
            ]);
        });
    }
}
