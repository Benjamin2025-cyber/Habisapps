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
            'scope' => ['nullable', Rule::in([
                LedgerAccount::SCOPE_AGENCY,
                LedgerAccount::SCOPE_INSTITUTION,
            ])],
            'agency_public_id' => ['nullable', 'string', 'exists:agencies,public_id'],
            'code' => ['required', 'string', 'max:64'],
            'name' => ['required', 'string', 'max:255'],
            'account_class' => ['required', Rule::in(LedgerAccount::accountClasses())],
            'account_type' => ['nullable', 'string', 'max:64'],
            'is_postable' => ['nullable', 'boolean'],
            'parent_account_public_id' => ['nullable', 'string', 'exists:ledger_accounts,public_id'],
            // Nullable is meaningful: no imposed side, for an account that
            // takes entries both ways by nature (comptes de liaison, de
            // régularisation, hors bilan). Without this the only way to
            // configure one would be to edit the seed data and re-seed.
            'normal_balance_side' => ['present', 'nullable', Rule::in([
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
