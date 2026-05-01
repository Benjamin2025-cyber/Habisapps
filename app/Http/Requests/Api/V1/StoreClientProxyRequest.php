<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

final class StoreClientProxyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('crm.proxies.create') === true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'proxy_full_name' => ['required', 'string', 'max:255'],
            'proxy_phone_number' => ['nullable', 'string', 'max:32'],
            'proxy_email' => ['nullable', 'email', 'max:255'],
            'proxy_id_document_type' => ['nullable', 'string', 'max:64'],
            'proxy_id_document_number' => ['nullable', 'string', 'max:128'],
            'mandate_type' => ['required', 'string', 'max:64'],
            'starts_on' => ['nullable', 'date'],
            'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
            'document_public_id' => ['nullable', 'string', 'exists:documents,public_id'],
        ];
    }
}
