<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\CustomerAccount;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreCustomerAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User && $user->hasRole('platform-admin');
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'client_public_id' => ['required', 'string', 'exists:clients,public_id'],
            'agency_public_id' => ['nullable', 'string', 'exists:agencies,public_id'],
            'ledger_account_public_id' => ['nullable', 'string', 'exists:ledger_accounts,public_id'],
            'account_number' => ['required', 'string', 'max:64', 'unique:customer_accounts,account_number'],
            'account_type' => ['nullable', 'string', 'max:64'],
            'opened_on' => ['required', 'date'],
            'closed_on' => ['nullable', 'date', 'after_or_equal:opened_on'],
            'status' => ['nullable', Rule::in([
                CustomerAccount::STATUS_ACTIVE,
                CustomerAccount::STATUS_SUSPENDED,
            ])],
        ];
    }
}
