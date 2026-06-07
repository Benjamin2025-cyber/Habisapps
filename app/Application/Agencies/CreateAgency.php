<?php

declare(strict_types=1);

namespace App\Application\Agencies;

use App\Models\Agency;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CreateAgency
{
    /**
     * @param  array<string, mixed>  $validated
     */
    public function execute(array $validated): Agency
    {
        return DB::transaction(function () use ($validated): Agency {
            /** @var Agency $agency */
            $agency = Agency::query()->create([
                'code' => $validated['code'],
                'name' => $validated['name'],
                'region' => $validated['region'] ?? null,
                'city' => $validated['city'] ?? null,
                'branch_name' => $validated['branch_name'] ?? null,
                'branch_type' => $validated['branch_type'] ?? null,
                'phone_number' => $validated['phone_number'] ?? null,
                'fax_number' => $validated['fax_number'] ?? null,
                'email' => $validated['email'] ?? null,
                'address_line_1' => $validated['address_line_1'] ?? null,
                'address_line_2' => $validated['address_line_2'] ?? null,
                'po_box' => $validated['po_box'] ?? null,
                'geographic_description' => $validated['geographic_description'] ?? null,
                'creation_date' => $validated['creation_date'] ?? null,
                'status' => $validated['status'] ?? Agency::STATUS_ACTIVE,
            ]);

            if (isset($validated['manager_public_id'])) {
                $manager = User::query()->where('public_id', $validated['manager_public_id'])->first();
                if ($manager === null) {
                    throw ValidationException::withMessages(['manager_public_id' => [__('domain.agency_selected_manager_invalid')]]);
                }

                app(AssignAgencyManager::class)->execute($agency, $manager, 'agency-manager');
            }

            return $agency->refresh()->loadMissing('manager');
        });
    }
}
