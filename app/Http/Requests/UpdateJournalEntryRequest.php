<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

final class UpdateJournalEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User && $user->hasRole('platform-admin');
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'reference' => ['sometimes', 'string', 'max:64'],
            'business_date' => ['sometimes', 'date'],
            'source_module' => ['sometimes', 'nullable', 'string', 'max:64'],
            'source_type' => ['sometimes', 'nullable', 'string', 'max:64'],
            'source_public_id' => ['sometimes', 'nullable', 'string', 'max:64'],
            'description' => ['sometimes', 'nullable', 'string'],
            'idempotency_key' => ['sometimes', 'nullable', 'string', 'max:128'],
        ];
    }
}
