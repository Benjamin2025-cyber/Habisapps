<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateStaffUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('users.update') === true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $staffUser = $this->route('staffUser');
        $userId = $staffUser instanceof User ? $staffUser->id : null;

        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'phone_number' => ['sometimes', 'string', 'max:32', Rule::unique('users', 'phone_number')->ignore($userId)],
            'email' => ['sometimes', 'nullable', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($userId)],
            'matricule' => ['sometimes', 'nullable', 'string', 'max:64', Rule::unique('users', 'matricule')->ignore($userId)],
            'job_title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'agency_code' => ['sometimes', 'nullable', 'string', 'max:64'],
            'agency_name' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}
