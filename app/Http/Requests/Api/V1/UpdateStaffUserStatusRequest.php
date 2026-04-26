<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateStaffUserStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('users.status.manage') === true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', 'string', Rule::in([
                User::STATUS_ACTIVE,
                User::STATUS_SUSPENDED,
                User::STATUS_DEACTIVATED,
            ])],
        ];
    }
}
