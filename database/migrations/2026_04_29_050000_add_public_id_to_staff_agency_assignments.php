<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('staff_agency_assignments', 'public_id')) {
            Schema::table('staff_agency_assignments', function (Blueprint $table): void {
                $table->ulid('public_id')->nullable()->after('id')->unique();
            });

            DB::table('staff_agency_assignments')
                ->select('id')
                ->orderBy('id')
                ->chunkById(500, function ($assignments): void {
                    foreach ($assignments as $assignment) {
                        DB::table('staff_agency_assignments')
                            ->where('id', $assignment->id)
                            ->update(['public_id' => (string) Str::ulid()]);
                    }
                });

            DB::statement('ALTER TABLE staff_agency_assignments ALTER COLUMN public_id SET NOT NULL');
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('staff_agency_assignments', 'public_id')) {
            Schema::table('staff_agency_assignments', function (Blueprint $table): void {
                $table->dropUnique('staff_agency_assignments_public_id_unique');
                $table->dropColumn('public_id');
            });
        }
    }
};
