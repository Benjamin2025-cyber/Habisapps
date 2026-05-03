<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('journal_lines', function (Blueprint $table) {
            $table->ulid('public_id')->nullable()->after('id')->unique();
        });

        DB::table('journal_lines')
            ->whereNull('public_id')
            ->orderBy('id')
            ->select(['id'])
            ->lazyById()
            ->each(static function (object $line): void {
                DB::table('journal_lines')
                    ->where('id', $line->id)
                    ->update(['public_id' => (string) Str::ulid()]);
            });

        DB::statement('ALTER TABLE journal_lines ALTER COLUMN public_id SET NOT NULL');

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('journal_lines', function (Blueprint $table) {
            $table->dropColumn('public_id');
        });
    }
};
