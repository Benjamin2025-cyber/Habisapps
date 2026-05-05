<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\JournalEntry;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

final class StoreJournalEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User && $user->can('create', JournalEntry::class);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'reference' => ['required', 'string', 'max:64', 'unique:journal_entries,reference'],
            'business_date' => ['required', 'date'],
            'agency_public_id' => ['nullable', 'string', 'exists:agencies,public_id'],
            'source_module' => ['nullable', 'string', 'max:64'],
            'source_type' => ['nullable', 'string', 'max:64'],
            'source_public_id' => ['nullable', 'string', 'max:64'],
            'description' => ['nullable', 'string'],
            'idempotency_key' => ['nullable', 'string', 'max:128', 'unique:journal_entries,idempotency_key'],
        ];
    }
}
