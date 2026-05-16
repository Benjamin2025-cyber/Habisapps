<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\OperationAccountMapping;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateOperationAccountMappingRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $mapping = $this->route('operationAccountMapping');

        return $user instanceof User
            && $mapping instanceof OperationAccountMapping
            && $user->can('update', $mapping);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'status' => ['sometimes', Rule::in([OperationAccountMapping::STATUS_ACTIVE, OperationAccountMapping::STATUS_INACTIVE, OperationAccountMapping::STATUS_ARCHIVED])],
            'rules' => ['sometimes', 'nullable', 'array'],
            'rules.*' => ['nullable'],
        ];
    }
}
