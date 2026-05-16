<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\TillReconciliation;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

final class StoreTillReconciliationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User && $user->can('create', TillReconciliation::class);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'currency' => ['sometimes', 'string', 'size:3'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'denomination_counts' => ['required', 'array', 'min:1'],
            'denomination_counts.*.denomination_public_id' => ['required', 'string', 'exists:denominations,public_id'],
            'denomination_counts.*.count' => ['required', 'integer', 'min:0'],
        ];
    }
}
