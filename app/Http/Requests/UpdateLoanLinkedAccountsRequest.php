<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Loan;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

final class UpdateLoanLinkedAccountsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $loan = $this->route('loan');

        return $user instanceof User && $loan instanceof Loan && $user->can('update', $loan);
    }

    /**
     * Only the linked customer-account fields are editable through this
     * endpoint. No loan terms (amount, schedule, sector, etc.) are accepted.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'amortization_account_public_id' => ['sometimes', 'nullable', 'string', 'exists:customer_accounts,public_id'],
            'unpaid_account_public_id' => ['sometimes', 'nullable', 'string', 'exists:customer_accounts,public_id'],
            'recovery_account_public_id' => ['sometimes', 'nullable', 'string', 'exists:customer_accounts,public_id'],
            'transfer_account_public_id' => ['sometimes', 'nullable', 'string', 'exists:customer_accounts,public_id'],
        ];
    }
}
