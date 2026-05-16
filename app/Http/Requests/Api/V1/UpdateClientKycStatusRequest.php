<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateClientKycStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'action' => ['required', Rule::in(['submit', 'verify', 'reject', 'suspend', 'archive'])],
            'reason' => ['nullable', 'string', 'max:1000', 'required_if:action,reject'],
            'comment' => ['nullable', 'string', 'max:1000'],
            'force_override_expired_identity' => ['sometimes', 'boolean'],
            'allow_self_verify' => ['sometimes', 'boolean'],
        ];
    }
}
