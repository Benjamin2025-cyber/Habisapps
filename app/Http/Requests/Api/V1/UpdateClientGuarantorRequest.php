<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Models\Client;
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
        $safeText = ['string', 'not_regex:/[<>]/'];

        return [
            'guarantor_client_public_id' => ['sometimes', 'nullable', 'string', 'exists:clients,public_id'],
            'guarantor_full_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'guarantor_civility' => ['sometimes', 'nullable', 'string', Rule::in(Client::CIVILITIES)],
            'guarantor_first_name' => ['sometimes', 'nullable', ...$safeText, 'max:128'],
            'guarantor_last_name' => ['sometimes', 'nullable', ...$safeText, 'max:128'],
            'guarantor_middle_name' => ['sometimes', 'nullable', ...$safeText, 'max:128'],
            'guarantor_date_of_birth' => ['sometimes', 'nullable', 'date', 'before_or_equal:today'],
            'guarantor_place_of_birth' => ['sometimes', 'nullable', ...$safeText, 'max:255'],
            'guarantor_identity_document_number' => ['sometimes', 'nullable', 'string', 'max:128'],
            'guarantor_identity_issued_on' => ['sometimes', 'nullable', 'date', 'before_or_equal:today'],
            'guarantor_identity_issued_at' => ['sometimes', 'nullable', ...$safeText, 'max:255'],
            'guarantor_father_name' => ['sometimes', 'nullable', ...$safeText, 'max:128'],
            'guarantor_mother_name' => ['sometimes', 'nullable', ...$safeText, 'max:128'],
            'guarantor_profession' => ['sometimes', 'nullable', ...$safeText, 'max:128'],
            'guarantor_address_line_1' => ['sometimes', 'nullable', ...$safeText, 'max:255'],
            'guarantor_address_line_2' => ['sometimes', 'nullable', ...$safeText, 'max:255'],
            'guarantor_business_address_line_1' => ['sometimes', 'nullable', ...$safeText, 'max:255'],
            'guarantor_business_address_line_2' => ['sometimes', 'nullable', ...$safeText, 'max:255'],
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
