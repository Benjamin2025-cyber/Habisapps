<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\EmfLedgerAccountMapping;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateEmfLedgerAccountMappingRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $mapping = $this->route('emfLedgerAccountMapping');

        return $user instanceof User
            && $mapping instanceof EmfLedgerAccountMapping
            && $user->can('update', $mapping);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'status' => ['sometimes', Rule::in([
                EmfLedgerAccountMapping::STATUS_ACTIVE,
                EmfLedgerAccountMapping::STATUS_INACTIVE,
                EmfLedgerAccountMapping::STATUS_ARCHIVED,
            ])],
        ];
    }
}
