<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_employees', function (Blueprint $table): void {
            $table->string('gender', 32)->nullable()->after('last_name');
            $table->date('birth_date')->nullable()->after('gender');
            $table->string('birth_place', 128)->nullable()->after('birth_date');
            $table->string('portfolio_code', 64)->nullable()->after('service_name');
        });
    }

    public function down(): void
    {
        Schema::table('hr_employees', function (Blueprint $table): void {
            $table->dropColumn([
                'gender',
                'birth_date',
                'birth_place',
                'portfolio_code',
            ]);
        });
    }
};
