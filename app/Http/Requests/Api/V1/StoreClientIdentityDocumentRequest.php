<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Models\ClientIdentityDocument;
use App\Support\Crm\IdentityDocumentTypeCatalog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreClientIdentityDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', ClientIdentityDocument::class) === true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'document_type' => ['required', 'string', 'max:64', Rule::in(IdentityDocumentTypeCatalog::keys())],
            'document_number' => ['required', 'string', 'max:128'],
            'issuing_authority' => ['nullable', 'string', 'max:255'],
            'issued_on' => ['nullable', 'date'],
            'expires_on' => ['nullable', 'date', 'after_or_equal:issued_on'],
            'document_public_id' => ['nullable', 'string', 'exists:documents,public_id'],
            'back_document_public_id' => ['nullable', 'string', 'exists:documents,public_id', 'different:document_public_id'],
        ];
    }
}
