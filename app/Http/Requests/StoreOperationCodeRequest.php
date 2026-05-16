<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\OperationCode;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreOperationCodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User && $user->can('create', OperationCode::class);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:64', 'unique:operation_codes,code'],
            'label' => ['required', 'string', 'max:255'],
            'module' => ['required', Rule::in(OperationCode::MODULES)],
            'operation_type' => ['nullable', 'string', 'max:64'],
            'direction' => ['nullable', 'string', 'max:32'],
            'status' => ['nullable', Rule::in([OperationCode::STATUS_ACTIVE, OperationCode::STATUS_INACTIVE])],
            'metadata' => ['nullable', 'array'],
            'metadata.*' => ['nullable'],
        ];
    }
}
