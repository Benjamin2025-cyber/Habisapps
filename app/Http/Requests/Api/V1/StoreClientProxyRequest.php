<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Models\ClientProxy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreClientProxyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', ClientProxy::class) === true;
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
            'customer_account_public_id' => ['nullable', 'string', 'exists:customer_accounts,public_id'],
            'operation_types' => ['nullable', 'array', 'min:1'],
            'operation_types.*' => ['string', 'max:64', Rule::in(['deposit', 'withdrawal', 'transfer', 'loan_repayment', 'statement_request'])],
            'max_amount_minor' => ['nullable', 'integer', 'min:0'],
            'limit_currency' => ['nullable', 'required_with:max_amount_minor', 'string', 'size:3'],
            'starts_on' => ['nullable', 'date'],
            'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
            'document_public_id' => ['nullable', 'string', 'exists:documents,public_id'],
        ];
    }
}
