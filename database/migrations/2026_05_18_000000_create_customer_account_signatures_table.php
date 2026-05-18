<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_account_signatures', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('agency_id')->constrained('agencies')->restrictOnDelete();
            $table->foreignId('customer_account_id')->constrained('customer_accounts')->restrictOnDelete();
            $table->foreignId('client_id')->constrained('clients')->restrictOnDelete();
            $table->foreignId('document_id')->unique()->constrained('documents')->restrictOnDelete();
            $table->foreignId('client_proxy_id')->nullable()->constrained('client_proxies')->restrictOnDelete();
            $table->string('signature_type', 32);
            $table->string('signer_name')->nullable();
            $table->string('signer_role', 64)->nullable();
            $table->string('status', 32)->default('active');
            $table->date('captured_on')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->foreignId('verified_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('revoked_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('revocation_reason')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['agency_id', 'status']);
            $table->index(['customer_account_id', 'status'], 'customer_account_signatures_account_status_index');
            $table->index(['client_id', 'status'], 'customer_account_signatures_client_status_index');
            $table->index(['client_proxy_id', 'status'], 'customer_account_signatures_proxy_status_index');
        });

        DB::statement('ALTER TABLE customer_account_signatures ADD CONSTRAINT customer_account_signatures_id_agency_unique UNIQUE (id, agency_id)');
        DB::statement('ALTER TABLE customer_account_signatures ADD CONSTRAINT customer_account_signatures_id_account_unique UNIQUE (id, customer_account_id)');
        DB::statement('ALTER TABLE client_proxies ADD CONSTRAINT client_proxies_id_agency_unique UNIQUE (id, agency_id)');
        DB::statement('ALTER TABLE customer_account_signatures ADD CONSTRAINT customer_account_signatures_account_agency_foreign FOREIGN KEY (customer_account_id, agency_id) REFERENCES customer_accounts (id, agency_id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE customer_account_signatures ADD CONSTRAINT customer_account_signatures_client_agency_foreign FOREIGN KEY (client_id, agency_id) REFERENCES clients (id, agency_id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE customer_account_signatures ADD CONSTRAINT customer_account_signatures_document_agency_foreign FOREIGN KEY (document_id, agency_id) REFERENCES documents (id, agency_id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE customer_account_signatures ADD CONSTRAINT customer_account_signatures_proxy_agency_foreign FOREIGN KEY (client_proxy_id, agency_id) REFERENCES client_proxies (id, agency_id) ON DELETE RESTRICT');
        DB::statement("ALTER TABLE customer_account_signatures ADD CONSTRAINT customer_account_signatures_type_check CHECK (signature_type IN ('primary_holder', 'joint_holder', 'proxy', 'mandate', 'thumbprint'))");
        DB::statement("ALTER TABLE customer_account_signatures ADD CONSTRAINT customer_account_signatures_status_check CHECK (status IN ('active', 'superseded', 'revoked', 'archived'))");
        DB::statement("ALTER TABLE customer_account_signatures ADD CONSTRAINT customer_account_signatures_revoked_fields_check CHECK ((status <> 'revoked' AND revoked_at IS NULL) OR (status = 'revoked' AND revoked_at IS NOT NULL))");
        DB::statement("CREATE UNIQUE INDEX customer_account_signatures_active_primary_unique ON customer_account_signatures (customer_account_id) WHERE status = 'active' AND signature_type = 'primary_holder'");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS customer_account_signatures_active_primary_unique');
        DB::statement('ALTER TABLE customer_account_signatures DROP CONSTRAINT IF EXISTS customer_account_signatures_revoked_fields_check');
        DB::statement('ALTER TABLE customer_account_signatures DROP CONSTRAINT IF EXISTS customer_account_signatures_status_check');
        DB::statement('ALTER TABLE customer_account_signatures DROP CONSTRAINT IF EXISTS customer_account_signatures_type_check');
        DB::statement('ALTER TABLE customer_account_signatures DROP CONSTRAINT IF EXISTS customer_account_signatures_proxy_agency_foreign');
        DB::statement('ALTER TABLE customer_account_signatures DROP CONSTRAINT IF EXISTS customer_account_signatures_document_agency_foreign');
        DB::statement('ALTER TABLE customer_account_signatures DROP CONSTRAINT IF EXISTS customer_account_signatures_client_agency_foreign');
        DB::statement('ALTER TABLE customer_account_signatures DROP CONSTRAINT IF EXISTS customer_account_signatures_account_agency_foreign');
        DB::statement('ALTER TABLE customer_account_signatures DROP CONSTRAINT IF EXISTS customer_account_signatures_id_account_unique');
        DB::statement('ALTER TABLE customer_account_signatures DROP CONSTRAINT IF EXISTS customer_account_signatures_id_agency_unique');

        Schema::dropIfExists('customer_account_signatures');

        DB::statement('ALTER TABLE client_proxies DROP CONSTRAINT IF EXISTS client_proxies_id_agency_unique');
    }
};
