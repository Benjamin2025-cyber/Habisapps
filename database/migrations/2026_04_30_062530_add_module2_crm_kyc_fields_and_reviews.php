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
        Schema::table('clients', function (Blueprint $table): void {
            $table->foreignId('prospector_id')->nullable()->after('agency_id')->constrained('users')->nullOnDelete();
            $table->string('collection_type', 64)->nullable()->after('employer_name');
            $table->string('collection_frequency', 32)->nullable()->after('collection_type');
            $table->decimal('collection_target_amount', 18, 2)->nullable()->after('collection_frequency');
            $table->foreignId('collection_agent_id')->nullable()->after('collection_target_amount')->constrained('users')->nullOnDelete();
            $table->timestamp('kyc_submitted_at')->nullable()->after('onboarded_on');
            $table->timestamp('kyc_verified_at')->nullable()->after('kyc_submitted_at');
            $table->foreignId('kyc_verified_by_user_id')->nullable()->after('kyc_verified_at')->constrained('users')->nullOnDelete();
            $table->timestamp('kyc_rejected_at')->nullable()->after('kyc_verified_by_user_id');
            $table->text('kyc_rejection_reason')->nullable()->after('kyc_rejected_at');
            $table->timestamp('kyc_suspended_at')->nullable()->after('kyc_rejection_reason');
            $table->timestamp('kyc_archived_at')->nullable()->after('kyc_suspended_at');
        });

        Schema::table('client_identity_documents', function (Blueprint $table): void {
            $table->timestamp('submitted_at')->nullable()->after('verification_status');
            $table->timestamp('rejected_at')->nullable()->after('verified_at');
            $table->text('rejection_reason')->nullable()->after('rejected_at');
            $table->foreignId('created_by_user_id')->nullable()->after('document_id')->constrained('users')->nullOnDelete();
            $table->timestamp('archived_at')->nullable()->after('rejection_reason');
        });

        Schema::table('client_guarantors', function (Blueprint $table): void {
            $table->timestamp('submitted_at')->nullable()->after('verification_status');
            $table->timestamp('verified_at')->nullable()->after('submitted_at');
            $table->foreignId('verified_by_user_id')->nullable()->after('verified_at')->constrained('users')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable()->after('verified_by_user_id');
            $table->text('rejection_reason')->nullable()->after('rejected_at');
            $table->foreignId('created_by_user_id')->nullable()->after('document_id')->constrained('users')->nullOnDelete();
            $table->timestamp('archived_at')->nullable()->after('rejection_reason');
        });

        Schema::table('client_proxies', function (Blueprint $table): void {
            $table->string('verification_status', 32)->default('pending')->after('status')->index();
            $table->timestamp('submitted_at')->nullable()->after('verification_status');
            $table->timestamp('verified_at')->nullable()->after('submitted_at');
            $table->foreignId('verified_by_user_id')->nullable()->after('verified_at')->constrained('users')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable()->after('verified_by_user_id');
            $table->text('rejection_reason')->nullable()->after('rejected_at');
            $table->foreignId('created_by_user_id')->nullable()->after('document_id')->constrained('users')->nullOnDelete();
            $table->timestamp('archived_at')->nullable()->after('rejection_reason');
        });

        Schema::create('client_kyc_reviews', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->foreignId('agency_id')->constrained('agencies')->restrictOnDelete();
            $table->string('previous_kyc_status', 32);
            $table->string('new_kyc_status', 32);
            $table->text('reason')->nullable();
            $table->text('comment')->nullable();
            $table->foreignId('acted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['client_id', 'created_at']);
            $table->index(['agency_id', 'created_at']);
            $table->index(['new_kyc_status']);
        });

        DB::statement('ALTER TABLE clients ADD CONSTRAINT clients_collection_target_non_negative CHECK (collection_target_amount IS NULL OR collection_target_amount >= 0)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE clients DROP CONSTRAINT IF EXISTS clients_collection_target_non_negative');

        Schema::dropIfExists('client_kyc_reviews');

        Schema::table('client_proxies', function (Blueprint $table): void {
            $table->dropForeign(['verified_by_user_id']);
            $table->dropForeign(['created_by_user_id']);
            $table->dropColumn([
                'verification_status',
                'submitted_at',
                'verified_at',
                'verified_by_user_id',
                'rejected_at',
                'rejection_reason',
                'created_by_user_id',
                'archived_at',
            ]);
        });

        Schema::table('client_guarantors', function (Blueprint $table): void {
            $table->dropForeign(['verified_by_user_id']);
            $table->dropForeign(['created_by_user_id']);
            $table->dropColumn([
                'submitted_at',
                'verified_at',
                'verified_by_user_id',
                'rejected_at',
                'rejection_reason',
                'created_by_user_id',
                'archived_at',
            ]);
        });

        Schema::table('client_identity_documents', function (Blueprint $table): void {
            $table->dropForeign(['created_by_user_id']);
            $table->dropColumn([
                'submitted_at',
                'rejected_at',
                'rejection_reason',
                'created_by_user_id',
                'archived_at',
            ]);
        });

        Schema::table('clients', function (Blueprint $table): void {
            $table->dropForeign(['prospector_id']);
            $table->dropForeign(['collection_agent_id']);
            $table->dropForeign(['kyc_verified_by_user_id']);
            $table->dropColumn([
                'prospector_id',
                'collection_type',
                'collection_frequency',
                'collection_target_amount',
                'collection_agent_id',
                'kyc_submitted_at',
                'kyc_verified_at',
                'kyc_verified_by_user_id',
                'kyc_rejected_at',
                'kyc_rejection_reason',
                'kyc_suspended_at',
                'kyc_archived_at',
            ]);
        });
    }
};
