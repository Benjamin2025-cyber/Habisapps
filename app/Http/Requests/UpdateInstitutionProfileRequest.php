<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

final class UpdateInstitutionProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User
            && ($user->hasRole('platform-admin') || $user->can('institution.profile.manage'));
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'legal_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'trade_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'legal_form' => ['sometimes', 'nullable', 'string', 'max:64'],
            'emf_category' => ['sometimes', 'nullable', 'string', 'max:32'],
            'supervisory_authority' => ['sometimes', 'nullable', 'string', 'max:128'],
            'approval_number' => ['sometimes', 'nullable', 'string', 'max:64'],
            'approval_date' => ['sometimes', 'nullable', 'date'],
            'registration_number' => ['sometimes', 'nullable', 'string', 'max:64'],
            'tax_identification_number' => ['sometimes', 'nullable', 'string', 'max:64'],
            'address_line_1' => ['sometimes', 'nullable', 'string', 'max:255'],
            'address_line_2' => ['sometimes', 'nullable', 'string', 'max:255'],
            'po_box' => ['sometimes', 'nullable', 'string', 'max:64'],
            'city' => ['sometimes', 'nullable', 'string', 'max:128'],
            'region' => ['sometimes', 'nullable', 'string', 'max:128'],
            'country' => ['sometimes', 'nullable', 'string', 'max:128'],
            'phone_number' => ['sometimes', 'nullable', 'string', 'max:32'],
            'fax_number' => ['sometimes', 'nullable', 'string', 'max:32'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'website' => ['sometimes', 'nullable', 'string', 'max:255'],
            // Declared for supervisory filings only; config/money.php remains
            // authoritative for posting and reporting currency.
            'declared_reporting_currency' => ['sometimes', 'nullable', 'string', 'size:3'],
            // Recorded for filings; the accounting calendar stays authoritative.
            'fiscal_year_start_month' => ['sometimes', 'nullable', 'integer', 'between:1,12'],
        ];
    }
}
