<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Models\Client;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateClientRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('crm.clients.update') === true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $safeText = ['string', 'not_regex:/[<>]/'];

        return [
            'prospector_public_id' => ['sometimes', 'nullable', 'string', 'exists:users,public_id'],
            'collection_agent_public_id' => ['sometimes', 'nullable', 'string', 'exists:users,public_id'],
            'first_name' => ['sometimes', ...$safeText, 'max:128'],
            'last_name' => ['sometimes', ...$safeText, 'max:128'],
            'middle_name' => ['sometimes', 'nullable', ...$safeText, 'max:128'],
            'date_of_birth' => ['sometimes', 'nullable', 'date', 'before_or_equal:today'],
            'place_of_birth' => ['sometimes', 'nullable', ...$safeText, 'max:255'],
            'gender' => ['sometimes', 'nullable', ...$safeText, 'max:32'],
            'phone_number' => ['sometimes', 'nullable', 'string', 'max:32'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'address_line_1' => ['sometimes', 'nullable', ...$safeText, 'max:255'],
            'address_line_2' => ['sometimes', 'nullable', ...$safeText, 'max:255'],
            'city' => ['sometimes', 'nullable', ...$safeText, 'max:128'],
            'region' => ['sometimes', 'nullable', ...$safeText, 'max:128'],
            'occupation' => ['sometimes', 'nullable', ...$safeText, 'max:128'],
            'employer_name' => ['sometimes', 'nullable', ...$safeText, 'max:255'],
            'collection_type' => ['sometimes', 'nullable', ...$safeText, 'max:64'],
            'collection_frequency' => ['sometimes', 'nullable', Rule::in(['daily', 'weekly', 'monthly', 'custom'])],
            'collection_target_amount' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'status' => ['sometimes', Rule::in([
                Client::STATUS_ACTIVE,
                Client::STATUS_INACTIVE,
                Client::STATUS_SUSPENDED,
                Client::STATUS_ARCHIVED,
            ])],
            'onboarded_on' => ['sometimes', 'nullable', 'date'],
        ];
    }
}
