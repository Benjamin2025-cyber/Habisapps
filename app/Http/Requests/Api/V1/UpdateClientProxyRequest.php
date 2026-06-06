<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Models\ClientProxy;
use App\Support\Crm\IdentityDocumentTypeCatalog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateClientProxyRequest extends FormRequest
{
    public function authorize(): bool
    {
        $proxy = $this->route('proxy');

        return $proxy instanceof ClientProxy
            && $this->user()?->can('update', $proxy) === true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $safeText = ['string', 'not_regex:/[<>]/'];

        return [
            'proxy_full_name' => ['sometimes', 'string', 'max:255'],
            'proxy_first_name' => ['sometimes', 'nullable', ...$safeText, 'max:128'],
            'proxy_last_name' => ['sometimes', 'nullable', ...$safeText, 'max:128'],
            'proxy_middle_name' => ['sometimes', 'nullable', ...$safeText, 'max:128'],
            'proxy_date_of_birth' => ['sometimes', 'nullable', 'date', 'before_or_equal:today'],
            'proxy_place_of_birth' => ['sometimes', 'nullable', ...$safeText, 'max:255'],
            'proxy_identity_issued_on' => ['sometimes', 'nullable', 'date', 'before_or_equal:today'],
            'proxy_identity_issued_at' => ['sometimes', 'nullable', ...$safeText, 'max:255'],
            'proxy_father_name' => ['sometimes', 'nullable', ...$safeText, 'max:128'],
            'proxy_mother_name' => ['sometimes', 'nullable', ...$safeText, 'max:128'],
            'proxy_address_line_1' => ['sometimes', 'nullable', ...$safeText, 'max:255'],
            'proxy_address_line_2' => ['sometimes', 'nullable', ...$safeText, 'max:255'],
            'proxy_business_address_line_1' => ['sometimes', 'nullable', ...$safeText, 'max:255'],
            'proxy_business_address_line_2' => ['sometimes', 'nullable', ...$safeText, 'max:255'],
            'proxy_phone_number' => ['sometimes', 'nullable', 'string', 'max:32'],
            'proxy_email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'proxy_id_document_type' => ['sometimes', 'nullable', 'string', 'max:64', Rule::in(IdentityDocumentTypeCatalog::keys())],
            'proxy_id_document_number' => ['sometimes', 'nullable', 'string', 'max:128'],
            'mandate_type' => ['sometimes', 'string', 'max:64'],
            'customer_account_public_id' => ['sometimes', 'nullable', 'string', 'exists:customer_accounts,public_id'],
            'operation_types' => ['sometimes', 'nullable', 'array', 'min:1'],
            'operation_types.*' => ['string', 'max:64', Rule::in(['deposit', 'withdrawal', 'transfer', 'loan_repayment', 'statement_request'])],
            'max_amount_minor' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'limit_currency' => ['sometimes', 'nullable', 'required_with:max_amount_minor', 'string', 'size:3'],
            'starts_on' => ['sometimes', 'nullable', 'date'],
            'ends_on' => ['sometimes', 'nullable', 'date', 'after_or_equal:starts_on'],
            'document_public_id' => ['sometimes', 'nullable', 'string', 'exists:documents,public_id'],
            'back_document_public_id' => ['sometimes', 'nullable', 'string', 'exists:documents,public_id', 'different:document_public_id'],
        ];
    }
}
