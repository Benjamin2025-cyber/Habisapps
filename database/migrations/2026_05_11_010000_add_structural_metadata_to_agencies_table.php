<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agencies', function (Blueprint $table): void {
            $table->string('branch_type', 64)->nullable()->after('branch_name');
            $table->string('po_box', 128)->nullable()->after('address_line_2');
            $table->string('fax_number', 32)->nullable()->after('phone_number');
            $table->text('geographic_description')->nullable()->after('po_box');
        });
    }

    public function down(): void
    {
        Schema::table('agencies', function (Blueprint $table): void {
            $table->dropColumn([
                'branch_type',
                'po_box',
                'fax_number',
                'geographic_description',
            ]);
        });
    }
};
