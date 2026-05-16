<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table): void {
            $table->foreignId('profile_photo_document_id')->nullable()->after('agency_id')->constrained('documents')->nullOnDelete();
            $table->string('father_name', 128)->nullable()->after('middle_name');
            $table->string('mother_name', 128)->nullable()->after('father_name');
            $table->string('home_phone_number', 32)->nullable()->after('phone_number');
            $table->date('business_started_on')->nullable()->after('employer_name');
            $table->date('business_activity_started_on')->nullable()->after('business_started_on');
            $table->string('business_address_line_1')->nullable()->after('business_activity_started_on');
            $table->string('business_address_line_2')->nullable()->after('business_address_line_1');
            $table->string('business_city', 128)->nullable()->after('business_address_line_2');
            $table->string('business_region', 128)->nullable()->after('business_city');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table): void {
            $table->dropForeign(['profile_photo_document_id']);
            $table->dropColumn([
                'profile_photo_document_id',
                'father_name',
                'mother_name',
                'home_phone_number',
                'business_started_on',
                'business_activity_started_on',
                'business_address_line_1',
                'business_address_line_2',
                'business_city',
                'business_region',
            ]);
        });
    }
};
