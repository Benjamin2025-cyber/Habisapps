<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Till;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateTillRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $till = $this->route('till');

        return $user instanceof User
            && $till instanceof Till
            && $user->can('update', $till);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'agency_public_id' => ['sometimes', 'string', 'exists:agencies,public_id'],
            'code' => ['sometimes', 'string', 'max:32'],
            'name' => ['sometimes', 'string', 'max:255'],
            'type' => ['sometimes', 'string', 'max:32'],
            'status' => ['sometimes', 'string', Rule::in([Till::STATUS_ACTIVE, Till::STATUS_INACTIVE])],
            'assigned_user_public_id' => ['sometimes', 'nullable', 'string', 'exists:users,public_id'],
        ];
    }
}
