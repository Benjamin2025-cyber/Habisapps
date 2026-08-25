<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesDenominationFaceValue;
use App\Models\Denomination;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreDenominationRequest extends FormRequest
{
    use ValidatesDenominationFaceValue;

    public function withValidator(Validator $validator): void
    {
        $this->validateFaceValueAgainstCode($validator);
    }

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
        $inputType = $this->input('type');
        $type = is_string($inputType) ? $inputType : '';

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
                // Scoped by type: the 500 F circulates as both a note and a
                // coin, and a teller must be able to count them separately.
                Rule::unique('denominations', 'value_minor')
                    ->where('currency', $currency)
                    ->where('type', $type),
            ],
            'currency' => ['required', 'string', 'size:3'],
            'type' => ['required', 'string', Rule::in([Denomination::TYPE_BANKNOTE, Denomination::TYPE_COIN])],
            'status' => ['sometimes', 'string', Rule::in([Denomination::STATUS_ACTIVE, Denomination::STATUS_INACTIVE])],
        ];
    }
}
