<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

final class ActivateStaffRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'phone_number' => ['required', 'string', 'max:32'],
            'otp' => ['required', 'string', 'digits:6'],
            'password' => ['required', 'confirmed', 'max:255', Password::defaults()],
        ];
    }
}
