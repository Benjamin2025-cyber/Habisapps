<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
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
            'file' => [
                'required',
                File::types(['pdf', 'jpg', 'jpeg', 'png'])->max(10 * 1024),
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (! $value instanceof UploadedFile) {
                        return;
                    }

                    if (! $this->hasSupportedBinarySignature($value)) {
                        $fail('The file must be a file of type: pdf, jpg, jpeg, png.');
                    }
                },
            ],
            'agency_public_id' => ['sometimes', 'nullable', 'string', 'exists:agencies,public_id'],
            'category' => ['required', 'string', 'max:64'],
            'title' => ['required', 'string', 'max:255'],
            'metadata' => ['sometimes', 'array'],
            'metadata.*' => ['nullable', 'string', 'max:255'],
        ];
    }

    private function hasSupportedBinarySignature(UploadedFile $file): bool
    {
        $realPath = $file->getRealPath();
        if (! is_string($realPath)) {
            return false;
        }

        $header = file_get_contents($realPath, false, null, 0, 8);
        if (! is_string($header) || $header === '') {
            return false;
        }

        if (str_starts_with($header, '%PDF-')) {
            return true;
        }

        if (str_starts_with($header, "\xFF\xD8\xFF")) {
            return true;
        }

        return $header === "\x89PNG\x0D\x0A\x1A\x0A";
    }
}
