<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('insurance_products')) {
            return;
        }

        Schema::table('insurance_products', function (Blueprint $table): void {
            if (! Schema::hasColumn('insurance_products', 'report_category')) {
                $table->string('report_category', 64)->nullable()->after('business_model');
            }

            if (! Schema::hasColumn('insurance_products', 'new_business_enabled')) {
                $table->boolean('new_business_enabled')->default(true)->after('report_category');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('insurance_products')) {
            return;
        }

        Schema::table('insurance_products', function (Blueprint $table): void {
            if (Schema::hasColumn('insurance_products', 'new_business_enabled')) {
                $table->dropColumn('new_business_enabled');
            }

            if (Schema::hasColumn('insurance_products', 'report_category')) {
                $table->dropColumn('report_category');
            }
        });
    }
};
