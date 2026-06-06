<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the PDF mandate-screen personal identity fields to client_proxies
 * (GHI-010C). All columns are nullable so existing proxy mandates keep working.
 * The proxy ID document number stays in its existing encrypted column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_proxies', function (Blueprint $table): void {
            $table->string('proxy_first_name', 128)->nullable()->after('proxy_full_name');
            $table->string('proxy_last_name', 128)->nullable()->after('proxy_first_name');
            $table->string('proxy_middle_name', 128)->nullable()->after('proxy_last_name');
            $table->date('proxy_date_of_birth')->nullable()->after('proxy_middle_name');
            $table->string('proxy_place_of_birth', 255)->nullable()->after('proxy_date_of_birth');
            $table->date('proxy_identity_issued_on')->nullable()->after('proxy_id_document_number');
            $table->string('proxy_identity_issued_at', 255)->nullable()->after('proxy_identity_issued_on');
            $table->string('proxy_father_name', 128)->nullable()->after('proxy_identity_issued_at');
            $table->string('proxy_mother_name', 128)->nullable()->after('proxy_father_name');
            $table->string('proxy_address_line_1', 255)->nullable()->after('proxy_mother_name');
            $table->string('proxy_address_line_2', 255)->nullable()->after('proxy_address_line_1');
            $table->string('proxy_business_address_line_1', 255)->nullable()->after('proxy_address_line_2');
            $table->string('proxy_business_address_line_2', 255)->nullable()->after('proxy_business_address_line_1');
        });
    }

    public function down(): void
    {
        Schema::table('client_proxies', function (Blueprint $table): void {
            $table->dropColumn([
                'proxy_first_name',
                'proxy_last_name',
                'proxy_middle_name',
                'proxy_date_of_birth',
                'proxy_place_of_birth',
                'proxy_identity_issued_on',
                'proxy_identity_issued_at',
                'proxy_father_name',
                'proxy_mother_name',
                'proxy_address_line_1',
                'proxy_address_line_2',
                'proxy_business_address_line_1',
                'proxy_business_address_line_2',
            ]);
        });
    }
};
