<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateClientProxyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('crm.proxies.update') === true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'proxy_full_name' => ['sometimes', 'string', 'max:255'],
            'proxy_phone_number' => ['sometimes', 'nullable', 'string', 'max:32'],
            'proxy_email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'proxy_id_document_type' => ['sometimes', 'nullable', 'string', 'max:64'],
            'proxy_id_document_number' => ['sometimes', 'nullable', 'string', 'max:128'],
            'mandate_type' => ['sometimes', 'string', 'max:64'],
            'starts_on' => ['sometimes', 'nullable', 'date'],
            'ends_on' => ['sometimes', 'nullable', 'date', 'after_or_equal:starts_on'],
            'document_public_id' => ['sometimes', 'nullable', 'string', 'exists:documents,public_id'],
        ];
    }
}
