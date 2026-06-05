<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Extend guarantor and proxy KYC evidence to the same typed recto/verso
 * identity-document contract already used for client identity documents
 * (API-ISSUE-004).
 *
 * Columns are added nullable so existing records remain readable; the typed
 * document-type and two-face completeness rules are enforced at verification
 * time and on new submissions, not retroactively.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_guarantors', function (Blueprint $table): void {
            $table->string('document_type', 64)->nullable()->after('relationship_type');
            $table->foreignId('back_document_id')
                ->nullable()
                ->after('document_id')
                ->constrained('documents')
                ->nullOnDelete();
        });

        Schema::table('client_proxies', function (Blueprint $table): void {
            $table->foreignId('back_document_id')
                ->nullable()
                ->after('document_id')
                ->constrained('documents')
                ->nullOnDelete();
        });

        // Agency-scoped composite FKs mirror the existing document_id scoping so
        // a back face cannot reference a document from another agency.
        DB::statement('ALTER TABLE client_guarantors ADD CONSTRAINT client_guarantors_back_document_agency_foreign FOREIGN KEY (back_document_id, agency_id) REFERENCES documents (id, agency_id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE client_proxies ADD CONSTRAINT client_proxies_back_document_agency_foreign FOREIGN KEY (back_document_id, agency_id) REFERENCES documents (id, agency_id) ON DELETE RESTRICT');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE client_guarantors DROP CONSTRAINT IF EXISTS client_guarantors_back_document_agency_foreign');
        DB::statement('ALTER TABLE client_proxies DROP CONSTRAINT IF EXISTS client_proxies_back_document_agency_foreign');

        Schema::table('client_guarantors', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('back_document_id');
            $table->dropColumn('document_type');
        });

        Schema::table('client_proxies', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('back_document_id');
        });
    }
};
