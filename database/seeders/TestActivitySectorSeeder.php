<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Sector;
use App\Models\SubSector;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * A small activity-classification bench so the loan form's « Secteur d'activité »
 * and « Sous-secteur » dropdowns are not empty during testing.
 *
 * Run explicitly with:
 *   php artisan db:seed --class=TestActivitySectorSeeder
 *
 * This is **test data, never installation data**. A real institution loads its
 * own activity taxonomy (its NAF/ISIC-style référentiel), so DatabaseSeeder must
 * not plant this dummy list on a production install — it would masquerade as the
 * institution's real classification. Gated exactly like TestStaffAccountsSeeder:
 * the same production guard here, and it is only reached through
 * ConsolidatedChartBenchSeeder, which DatabaseSeeder calls under `local` alone.
 *
 * Idempotent: keyed on the sector/sub-sector code, so re-running leaves an
 * institution's own edits to labels and status untouched.
 */
final class TestActivitySectorSeeder extends Seeder
{
    /**
     * @var array<string, array{name: string, sub: array<string, string>}>
     */
    private const array SECTORS = [
        'AGR' => [
            'name' => 'Agriculture, élevage et pêche',
            'sub' => [
                'AGR-CUL' => 'Cultures vivrières',
                'AGR-ELV' => 'Élevage',
                'AGR-PEC' => 'Pêche et aquaculture',
            ],
        ],
        'COM' => [
            'name' => 'Commerce',
            'sub' => [
                'COM-DET' => 'Commerce de détail',
                'COM-GRO' => 'Commerce de gros',
            ],
        ],
        'ART' => [
            'name' => 'Artisanat et production',
            'sub' => [
                'ART-TRA' => 'Transformation agroalimentaire',
                'ART-TEX' => 'Textile et habillement',
            ],
        ],
        'SER' => [
            'name' => 'Services',
            'sub' => [
                'SER-TRA' => 'Transport',
                'SER-RES' => 'Restauration',
            ],
        ],
        'BTP' => [
            'name' => 'Bâtiment et travaux publics',
            'sub' => [
                'BTP-CON' => 'Construction',
            ],
        ],
    ];

    public function run(): void
    {
        if (app()->environment('production') && ! (bool) env('ALLOW_TEST_STAFF_SEEDING', false)) {
            throw new LogicException(
                'Test activity-sector seeding is disabled in production. A production install loads its own '
                .'activity taxonomy; set ALLOW_TEST_STAFF_SEEDING=true only on an intentionally isolated test installation.'
            );
        }

        DB::transaction(function (): void {
            foreach (self::SECTORS as $sectorCode => $definition) {
                $sector = Sector::query()->updateOrCreate(
                    ['code' => $sectorCode],
                    ['name' => $definition['name'], 'status' => Sector::STATUS_ACTIVE],
                );

                foreach ($definition['sub'] as $subCode => $subName) {
                    SubSector::query()->updateOrCreate(
                        ['code' => $subCode],
                        ['sector_id' => $sector->id, 'name' => $subName, 'status' => SubSector::STATUS_ACTIVE],
                    );
                }
            }
        });
    }
}
