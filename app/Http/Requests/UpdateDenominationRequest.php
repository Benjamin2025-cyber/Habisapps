<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Denomination;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateDenominationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $denomination = $this->route('denomination');

        return $user instanceof User
            && $denomination instanceof Denomination
            && $user->can('update', $denomination);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $denomination = $this->route('denomination');
        $currentCurrency = $denomination instanceof Denomination ? $denomination->currency : '';
        $inputCurrency = $this->input('currency', $currentCurrency);
        $currency = is_string($inputCurrency) ? strtoupper($inputCurrency) : $currentCurrency;
        $ignoreId = $denomination instanceof Denomination ? $denomination->id : null;

        return [
            'code' => [
                'sometimes',
                'string',
                'max:32',
                Rule::unique('denominations', 'code')->where('currency', $currency)->ignore($ignoreId),
            ],
            'label' => ['sometimes', 'string', 'max:64'],
            'value_minor' => [
                'sometimes',
                'integer',
                'min:1',
                Rule::unique('denominations', 'value_minor')->where('currency', $currency)->ignore($ignoreId),
            ],
            'currency' => ['sometimes', 'string', 'size:3'],
            'type' => ['sometimes', 'string', Rule::in([Denomination::TYPE_BANKNOTE, Denomination::TYPE_COIN])],
            'status' => ['sometimes', 'string', Rule::in([Denomination::STATUS_ACTIVE, Denomination::STATUS_INACTIVE])],
        ];
    }
}
