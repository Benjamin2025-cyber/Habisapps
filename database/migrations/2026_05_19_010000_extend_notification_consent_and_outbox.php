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
        Schema::create('client_notification_consents', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->foreignId('agency_id')->constrained('agencies')->restrictOnDelete();
            $table->string('channel', 32)->index();
            $table->string('category', 64)->index();
            $table->string('language', 8)->default('fr');
            $table->string('status', 16)->default('opted_in')->index();
            $table->timestamp('opted_in_at')->nullable();
            $table->timestamp('opted_out_at')->nullable();
            $table->foreignId('last_changed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['client_id', 'channel', 'category', 'language'], 'uniq_client_consent_axis');
        });

        DB::statement('ALTER TABLE client_notification_consents ADD CONSTRAINT client_notification_consents_status_valid CHECK (status IN (\'opted_in\', \'opted_out\'))');
        DB::statement('ALTER TABLE notification_deliveries ADD CONSTRAINT notification_deliveries_status_valid CHECK (status IN (\'pending\', \'sent\', \'failed\', \'cancelled\', \'permanently_failed\'))');

        Schema::table('notification_templates', function (Blueprint $table): void {
            $table->unsignedInteger('version')->default(1)->after('code');
            $table->string('category', 64)->nullable()->after('channel')->index();
            $table->string('language', 8)->default('fr')->after('category');
            $table->json('variables_allowlist')->nullable()->after('body_template');
            $table->timestamp('effective_from')->nullable()->after('status');
            $table->timestamp('effective_to')->nullable()->after('effective_from');
        });

        DB::statement('ALTER TABLE notification_templates DROP CONSTRAINT IF EXISTS notification_templates_code_unique');
        DB::statement('ALTER TABLE notification_templates ADD CONSTRAINT notification_templates_code_version_unique UNIQUE (code, version)');

        Schema::table('notification_deliveries', function (Blueprint $table): void {
            $table->string('category', 64)->nullable()->after('channel')->index();
            $table->string('idempotency_key', 191)->nullable()->after('category');
        });

        DB::statement('CREATE UNIQUE INDEX notification_deliveries_idempotency_unique ON notification_deliveries (idempotency_key) WHERE idempotency_key IS NOT NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS notification_deliveries_idempotency_unique');
        DB::statement('ALTER TABLE notification_deliveries DROP CONSTRAINT IF EXISTS notification_deliveries_status_valid');

        Schema::table('notification_deliveries', function (Blueprint $table): void {
            $table->dropColumn(['category', 'idempotency_key']);
        });

        DB::statement('ALTER TABLE notification_templates DROP CONSTRAINT IF EXISTS notification_templates_code_version_unique');
        DB::statement('ALTER TABLE notification_templates ADD CONSTRAINT notification_templates_code_unique UNIQUE (code)');

        Schema::table('notification_templates', function (Blueprint $table): void {
            $table->dropColumn(['version', 'category', 'language', 'variables_allowlist', 'effective_from', 'effective_to']);
        });

        DB::statement('ALTER TABLE client_notification_consents DROP CONSTRAINT IF EXISTS client_notification_consents_status_valid');
        Schema::dropIfExists('client_notification_consents');
    }
};
