<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Sector;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

final class UpdateSectorRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        $sector = $this->route('sector');

        return $user instanceof User
            && $sector instanceof Sector
            && $user->can('update', $sector);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'status' => ['sometimes', 'string'],
        ];
    }
}
