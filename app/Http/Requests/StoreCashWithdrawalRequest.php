<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\TellerTransaction;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreCashWithdrawalRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User && $user->can('create', TellerTransaction::class);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'customer_account_public_id' => ['required', 'string', 'exists:customer_accounts,public_id'],
            'amount_minor' => ['required', 'integer', 'min:1'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'payment_method' => ['sometimes', 'nullable', Rule::in(['cash', 'cheque', 'transfer', 'mixed'])],
            'denomination_counts' => ['sometimes', 'nullable', 'array'],
            'denomination_counts.*.denomination_public_id' => ['required_with:denomination_counts', 'string', 'exists:denominations,public_id'],
            'denomination_counts.*.count' => ['required_with:denomination_counts', 'integer', 'min:0'],
            'cash_amount_minor' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'cheque_amount_minor' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'transfer_amount_minor' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'cheque_number' => ['sometimes', 'nullable', 'string', 'max:64'],
            'cheque_bank_name' => ['sometimes', 'nullable', 'string', 'max:128'],
            'cheque_issue_date' => ['sometimes', 'nullable', 'date'],
            'external_reference' => ['sometimes', 'nullable', 'string', 'max:128'],
            'channel' => ['sometimes', 'nullable', Rule::in(['branch_counter', 'mobile_money', 'bank_transfer', 'clearing_house', 'internal_transfer'])],
            'fee_policy_key' => ['sometimes', 'nullable', 'string', 'max:64'],
            'fee_amount_minor' => ['prohibited'],
            'notify_customer' => ['sometimes', 'boolean'],
            'notification_channels' => ['sometimes', 'nullable', 'array'],
            'notification_channels.*' => ['string', Rule::in(['sms', 'email', 'push'])],
            'operation_code' => ['sometimes', 'nullable', 'string', 'max:64'],
            'initiator_type' => ['sometimes', 'nullable', 'string', 'in:holder,proxy,staff_on_behalf,system'],
            'initiator_proxy_public_id' => ['sometimes', 'nullable', 'string', 'exists:client_proxies,public_id'],
            'signature_public_id' => ['required', 'string', 'exists:customer_account_signatures,public_id'],
            'signature_verification_method' => ['required', 'string', Rule::in([
                TellerTransaction::SIGNATURE_METHOD_VISUAL_MATCH,
                TellerTransaction::SIGNATURE_METHOD_THUMBPRINT_MATCH,
                TellerTransaction::SIGNATURE_METHOD_VERIFIED_PROXY_MANDATE,
                TellerTransaction::SIGNATURE_METHOD_EXCEPTION_OVERRIDE,
            ])],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'idempotency_key' => ['sometimes', 'nullable', 'string', 'max:128'],
        ];
    }
}
