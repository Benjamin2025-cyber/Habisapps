<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ReserveReferenceNumberRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('references.reserve') === true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $sequences = config('reference_numbers.sequences', []);
        $keys = is_array($sequences) ? array_keys($sequences) : [];

        return [
            'key' => ['required', 'string', Rule::in($keys)],
        ];
    }
}
