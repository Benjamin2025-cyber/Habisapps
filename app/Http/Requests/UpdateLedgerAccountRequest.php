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

        $ledgerAccount = $this->route('ledgerAccount');

        return $user instanceof User
            && $ledgerAccount instanceof LedgerAccount
            && $user->can('update', $ledgerAccount);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            // Correctable while the account has no movements: a class chosen by
            // mistake must not strand a PCEMF code that cannot be reinvented.
            // The controller freezes it as soon as movements exist.
            'account_class' => ['sometimes', Rule::in(LedgerAccount::accountClasses())],
            'account_type' => ['sometimes', 'nullable', 'string', 'max:64'],
            'is_postable' => ['sometimes', 'boolean'],
            'parent_account_public_id' => ['sometimes', 'nullable', 'string', 'exists:ledger_accounts,public_id'],
            // Nullable is meaningful: no imposed side, for an account that
            // takes entries both ways by nature (comptes de liaison, de
            // régularisation, hors bilan). Without this the only way to
            // configure one would be to edit the seed data and re-seed.
            'normal_balance_side' => ['sometimes', 'nullable', Rule::in([
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
