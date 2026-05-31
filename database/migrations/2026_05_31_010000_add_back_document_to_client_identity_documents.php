<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add a second document link so identity documents that require two faces
     * (e.g. a national ID front and back) can carry both evidence files
     * (FBI-017). `document_id` remains the primary/front face.
     */
    public function up(): void
    {
        Schema::table('client_identity_documents', function (Blueprint $table): void {
            $table->foreignId('back_document_id')
                ->nullable()
                ->after('document_id')
                ->constrained('documents')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('client_identity_documents', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('back_document_id');
        });
    }
};
