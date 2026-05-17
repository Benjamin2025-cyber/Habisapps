<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\AccountHold;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

final class ReleaseAccountHoldRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        $accountHold = $this->route('accountHold');

        return $user instanceof User
            && $accountHold instanceof AccountHold
            && $user->can('release', $accountHold);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'reference' => ['nullable', 'string', 'max:128'],
            'release_reason' => ['nullable', 'string', 'max:255'],
        ];
    }
}
