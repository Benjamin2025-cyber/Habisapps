<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\DatabaseManagement;

use App\Models\DatabaseBackup;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class IndexDatabaseBackupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('system.database.view') === true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'status' => ['sometimes', 'string', Rule::in([
                DatabaseBackup::STATUS_PENDING,
                DatabaseBackup::STATUS_RUNNING,
                DatabaseBackup::STATUS_COMPLETED,
                DatabaseBackup::STATUS_FAILED,
                DatabaseBackup::STATUS_VERIFIED,
                DatabaseBackup::STATUS_DELETED,
            ])],
            'search' => ['sometimes', 'nullable', 'string', 'max:128'],
            'date_from' => ['sometimes', 'nullable', 'date'],
            'date_to' => ['sometimes', 'nullable', 'date'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
