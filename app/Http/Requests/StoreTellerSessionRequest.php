<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\TellerSession;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

final class StoreTellerSessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User && $user->can('create', TellerSession::class);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'till_public_id' => ['required', 'string', 'exists:tills,public_id'],
            'teller_user_public_id' => ['sometimes', 'nullable', 'string', 'exists:users,public_id'],
            // Optional: the open accounting day governs the business date. When
            // supplied it must equal the open day (enforced by AccountingDayGuard).
            'business_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'opening_declaration_minor' => ['required', 'integer', 'min:0'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'denomination_counts' => ['sometimes', 'array'],
            'denomination_counts.*.denomination_public_id' => ['required_with:denomination_counts', 'string', 'exists:denominations,public_id'],
            'denomination_counts.*.count' => ['required_with:denomination_counts', 'integer', 'min:0'],
        ];
    }
}
