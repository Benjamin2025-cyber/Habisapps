<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Till;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreTillRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User && $user->can('create', Till::class);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'agency_public_id' => ['sometimes', 'string', 'exists:agencies,public_id'],
            'code' => ['required', 'string', 'max:32'],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['sometimes', 'string', 'max:32'],
            'status' => ['sometimes', 'string', Rule::in([Till::STATUS_ACTIVE, Till::STATUS_INACTIVE])],
            'daily_state' => ['sometimes', 'string', Rule::in([Till::DAILY_STATE_OPEN, Till::DAILY_STATE_CLOSED])],
            'opening_balance_minor' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'last_closing_balance_minor' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'requires_denominations' => ['sometimes', 'boolean'],
            'nature' => ['sometimes', 'nullable', 'string', 'max:64'],
            'is_central_till' => ['sometimes', 'boolean'],
            'max_balance_limit_minor' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'max_withdrawal_limit_minor' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'assigned_user_public_id' => ['nullable', 'string', 'exists:users,public_id'],
            'ledger_account_public_id' => ['sometimes', 'nullable', 'string', 'exists:ledger_accounts,public_id'],
        ];
    }
}
