<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\AccountHold;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreAccountHoldRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User && $user->can('create', AccountHold::class);
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
            'source_type' => ['nullable', 'string', 'max:64'],
            'source_public_id' => ['nullable', 'string', 'max:64'],
            'expires_at' => ['nullable', 'date'],
            'status' => ['nullable', Rule::in(['active'])],
            'reference' => ['nullable', 'string', 'max:128'],
        ];
    }
}
