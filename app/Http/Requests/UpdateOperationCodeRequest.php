<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\OperationCode;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateOperationCodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $operationCode = $this->route('operationCode');

        return $user instanceof User
            && $operationCode instanceof OperationCode
            && $user->can('update', $operationCode);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'label' => ['sometimes', 'string', 'max:255'],
            'module' => ['sometimes', Rule::in(OperationCode::MODULES)],
            'operation_type' => ['sometimes', 'nullable', 'string', 'max:64'],
            'direction' => ['sometimes', 'nullable', 'string', 'max:32'],
            'status' => ['sometimes', Rule::in([OperationCode::STATUS_ACTIVE, OperationCode::STATUS_INACTIVE, OperationCode::STATUS_ARCHIVED])],
            'metadata' => ['sometimes', 'nullable', 'array'],
            'metadata.*' => ['nullable'],
        ];
    }
}
