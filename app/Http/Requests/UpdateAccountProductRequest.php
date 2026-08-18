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
            /*
             * Nullable, exactly as on create. `sometimes` only skips a key that is
             * absent — a key present and null still had to satisfy `integer`, so a
             * product could be created with no overdraft limit and then not saved
             * again: the form sends null for the limit whenever the overdraft box
             * is unchecked, and hides the field at the same time. The refusal
             * therefore named « Plafond de découvert », a field not on screen.
             */
            'minimum_balance_minor' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'currency' => ['sometimes', 'nullable', 'string', 'size:3'],
            'allows_overdraft' => ['sometimes', 'nullable', 'boolean'],
            'overdraft_limit_minor' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'status' => ['sometimes', 'nullable', Rule::in([
                AccountProduct::STATUS_ACTIVE,
                AccountProduct::STATUS_INACTIVE,
                AccountProduct::STATUS_ARCHIVED,
            ])],
            'rules' => ['sometimes', 'nullable', 'array'],
            'rules.*' => ['nullable'],
        ];
    }
}
