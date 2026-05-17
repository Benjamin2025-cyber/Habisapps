<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\AccountProduct;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreAccountProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User && $user->can('create', AccountProduct::class);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'agency_public_id' => ['nullable', 'string', 'exists:agencies,public_id'],
            'ledger_account_public_id' => ['nullable', 'string', 'exists:ledger_accounts,public_id'],
            'code' => ['required', 'string', 'max:64'],
            'name' => ['required', 'string', 'max:255'],
            'account_family' => ['required', Rule::in([
                AccountProduct::FAMILY_SAVINGS,
                AccountProduct::FAMILY_CURRENT,
                AccountProduct::FAMILY_RECOVERY,
                AccountProduct::FAMILY_ISLAMIC,
            ])],
            'minimum_balance_minor' => ['nullable', 'integer', 'min:0'],
            'currency' => ['nullable', 'string', 'size:3'],
            'allows_recovery_debit' => ['nullable', 'boolean'],
            'is_recovery_account' => ['nullable', 'boolean'],
            'is_ordinary_savings' => ['nullable', 'boolean'],
            'allows_overdraft' => ['nullable', 'boolean'],
            'overdraft_limit_minor' => ['nullable', 'integer', 'min:0'],
            'status' => ['nullable', Rule::in([
                AccountProduct::STATUS_ACTIVE,
                AccountProduct::STATUS_INACTIVE,
            ])],
            'rules' => ['nullable', 'array'],
            'rules.*' => ['nullable'],
        ];
    }
}
