<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Agency;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Creates the institution's first agency, so a fresh install has somewhere for
 * the chart of accounts to hang: `PcemfChartSeeder` puts every postable account
 * under an agency, and with none it can only create the institution-level
 * grouping accounts.
 *
 * Opt-in, like `BootstrapAdminSeeder`, and deliberately without a default code.
 * An agency's `code` is fixed at creation — `AgencyWorkflow::update()` accepts
 * name, city, address and every other descriptive field, but not the code — so
 * a placeholder would leave the institution with an identifier it cannot correct
 * and that already has a chart of accounts hanging off it. The name is editable,
 * so only the code has to be decided up front.
 *
 *   SEED_DEFAULT_AGENCY=true \
 *   SEED_DEFAULT_AGENCY_CODE=001 \
 *   SEED_DEFAULT_AGENCY_NAME="Siège" \
 *   php artisan db:seed --class=DefaultAgencySeeder
 */
final class DefaultAgencySeeder extends Seeder
{
    public function run(): void
    {
        if (! (bool) config('security.default_agency.enabled', false)) {
            $this->command->info('Default agency seeding is disabled (set SEED_DEFAULT_AGENCY=true to enable).');

            return;
        }

        $code = $this->stringConfig('security.default_agency.code');
        $name = $this->stringConfig('security.default_agency.name');
        if ($code === '') {
            throw new InvalidArgumentException('SEED_DEFAULT_AGENCY_CODE is required when SEED_DEFAULT_AGENCY is enabled. It cannot be changed later.');
        }
        if ($name === '') {
            throw new InvalidArgumentException('SEED_DEFAULT_AGENCY_NAME is required when SEED_DEFAULT_AGENCY is enabled.');
        }

        // Only ever bootstraps an empty install: once any agency exists, the
        // institution is managing its own network and this must not add to it.
        if (DB::table('agencies')->exists()) {
            $this->command->info('An agency already exists: leaving the network as it is.');

            return;
        }

        $city = $this->stringConfig('security.default_agency.city');
        $agency = Agency::query()->create([
            'public_id' => (string) Str::ulid(),
            'code' => $code,
            'name' => $name,
            'city' => $city === '' ? null : $city,
            'status' => 'active',
        ]);

        $this->command->info(sprintf('Agency created: %s — %s (%s).', $agency->code, $agency->name, $agency->public_id));
        $this->command->warn('The agency code is fixed; the name and address can be corrected in Référentiel › Agence.');
    }

    private function stringConfig(string $key): string
    {
        $value = config($key);

        return is_string($value) ? trim($value) : '';
    }
}
