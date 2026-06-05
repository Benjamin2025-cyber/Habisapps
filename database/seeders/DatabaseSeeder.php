<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

final class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(RolesAndPermissionsSeeder::class);

        $this->call(StandardReportDefinitionSeeder::class);

        $this->call(BatchProcedureSeeder::class);

        $this->call(BootstrapAdminSeeder::class);
    }
}
