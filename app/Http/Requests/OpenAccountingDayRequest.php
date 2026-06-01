<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\AccountingDay;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

final class OpenAccountingDayRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User && $user->can('open', AccountingDay::class);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'scope' => ['sometimes', 'string', 'in:agency,institution'],
            'agency_public_id' => ['sometimes', 'nullable', 'string', 'exists:agencies,public_id'],
            'business_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
        ];
    }
}
