<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\DatabaseManagement;

use Illuminate\Foundation\Http\FormRequest;

final class ExecuteDatabaseRestoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('system.database.restore.execute') === true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            // Step-up re-authentication: the operator must re-enter their
            // password to execute a restore (ADM-DB-007). Verified in the
            // workflow against the authenticated user's hashed password.
            'password' => ['required', 'string'],
        ];
    }
}
