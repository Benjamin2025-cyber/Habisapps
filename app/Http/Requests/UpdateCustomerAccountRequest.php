<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\CustomerAccount;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateCustomerAccountRequest extends FormRequest
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
            'ledger_account_public_id' => ['sometimes', 'nullable', 'string', 'exists:ledger_accounts,public_id'],
            'account_type' => ['sometimes', 'nullable', 'string', 'max:64'],
            'closed_on' => ['sometimes', 'nullable', 'date'],
            'status' => ['sometimes', Rule::in([
                CustomerAccount::STATUS_ACTIVE,
                CustomerAccount::STATUS_SUSPENDED,
                CustomerAccount::STATUS_CLOSED,
                CustomerAccount::STATUS_ARCHIVED,
            ])],
        ];
    }
}
