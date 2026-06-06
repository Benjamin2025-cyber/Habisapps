<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\DatabaseManagement;

use Illuminate\Foundation\Http\FormRequest;

final class RunRetentionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('system.database.maintenance.manage') === true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            // Dry-run reports deletion candidates without removing anything.
            'dry_run' => ['sometimes', 'boolean'],
        ];
    }
}
