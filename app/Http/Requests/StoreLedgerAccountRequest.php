<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\LedgerAccount;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreLedgerAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User && $user->can('create', LedgerAccount::class);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'agency_public_id' => ['nullable', 'string', 'exists:agencies,public_id'],
            'code' => ['required', 'string', 'max:64'],
            'name' => ['required', 'string', 'max:255'],
            'account_class' => ['required', Rule::in([
                LedgerAccount::ACCOUNT_CLASS_ASSET,
                LedgerAccount::ACCOUNT_CLASS_LIABILITY,
                LedgerAccount::ACCOUNT_CLASS_EQUITY,
                LedgerAccount::ACCOUNT_CLASS_REVENUE,
                LedgerAccount::ACCOUNT_CLASS_EXPENSE,
            ])],
            'account_type' => ['nullable', 'string', 'max:64'],
            'parent_account_public_id' => ['nullable', 'string', 'exists:ledger_accounts,public_id'],
            'normal_balance_side' => ['required', Rule::in([
                LedgerAccount::NORMAL_BALANCE_DEBIT,
                LedgerAccount::NORMAL_BALANCE_CREDIT,
            ])],
            'status' => ['nullable', Rule::in([
                LedgerAccount::STATUS_ACTIVE,
                LedgerAccount::STATUS_INACTIVE,
                LedgerAccount::STATUS_SUSPENDED,
                LedgerAccount::STATUS_ARCHIVED,
            ])],
        ];
    }
}
