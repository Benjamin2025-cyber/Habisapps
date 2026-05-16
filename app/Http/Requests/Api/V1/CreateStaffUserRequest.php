<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

final class CreateStaffUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('users.create') === true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'phone_number' => ['required', 'string', 'max:32', 'unique:users,phone_number'],
            'email' => ['nullable', 'string', 'email', 'max:255', 'unique:users,email'],
            'matricule' => ['nullable', 'string', 'max:64', 'unique:users,matricule'],
            'job_title' => ['nullable', 'string', 'max:255'],
            'gender' => ['nullable', 'string', 'max:32'],
            'birth_date' => ['nullable', 'date', 'before:today'],
            'birth_place' => ['nullable', 'string', 'max:128'],
            'service_name' => ['nullable', 'string', 'max:128'],
            'supervisor_public_id' => ['nullable', 'string', 'exists:users,public_id'],
            'portfolio_code' => ['nullable', 'string', 'max:64'],
            'agency_code' => ['nullable', 'string', 'max:64', 'exists:agencies,code'],
            'agency_name' => ['nullable', 'string', 'max:255'],
        ];
    }
}
