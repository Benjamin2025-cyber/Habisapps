<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds standalone identity fields to client_guarantors (GHI-010B) for
 * non-client guarantors that have no linked client fiche. All columns are
 * nullable so existing slim guarantor records keep working; when a linked
 * client is present that client stays authoritative for identity.
 *
 * `guarantor_identity_document_number` is a text column because it is stored
 * encrypted (cast at the model), mirroring the proxy ID number.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_guarantors', function (Blueprint $table): void {
            $table->string('guarantor_civility', 32)->nullable()->after('guarantor_full_name');
            $table->string('guarantor_first_name', 128)->nullable()->after('guarantor_civility');
            $table->string('guarantor_last_name', 128)->nullable()->after('guarantor_first_name');
            $table->string('guarantor_middle_name', 128)->nullable()->after('guarantor_last_name');
            $table->date('guarantor_date_of_birth')->nullable()->after('guarantor_middle_name');
            $table->string('guarantor_place_of_birth', 255)->nullable()->after('guarantor_date_of_birth');
            $table->text('guarantor_identity_document_number')->nullable()->after('guarantor_place_of_birth');
            $table->date('guarantor_identity_issued_on')->nullable()->after('guarantor_identity_document_number');
            $table->string('guarantor_identity_issued_at', 255)->nullable()->after('guarantor_identity_issued_on');
            $table->string('guarantor_father_name', 128)->nullable()->after('guarantor_identity_issued_at');
            $table->string('guarantor_mother_name', 128)->nullable()->after('guarantor_father_name');
            $table->string('guarantor_profession', 128)->nullable()->after('guarantor_mother_name');
            $table->string('guarantor_address_line_1', 255)->nullable()->after('guarantor_profession');
            $table->string('guarantor_address_line_2', 255)->nullable()->after('guarantor_address_line_1');
            $table->string('guarantor_business_address_line_1', 255)->nullable()->after('guarantor_address_line_2');
            $table->string('guarantor_business_address_line_2', 255)->nullable()->after('guarantor_business_address_line_1');
        });
    }

    public function down(): void
    {
        Schema::table('client_guarantors', function (Blueprint $table): void {
            $table->dropColumn([
                'guarantor_civility',
                'guarantor_first_name',
                'guarantor_last_name',
                'guarantor_middle_name',
                'guarantor_date_of_birth',
                'guarantor_place_of_birth',
                'guarantor_identity_document_number',
                'guarantor_identity_issued_on',
                'guarantor_identity_issued_at',
                'guarantor_father_name',
                'guarantor_mother_name',
                'guarantor_profession',
                'guarantor_address_line_1',
                'guarantor_address_line_2',
                'guarantor_business_address_line_1',
                'guarantor_business_address_line_2',
            ]);
        });
    }
};
