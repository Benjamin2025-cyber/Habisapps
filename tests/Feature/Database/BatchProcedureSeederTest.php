<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use App\Models\BatchProcedure;
use Database\Seeders\BatchProcedureSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class BatchProcedureSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_creates_active_close_control_procedures(): void
    {
        $this->seed(BatchProcedureSeeder::class);

        foreach (['accounting_close_verification', 'cash_close_verification'] as $code) {
            $procedure = BatchProcedure::query()->where('code', $code)->first();
            self::assertInstanceOf(BatchProcedure::class, $procedure);
            self::assertSame(BatchProcedure::STATUS_ACTIVE, $procedure->status);
        }
    }

    public function test_seeded_codes_match_close_workflow_normalization(): void
    {
        $this->seed(BatchProcedureSeeder::class);

        // The close workflow looks procedures up by LOWER(REPLACE(code, '-', '_')).
        foreach (['accounting_close_verification', 'cash_close_verification'] as $code) {
            $found = DB::table('batch_procedures')
                ->where('status', BatchProcedure::STATUS_ACTIVE)
                ->whereRaw('LOWER(REPLACE(code, ?, ?)) = ?', ['-', '_', $code])
                ->exists();
            self::assertTrue($found, "Procedure for {$code} should be resolvable by the close workflow.");
        }
    }

    public function test_seeder_is_idempotent_on_repeated_runs(): void
    {
        $this->seed(BatchProcedureSeeder::class);
        $countAfterFirst = BatchProcedure::query()->getQuery()->count();

        $this->seed(BatchProcedureSeeder::class);
        $this->seed(BatchProcedureSeeder::class);

        self::assertSame($countAfterFirst, BatchProcedure::query()->getQuery()->count());
    }

    public function test_seeder_does_not_duplicate_hyphenated_variants(): void
    {
        // A pre-existing hyphenated variant must be reused, not duplicated.
        BatchProcedure::query()->create([
            'public_id' => (string) Str::ulid(),
            'code' => 'accounting-close-verification',
            'name' => 'Legacy Accounting Close',
            'status' => BatchProcedure::STATUS_ACTIVE,
        ]);

        $this->seed(BatchProcedureSeeder::class);

        $query = BatchProcedure::query();
        $query->getQuery()->whereRaw('LOWER(REPLACE(code, ?, ?)) = ?', ['-', '_', 'accounting_close_verification']);
        $matching = $query->get();

        self::assertCount(1, $matching);
        self::assertSame('Accounting Close Verification', $matching->first()?->name);
    }
}
