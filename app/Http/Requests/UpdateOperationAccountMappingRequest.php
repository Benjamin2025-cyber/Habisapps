<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\OperationAccountMapping;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateOperationAccountMappingRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $mapping = $this->route('operationAccountMapping');

        return $user instanceof User
            && $mapping instanceof OperationAccountMapping
            && $user->can('update', $mapping);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'agency_public_id' => ['sometimes', 'nullable', 'string', 'exists:agencies,public_id'],
            'debit_ledger_account_public_id' => ['sometimes', 'nullable', 'string', 'exists:ledger_accounts,public_id'],
            'credit_ledger_account_public_id' => ['sometimes', 'nullable', 'string', 'exists:ledger_accounts,public_id'],
            'currency' => ['sometimes', 'nullable', 'string', 'size:3'],
            'effective_from' => ['sometimes', 'nullable', 'date'],
            'effective_to' => ['sometimes', 'nullable', 'date', 'after_or_equal:effective_from'],
            'status' => ['sometimes', Rule::in([OperationAccountMapping::STATUS_ACTIVE, OperationAccountMapping::STATUS_INACTIVE, OperationAccountMapping::STATUS_ARCHIVED])],
            // Editing may withdraw a rule to draft or put it up for review; it
            // may not approve or reject it. Those are decisions, taken through
            // their own endpoints by someone other than the author.
            // Every state except the two decisions. Withdrawing, suspending or
            // revoking only ever takes a rule *out* of service, so it needs no
            // second pair of eyes; approving and rejecting are decisions, taken
            // through their own endpoints by someone other than the author.
            'approval_status' => ['sometimes', Rule::in([
                OperationAccountMapping::APPROVAL_DRAFT,
                OperationAccountMapping::APPROVAL_SUBMITTED,
                OperationAccountMapping::APPROVAL_SUSPENDED,
                OperationAccountMapping::APPROVAL_REVOKED,
                OperationAccountMapping::APPROVAL_EXPIRED,
                OperationAccountMapping::APPROVAL_ARCHIVED,
            ])],
            'rules' => ['sometimes', 'nullable', 'array'],
            'rules.*' => ['nullable'],
        ];
    }
}
