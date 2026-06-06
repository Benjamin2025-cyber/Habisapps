<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Models\Client;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreClientRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Client::class) === true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $safeText = ['string', 'not_regex:/[<>]/'];

        return [
            'agency_public_id' => ['sometimes', 'string', 'exists:agencies,public_id'],
            'profile_photo_document_public_id' => ['nullable', 'string', 'exists:documents,public_id'],
            'prospector_public_id' => ['nullable', 'string', 'exists:users,public_id'],
            'collection_agent_public_id' => ['nullable', 'string', 'exists:users,public_id'],
            'sector_public_id' => ['nullable', 'string', 'exists:sectors,public_id'],
            'sub_sector_public_id' => ['nullable', 'string', 'exists:sub_sectors,public_id'],
            'civility' => ['nullable', 'string', Rule::in(Client::CIVILITIES)],
            'first_name' => ['required', ...$safeText, 'max:128'],
            'last_name' => ['required', ...$safeText, 'max:128'],
            'middle_name' => ['nullable', ...$safeText, 'max:128'],
            'father_name' => ['nullable', ...$safeText, 'max:128'],
            'mother_name' => ['nullable', ...$safeText, 'max:128'],
            'date_of_birth' => ['nullable', 'date', 'before_or_equal:today'],
            'place_of_birth' => ['nullable', ...$safeText, 'max:255'],
            'gender' => ['nullable', ...$safeText, 'max:32'],
            'phone_number' => ['nullable', 'string', 'max:32'],
            'home_phone_number' => ['nullable', 'string', 'max:32'],
            'email' => ['nullable', 'email', 'max:255'],
            'address_line_1' => ['nullable', ...$safeText, 'max:255'],
            'address_line_2' => ['nullable', ...$safeText, 'max:255'],
            'city' => ['nullable', ...$safeText, 'max:128'],
            'region' => ['nullable', ...$safeText, 'max:128'],
            'occupation' => ['nullable', ...$safeText, 'max:128'],
            'employer_name' => ['nullable', ...$safeText, 'max:255'],
            'business_started_on' => ['nullable', 'date', 'before_or_equal:today'],
            'business_activity_started_on' => ['nullable', 'date', 'before_or_equal:today'],
            'business_address_line_1' => ['nullable', ...$safeText, 'max:255'],
            'business_address_line_2' => ['nullable', ...$safeText, 'max:255'],
            'business_city' => ['nullable', ...$safeText, 'max:128'],
            'business_region' => ['nullable', ...$safeText, 'max:128'],
            'collection_type' => ['nullable', ...$safeText, 'max:64'],
            'collection_frequency' => ['nullable', Rule::in(['daily', 'weekly', 'monthly', 'custom'])],
            'collection_target_amount' => ['nullable', 'numeric', 'min:0'],
            'status' => ['sometimes', Rule::in([
                Client::STATUS_ACTIVE,
                Client::STATUS_INACTIVE,
                Client::STATUS_SUSPENDED,
                Client::STATUS_ARCHIVED,
            ])],
            'onboarded_on' => ['nullable', 'date'],
        ];
    }
}
