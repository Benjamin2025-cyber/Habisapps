<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

final class StoreClientIdentityDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('crm.identity_documents.create') === true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'document_type' => ['required', 'string', 'max:64'],
            'document_number' => ['required', 'string', 'max:128'],
            'issuing_authority' => ['nullable', 'string', 'max:255'],
            'issued_on' => ['nullable', 'date'],
            'expires_on' => ['nullable', 'date', 'after_or_equal:issued_on'],
            'document_public_id' => ['nullable', 'string', 'exists:documents,public_id'],
        ];
    }
}
