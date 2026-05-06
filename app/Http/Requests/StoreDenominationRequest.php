<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Denomination;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreDenominationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User && $user->can('create', Denomination::class);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $inputCurrency = $this->input('currency');
        $currency = is_string($inputCurrency) ? strtoupper($inputCurrency) : '';

        return [
            'code' => [
                'required',
                'string',
                'max:32',
                Rule::unique('denominations', 'code')->where('currency', $currency),
            ],
            'label' => ['required', 'string', 'max:64'],
            'value_minor' => [
                'required',
                'integer',
                'min:1',
                Rule::unique('denominations', 'value_minor')->where('currency', $currency),
            ],
            'currency' => ['required', 'string', 'size:3'],
            'type' => ['required', 'string', Rule::in([Denomination::TYPE_BANKNOTE, Denomination::TYPE_COIN])],
            'status' => ['sometimes', 'string', Rule::in([Denomination::STATUS_ACTIVE, Denomination::STATUS_INACTIVE])],
        ];
    }
}
