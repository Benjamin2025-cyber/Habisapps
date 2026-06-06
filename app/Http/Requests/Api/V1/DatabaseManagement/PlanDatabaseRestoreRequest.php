<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\DatabaseManagement;

use App\Models\DatabaseRestoreOperation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class PlanDatabaseRestoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('system.database.restore.plan') === true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'backup_public_id' => ['required', 'string', 'exists:database_backups,public_id'],
            'target' => ['required', 'string', Rule::in([
                DatabaseRestoreOperation::TARGET_SAME_DATABASE,
                DatabaseRestoreOperation::TARGET_STAGING_DATABASE,
                DatabaseRestoreOperation::TARGET_EXTERNAL_DATABASE,
            ])],
            'mode' => ['required', 'string', Rule::in([
                DatabaseRestoreOperation::MODE_DRY_RUN,
                DatabaseRestoreOperation::MODE_REPLACE,
                DatabaseRestoreOperation::MODE_VERIFY_ONLY,
            ])],
            // Required only when config demands it; validated in the workflow.
            'confirmation_phrase' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}
