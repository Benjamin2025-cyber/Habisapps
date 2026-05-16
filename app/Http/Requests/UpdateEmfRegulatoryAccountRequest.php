<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\EmfRegulatoryAccount;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateEmfRegulatoryAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $account = $this->route('emfRegulatoryAccount');

        return $user instanceof User
            && $account instanceof EmfRegulatoryAccount
            && $user->can('update', $account);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'parent_public_id' => ['sometimes', 'nullable', 'string', 'exists:emf_regulatory_accounts,public_id'],
            'name' => ['sometimes', 'string', 'max:255'],
            'account_class' => ['sometimes', 'nullable', 'string', 'max:32'],
            'status' => ['sometimes', Rule::in([
                EmfRegulatoryAccount::STATUS_ACTIVE,
                EmfRegulatoryAccount::STATUS_INACTIVE,
                EmfRegulatoryAccount::STATUS_ARCHIVED,
            ])],
            'metadata' => ['sometimes', 'nullable', 'array'],
            'metadata.*' => ['nullable'],
        ];
    }
}
