<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\LedgerAccount;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateLedgerAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User && ($user->hasRole('platform-admin') || $user->can('ledger.accounts.update'));
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'account_type' => ['sometimes', 'nullable', 'string', 'max:64'],
            'parent_account_public_id' => ['sometimes', 'nullable', 'string', 'exists:ledger_accounts,public_id'],
            'normal_balance_side' => ['sometimes', Rule::in([
                LedgerAccount::NORMAL_BALANCE_DEBIT,
                LedgerAccount::NORMAL_BALANCE_CREDIT,
            ])],
            'status' => ['sometimes', Rule::in([
                LedgerAccount::STATUS_ACTIVE,
                LedgerAccount::STATUS_INACTIVE,
                LedgerAccount::STATUS_SUSPENDED,
                LedgerAccount::STATUS_ARCHIVED,
            ])],
        ];
    }
}
