<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Widen the encrypted proxy ID document number column.
     *
     * The column stores a Laravel `encrypted` cast payload, which is
     * substantially larger than the plaintext business value (a typical
     * document number encrypts to a multi-hundred-character base64 blob).
     * The original varchar(128) cannot hold that payload and overflows on
     * insert, so the column is widened to `text`.
     */
    public function up(): void
    {
        Schema::table('client_proxies', function (Blueprint $table) {
            $table->text('proxy_id_document_number')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('client_proxies', function (Blueprint $table) {
            $table->string('proxy_id_document_number', 128)->nullable()->change();
        });
    }
};
