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
        return $this->user()?->can('crm.clients.create') === true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'agency_public_id' => ['sometimes', 'string', 'exists:agencies,public_id'],
            'prospector_public_id' => ['nullable', 'string', 'exists:users,public_id'],
            'collection_agent_public_id' => ['nullable', 'string', 'exists:users,public_id'],
            'first_name' => ['required', 'string', 'max:128'],
            'last_name' => ['required', 'string', 'max:128'],
            'middle_name' => ['nullable', 'string', 'max:128'],
            'date_of_birth' => ['nullable', 'date', 'before_or_equal:today'],
            'place_of_birth' => ['nullable', 'string', 'max:255'],
            'gender' => ['nullable', 'string', 'max:32'],
            'phone_number' => ['nullable', 'string', 'max:32'],
            'email' => ['nullable', 'email', 'max:255'],
            'address_line_1' => ['nullable', 'string', 'max:255'],
            'address_line_2' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:128'],
            'region' => ['nullable', 'string', 'max:128'],
            'occupation' => ['nullable', 'string', 'max:128'],
            'employer_name' => ['nullable', 'string', 'max:255'],
            'collection_type' => ['nullable', 'string', 'max:64'],
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
