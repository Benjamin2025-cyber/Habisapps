<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\TellerTransaction;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

final class StoreCashDepositRequest extends FormRequest
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
            'operation_code' => ['sometimes', 'nullable', 'string', 'max:64'],
            'depositor_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'depositor_address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'initiator_type' => ['sometimes', 'nullable', 'string', 'in:holder,proxy,staff_on_behalf,system'],
            'initiator_proxy_public_id' => ['sometimes', 'nullable', 'string', 'exists:client_proxies,public_id'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'idempotency_key' => ['sometimes', 'nullable', 'string', 'max:128'],
        ];
    }
}
