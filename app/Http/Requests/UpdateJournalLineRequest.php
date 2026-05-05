<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\JournalLine;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

final class UpdateJournalLineRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        $journalLine = $this->route('journalLine');

        return $user instanceof User
            && $journalLine instanceof JournalLine
            && $user->can('update', $journalLine);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'debit_minor' => ['sometimes', 'integer', 'min:0'],
            'credit_minor' => ['sometimes', 'integer', 'min:0'],
            'currency' => ['sometimes', 'string', 'size:3', 'uppercase'],
            'line_memo' => ['sometimes', 'nullable', 'string'],
        ];
    }
}
