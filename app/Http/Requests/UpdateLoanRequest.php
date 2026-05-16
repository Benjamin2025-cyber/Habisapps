<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Loan;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

final class UpdateLoanRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $loan = $this->route('loan');

        return $user instanceof User && $loan instanceof Loan && $user->can('update', $loan);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'credit_agent_public_id' => ['sometimes', 'nullable', 'string', 'exists:users,public_id'],
            'amortization_account_public_id' => ['sometimes', 'nullable', 'string', 'exists:customer_accounts,public_id'],
            'unpaid_account_public_id' => ['sometimes', 'nullable', 'string', 'exists:customer_accounts,public_id'],
            'recovery_account_public_id' => ['sometimes', 'nullable', 'string', 'exists:customer_accounts,public_id'],
            'transfer_account_public_id' => ['sometimes', 'nullable', 'string', 'exists:customer_accounts,public_id'],
            'requested_amount_minor' => ['sometimes', 'integer', 'min:1'],
            'purpose' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'sector_public_id' => ['sometimes', 'nullable', 'string', 'exists:sectors,public_id'],
            'sub_sector_public_id' => ['sometimes', 'nullable', 'string', 'exists:sub_sectors,public_id'],
            'financed_activity_code' => ['sometimes', 'nullable', 'string', 'max:64'],
            'activity_address' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'entrepreneur_address' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'first_installment_date' => ['sometimes', 'nullable', 'date'],
            'number_of_installments' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'grace_period_duration' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'tranche_duration' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'total_loan_duration' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ];
    }
}
