<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\File;

final class StoreDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('documents.create') === true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'file' => ['required', File::types(['pdf', 'jpg', 'jpeg', 'png'])->max(10 * 1024)],
            'category' => ['required', 'string', 'max:64'],
            'title' => ['required', 'string', 'max:255'],
            'metadata' => ['sometimes', 'array'],
            'metadata.*' => ['nullable', 'string', 'max:255'],
        ];
    }
}
