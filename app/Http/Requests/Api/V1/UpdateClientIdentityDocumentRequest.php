<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Models\ClientIdentityDocument;
use App\Support\Crm\IdentityDocumentTypeCatalog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateClientIdentityDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $identityDocument = $this->route('identityDocument');

        return $identityDocument instanceof ClientIdentityDocument
            && $this->user()?->can('update', $identityDocument) === true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'document_type' => ['sometimes', 'string', 'max:64', Rule::in(IdentityDocumentTypeCatalog::keys())],
            'document_number' => ['sometimes', 'string', 'max:128'],
            'issuing_authority' => ['sometimes', 'nullable', 'string', 'max:255'],
            'issued_on' => ['sometimes', 'nullable', 'date'],
            'expires_on' => ['sometimes', 'nullable', 'date', 'after_or_equal:issued_on'],
            'document_public_id' => ['sometimes', 'nullable', 'string', 'exists:documents,public_id'],
            'back_document_public_id' => ['sometimes', 'nullable', 'string', 'exists:documents,public_id', 'different:document_public_id'],
        ];
    }
}
