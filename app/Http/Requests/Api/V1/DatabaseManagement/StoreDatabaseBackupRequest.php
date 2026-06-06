<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\DatabaseManagement;

use Illuminate\Foundation\Http\FormRequest;

final class StoreDatabaseBackupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('system.database.backup.create') === true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            // Operators may attach a short, non-secret note for the inventory.
            'note' => ['sometimes', 'nullable', 'string', 'max:255', 'not_regex:/[<>]/'],
        ];
    }
}
