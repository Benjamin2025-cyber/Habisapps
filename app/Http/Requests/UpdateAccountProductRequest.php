<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\AccountProduct;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateAccountProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $accountProduct = $this->route('accountProduct');

        return $user instanceof User
            && $accountProduct instanceof AccountProduct
            && $user->can('update', $accountProduct);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'ledger_account_public_id' => ['sometimes', 'nullable', 'string', 'exists:ledger_accounts,public_id'],
            'name' => ['sometimes', 'string', 'max:255'],
            'account_family' => ['sometimes', Rule::in([
                AccountProduct::FAMILY_SAVINGS,
                AccountProduct::FAMILY_CURRENT,
                AccountProduct::FAMILY_RECOVERY,
                AccountProduct::FAMILY_ISLAMIC,
            ])],
            'minimum_balance_minor' => ['sometimes', 'integer', 'min:0'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'allows_recovery_debit' => ['sometimes', 'boolean'],
            'is_recovery_account' => ['sometimes', 'boolean'],
            'is_ordinary_savings' => ['sometimes', 'boolean'],
            'allows_overdraft' => ['sometimes', 'boolean'],
            'overdraft_limit_minor' => ['sometimes', 'integer', 'min:0'],
            'status' => ['sometimes', Rule::in([
                AccountProduct::STATUS_ACTIVE,
                AccountProduct::STATUS_INACTIVE,
                AccountProduct::STATUS_ARCHIVED,
            ])],
            'rules' => ['sometimes', 'nullable', 'array'],
            'rules.*' => ['nullable'],
        ];
    }
}
