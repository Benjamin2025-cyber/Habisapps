<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\OperationAccountMapping;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreOperationAccountMappingRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User && $user->can('create', OperationAccountMapping::class);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'operation_code_public_id' => ['required', 'string', 'exists:operation_codes,public_id'],
            'agency_public_id' => ['nullable', 'string', 'exists:agencies,public_id'],
            'debit_ledger_account_public_id' => ['nullable', 'required_without:credit_ledger_account_public_id', 'string', 'exists:ledger_accounts,public_id'],
            'credit_ledger_account_public_id' => ['nullable', 'required_without:debit_ledger_account_public_id', 'string', 'exists:ledger_accounts,public_id'],
            'currency' => ['nullable', 'string', 'size:3'],
            'effective_from' => ['nullable', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'status' => ['nullable', Rule::in([OperationAccountMapping::STATUS_ACTIVE, OperationAccountMapping::STATUS_INACTIVE])],
            'approval_status' => ['nullable', Rule::in([
                OperationAccountMapping::APPROVAL_DRAFT,
                OperationAccountMapping::APPROVAL_SUBMITTED,
                OperationAccountMapping::APPROVAL_APPROVED,
            ])],
            'rules' => ['nullable', 'array'],
            'rules.*' => ['nullable'],
        ];
    }
}
