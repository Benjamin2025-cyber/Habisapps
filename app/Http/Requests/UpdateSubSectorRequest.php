<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\SubSector;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

final class UpdateSubSectorRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        $subSector = $this->route('subSector');

        return $user instanceof User
            && $subSector instanceof SubSector
            && $user->can('update', $subSector);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'sector_public_id' => ['sometimes', 'string', 'exists:sectors,public_id'],
            'name' => ['sometimes', 'string', 'max:255'],
            'status' => ['sometimes', 'string'],
        ];
    }
}
