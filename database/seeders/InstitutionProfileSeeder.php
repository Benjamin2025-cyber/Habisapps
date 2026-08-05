<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\InstitutionProfile;
use Illuminate\Database\Seeder;

/**
 * Creates the institution profile row so the endpoint and report headers have
 * something to read on a fresh install. The row is left empty on purpose: the
 * legal name and approval identifiers appear on supervisory filings, so they
 * must be entered by the institution rather than guessed from the app name.
 */
final class InstitutionProfileSeeder extends Seeder
{
    public function run(): void
    {
        InstitutionProfile::singleton();
    }
}
