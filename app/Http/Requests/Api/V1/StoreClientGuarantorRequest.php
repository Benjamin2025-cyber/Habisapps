<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Models\Client;
use App\Models\ClientGuarantor;
use App\Support\Crm\IdentityDocumentTypeCatalog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreClientGuarantorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', ClientGuarantor::class) === true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $safeText = ['string', 'not_regex:/[<>]/'];

        return [
            'guarantor_client_public_id' => ['nullable', 'string', 'exists:clients,public_id'],
            // A standalone guarantor needs a display name, but it can be derived
            // from structured names, so a last name (or linked client) suffices.
            'guarantor_full_name' => ['nullable', 'string', 'max:255', 'required_without_all:guarantor_client_public_id,guarantor_last_name'],
            'guarantor_civility' => ['nullable', 'string', Rule::in(Client::CIVILITIES)],
            'guarantor_first_name' => ['nullable', ...$safeText, 'max:128'],
            'guarantor_last_name' => ['nullable', ...$safeText, 'max:128'],
            'guarantor_middle_name' => ['nullable', ...$safeText, 'max:128'],
            'guarantor_date_of_birth' => ['nullable', 'date', 'before_or_equal:today'],
            'guarantor_place_of_birth' => ['nullable', ...$safeText, 'max:255'],
            'guarantor_identity_document_number' => ['nullable', 'string', 'max:128'],
            'guarantor_identity_issued_on' => ['nullable', 'date', 'before_or_equal:today'],
            'guarantor_identity_issued_at' => ['nullable', ...$safeText, 'max:255'],
            'guarantor_father_name' => ['nullable', ...$safeText, 'max:128'],
            'guarantor_mother_name' => ['nullable', ...$safeText, 'max:128'],
            'guarantor_profession' => ['nullable', ...$safeText, 'max:128'],
            'guarantor_address_line_1' => ['nullable', ...$safeText, 'max:255'],
            'guarantor_address_line_2' => ['nullable', ...$safeText, 'max:255'],
            'guarantor_business_address_line_1' => ['nullable', ...$safeText, 'max:255'],
            'guarantor_business_address_line_2' => ['nullable', ...$safeText, 'max:255'],
            'guarantor_phone_number' => ['nullable', 'string', 'max:32'],
            'relationship_type' => ['nullable', 'string', 'max:64'],
            'document_type' => ['nullable', 'string', 'max:64', Rule::in(IdentityDocumentTypeCatalog::keys())],
            'starts_on' => ['nullable', 'date'],
            'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
            'document_public_id' => ['nullable', 'string', 'exists:documents,public_id'],
            'back_document_public_id' => ['nullable', 'string', 'exists:documents,public_id', 'different:document_public_id'],
        ];
    }
}
