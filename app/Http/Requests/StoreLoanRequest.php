<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Loan;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

final class StoreLoanRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User && $user->can('create', Loan::class);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'client_public_id' => ['required', 'string', 'exists:clients,public_id'],
            'loan_product_public_id' => ['required', 'string', 'exists:loan_products,public_id'],
            'credit_agent_public_id' => ['nullable', 'string', 'exists:users,public_id'],
            'amortization_account_public_id' => ['nullable', 'string', 'exists:customer_accounts,public_id'],
            'unpaid_account_public_id' => ['nullable', 'string', 'exists:customer_accounts,public_id'],
            'recovery_account_public_id' => ['nullable', 'string', 'exists:customer_accounts,public_id'],
            'transfer_account_public_id' => ['nullable', 'string', 'exists:customer_accounts,public_id'],
            'requested_amount_minor' => ['required', 'integer', 'min:1'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'applied_on' => ['sometimes', 'date'],
            'purpose' => ['nullable', 'string', 'max:1000'],
            'sector_public_id' => ['nullable', 'string', 'exists:sectors,public_id'],
            'sub_sector_public_id' => ['nullable', 'string', 'exists:sub_sectors,public_id'],
            'financed_activity_code' => ['nullable', 'string', 'max:64'],
            'activity_address' => ['nullable', 'string', 'max:1000'],
            'entrepreneur_address' => ['nullable', 'string', 'max:1000'],
            'first_installment_date' => ['nullable', 'date'],
            'number_of_installments' => ['nullable', 'integer', 'min:1'],
            'grace_period_duration' => ['nullable', 'integer', 'min:0'],
            'tranche_duration' => ['nullable', 'integer', 'min:1'],
            'total_loan_duration' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
