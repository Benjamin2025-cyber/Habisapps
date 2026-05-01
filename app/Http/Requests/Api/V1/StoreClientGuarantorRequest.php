<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

final class StoreClientGuarantorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('crm.guarantors.create') === true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'guarantor_client_public_id' => ['nullable', 'string', 'exists:clients,public_id'],
            'guarantor_full_name' => ['nullable', 'string', 'max:255', 'required_without:guarantor_client_public_id'],
            'guarantor_phone_number' => ['nullable', 'string', 'max:32'],
            'relationship_type' => ['nullable', 'string', 'max:64'],
            'starts_on' => ['nullable', 'date'],
            'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
            'document_public_id' => ['nullable', 'string', 'exists:documents,public_id'],
        ];
    }
}
