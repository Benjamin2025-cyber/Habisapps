<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\AccountingDay;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

final class ReopenAccountingDayRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $accountingDay = $this->route('accountingDay');

        return $user instanceof User
            && $accountingDay instanceof AccountingDay
            && $user->can('reopen', $accountingDay);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:10', 'max:2000'],
        ];
    }
}
