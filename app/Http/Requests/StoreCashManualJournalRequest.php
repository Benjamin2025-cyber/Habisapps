<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\TellerTransaction;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreCashManualJournalRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User && $user->can('create', TellerTransaction::class);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'reference' => ['sometimes', 'nullable', 'string', 'max:64'],
            'operation_code_public_id' => ['sometimes', 'nullable', 'string', 'exists:operation_codes,public_id'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'idempotency_key' => ['sometimes', 'nullable', 'string', 'max:128'],
            'lines' => ['required', 'array', 'min:2'],
            'lines.*.ledger_account_public_id' => ['required', 'string', 'exists:ledger_accounts,public_id'],
            'lines.*.customer_account_public_id' => ['sometimes', 'nullable', 'string', 'exists:customer_accounts,public_id'],
            'lines.*.direction' => ['required', 'string', Rule::in(['debit', 'credit'])],
            'lines.*.amount_minor' => ['required', 'integer', 'min:1'],
            'lines.*.memo' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}
