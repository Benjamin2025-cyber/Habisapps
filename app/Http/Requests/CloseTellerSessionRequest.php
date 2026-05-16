<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\TellerSession;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

final class CloseTellerSessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $session = $this->route('tellerSession');

        return $user instanceof User
            && $session instanceof TellerSession
            && $user->can('close', $session);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'closing_declaration_minor' => ['required', 'integer', 'min:0'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'denomination_counts' => ['sometimes', 'array'],
            'denomination_counts.*.denomination_public_id' => ['required_with:denomination_counts', 'string', 'exists:denominations,public_id'],
            'denomination_counts.*.count' => ['required_with:denomination_counts', 'integer', 'min:0'],
        ];
    }
}
