<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\EmfRegulatoryAccount;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreEmfRegulatoryAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User && $user->can('create', EmfRegulatoryAccount::class);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'parent_public_id' => ['nullable', 'string', 'exists:emf_regulatory_accounts,public_id'],
            'code' => ['required', 'string', 'max:64', 'unique:emf_regulatory_accounts,code'],
            'name' => ['required', 'string', 'max:255'],
            'account_class' => ['nullable', 'string', 'max:32'],
            'status' => ['nullable', Rule::in([
                EmfRegulatoryAccount::STATUS_ACTIVE,
                EmfRegulatoryAccount::STATUS_INACTIVE,
            ])],
            'metadata' => ['nullable', 'array'],
            'metadata.*' => ['nullable'],
        ];
    }
}
