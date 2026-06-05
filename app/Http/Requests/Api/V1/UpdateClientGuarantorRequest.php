<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Models\ClientGuarantor;
use App\Support\Crm\IdentityDocumentTypeCatalog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateClientGuarantorRequest extends FormRequest
{
    public function authorize(): bool
    {
        $guarantor = $this->route('guarantor');

        return $guarantor instanceof ClientGuarantor
            && $this->user()?->can('update', $guarantor) === true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'guarantor_client_public_id' => ['sometimes', 'nullable', 'string', 'exists:clients,public_id'],
            'guarantor_full_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'guarantor_phone_number' => ['sometimes', 'nullable', 'string', 'max:32'],
            'relationship_type' => ['sometimes', 'nullable', 'string', 'max:64'],
            'document_type' => ['sometimes', 'nullable', 'string', 'max:64', Rule::in(IdentityDocumentTypeCatalog::keys())],
            'starts_on' => ['sometimes', 'nullable', 'date'],
            'ends_on' => ['sometimes', 'nullable', 'date', 'after_or_equal:starts_on'],
            'document_public_id' => ['sometimes', 'nullable', 'string', 'exists:documents,public_id'],
            'back_document_public_id' => ['sometimes', 'nullable', 'string', 'exists:documents,public_id', 'different:document_public_id'],
        ];
    }
}
