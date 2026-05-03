<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreAccountHoldRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User && $user->hasRole('platform-admin');
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'customer_account_public_id' => ['required', 'string', 'exists:customer_accounts,public_id'],
            'amount_minor' => ['required', 'integer', 'min:1'],
            'currency' => ['required', 'string', 'size:3', 'uppercase'],
            'reason_type' => ['required', 'string', 'max:64'],
            'status' => ['nullable', Rule::in(['active'])],
            'reference' => ['nullable', 'string', 'max:128'],
        ];
    }
}
