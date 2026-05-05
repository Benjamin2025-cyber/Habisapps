<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\JournalLine;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

final class StoreJournalLineRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User && $user->can('create', JournalLine::class);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'journal_entry_public_id' => ['required', 'string', 'exists:journal_entries,public_id'],
            'ledger_account_public_id' => ['required', 'string', 'exists:ledger_accounts,public_id'],
            'customer_account_public_id' => ['nullable', 'string', 'exists:customer_accounts,public_id'],
            'debit_minor' => ['required', 'integer', 'min:0'],
            'credit_minor' => ['required', 'integer', 'min:0'],
            'currency' => ['required', 'string', 'size:3', 'uppercase'],
            'line_memo' => ['nullable', 'string'],
        ];
    }
}
