<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Till;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreTillRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User && $user->can('create', Till::class);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'agency_public_id' => ['sometimes', 'string', 'exists:agencies,public_id'],
            'code' => ['required', 'string', 'max:32'],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['sometimes', 'string', 'max:32'],
            'status' => ['sometimes', 'string', Rule::in([Till::STATUS_ACTIVE, Till::STATUS_INACTIVE])],
            'assigned_user_public_id' => ['nullable', 'string', 'exists:users,public_id'],
        ];
    }
}
