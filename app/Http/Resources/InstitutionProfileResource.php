<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\InstitutionProfile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin InstitutionProfile
 */
final class InstitutionProfileResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var InstitutionProfile $profile */
        $profile = $this->resource;

        return [
            'public_id' => $profile->public_id,
            'legal_name' => $profile->legal_name,
            'trade_name' => $profile->trade_name,
            'legal_form' => $profile->legal_form,
            'emf_category' => $profile->emf_category,
            'supervisory_authority' => $profile->supervisory_authority,
            'approval_number' => $profile->approval_number,
            'approval_date' => $profile->approval_date?->toDateString(),
            'registration_number' => $profile->registration_number,
            'tax_identification_number' => $profile->tax_identification_number,
            'address_line_1' => $profile->address_line_1,
            'address_line_2' => $profile->address_line_2,
            'po_box' => $profile->po_box,
            'city' => $profile->city,
            'region' => $profile->region,
            'country' => $profile->country,
            'phone_number' => $profile->phone_number,
            'fax_number' => $profile->fax_number,
            'email' => $profile->email,
            'website' => $profile->website,
            'declared_reporting_currency' => $profile->declared_reporting_currency,
            'fiscal_year_start_month' => $profile->fiscal_year_start_month,
            'created_at' => $profile->created_at?->toAtomString(),
            'updated_at' => $profile->updated_at?->toAtomString(),
        ];
    }
}
