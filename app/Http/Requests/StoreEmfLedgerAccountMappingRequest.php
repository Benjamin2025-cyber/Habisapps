<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\EmfLedgerAccountMapping;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreEmfLedgerAccountMappingRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User && $user->can('create', EmfLedgerAccountMapping::class);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'emf_regulatory_account_public_id' => ['required', 'string', 'exists:emf_regulatory_accounts,public_id'],
            'ledger_account_public_id' => ['required', 'string', 'exists:ledger_accounts,public_id'],
            'status' => ['nullable', Rule::in([
                EmfLedgerAccountMapping::STATUS_ACTIVE,
                EmfLedgerAccountMapping::STATUS_INACTIVE,
            ])],
        ];
    }
}
