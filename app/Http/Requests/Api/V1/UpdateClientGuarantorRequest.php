<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateClientGuarantorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('crm.guarantors.update') === true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'guarantor_client_public_id' => ['sometimes', 'nullable', 'string', 'exists:clients,public_id'],
            'guarantor_full_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'guarantor_phone_number' => ['sometimes', 'nullable', 'string', 'max:32'],
            'relationship_type' => ['sometimes', 'nullable', 'string', 'max:64'],
            'starts_on' => ['sometimes', 'nullable', 'date'],
            'ends_on' => ['sometimes', 'nullable', 'date', 'after_or_equal:starts_on'],
            'document_public_id' => ['sometimes', 'nullable', 'string', 'exists:documents,public_id'],
        ];
    }
}
