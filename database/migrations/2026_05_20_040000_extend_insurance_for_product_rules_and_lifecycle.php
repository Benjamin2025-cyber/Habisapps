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
        // A8: Add product approval_status and business_model columns
        Schema::table('insurance_products', function (Blueprint $table): void {
            $table->string('approval_status', 32)->default('draft')->after('status');
            $table->string('business_model', 64)->nullable()->after('approval_status');
            $table->string('report_category', 64)->nullable()->after('business_model');
            $table->boolean('new_business_enabled')->default(true)->after('report_category');
        });

        // A8: Versioned premium rule configurations per product
        Schema::create('insurance_product_rule_versions', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('insurance_product_id')->constrained('insurance_products')->cascadeOnDelete();
            $table->unsignedInteger('version_number')->default(1);
            $table->string('calculation_type', 64); // percentage, flat_rate, bracketed
            $table->string('base_description', 128)->nullable(); // e.g. insured_amount, sum_assured
            $table->decimal('rate', 12, 6)->nullable();
            $table->bigInteger('fixed_premium_minor')->nullable();
            $table->bigInteger('cap_minor')->nullable();
            $table->bigInteger('floor_minor')->nullable();
            $table->string('frequency', 32)->default('one_time'); // one_time, monthly, quarterly, annual
            $table->string('source_reference', 512)->nullable();
            $table->date('effective_from');
            $table->date('effective_until')->nullable();
            $table->string('status', 32)->default('draft'); // draft, approved, superseded
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            $table->index(['insurance_product_id', 'status']);
            $table->unique(['insurance_product_id', 'version_number'], 'uniq_product_rule_version');
        });

        // A8/A11: Premium split configuration for a rule version
        Schema::create('insurance_product_rule_version_splits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('insurance_product_rule_version_id')
                ->constrained('insurance_product_rule_versions')->cascadeOnDelete();
            $table->string('split_type', 64); // insurer_payable, commission_income, tax_fee, institution_income
            $table->string('calculation_type', 32)->default('percentage'); // percentage, fixed
            $table->decimal('rate', 12, 6)->nullable();
            $table->bigInteger('fixed_minor')->nullable();
            $table->foreignId('ledger_account_id')->nullable()->constrained('ledger_accounts')->restrictOnDelete();
            $table->timestamps();

            $table->unique(
                ['insurance_product_rule_version_id', 'split_type'],
                'uniq_rule_split_type'
            );
        });

        // A8: Link premium assessments to the rule version used
        Schema::table('insurance_premium_assessments', function (Blueprint $table): void {
            $table->foreignId('rule_version_id')
                ->nullable()
                ->after('loan_id')
                ->constrained('insurance_product_rule_versions')->nullOnDelete();
            $table->string('period_key', 128)->nullable()->after('rule_version_id'); // idempotency key for schedules
        });

        // A9: Subscription lifecycle status + rule version snapshot
        Schema::table('insurance_subscriptions', function (Blueprint $table): void {
            $table->string('lifecycle_status', 32)->default('active')->after('status');
            $table->foreignId('rule_version_id')
                ->nullable()
                ->after('lifecycle_status')
                ->constrained('insurance_product_rule_versions')->nullOnDelete();
            $table->date('grace_period_ends_on')->nullable()->after('rule_version_id');
            $table->timestamp('cancelled_at')->nullable()->after('grace_period_ends_on');
        });

        // A9: Recurring premium schedule
        Schema::create('insurance_premium_schedules', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('insurance_subscription_id')
                ->constrained('insurance_subscriptions')->cascadeOnDelete();
            $table->foreignId('rule_version_id')
                ->nullable()
                ->constrained('insurance_product_rule_versions')->nullOnDelete();
            $table->unsignedInteger('period_number');
            $table->date('due_on');
            $table->string('idempotency_key', 128)->unique();
            $table->foreignId('insurance_premium_assessment_id')
                ->nullable()
                ->constrained('insurance_premium_assessments')->nullOnDelete();
            $table->string('status', 32)->default('scheduled'); // scheduled, assessed, paid, cancelled
            $table->timestamps();

            $table->index(['insurance_subscription_id', 'status']);
        });

        // A10: Endorsement change records
        Schema::create('insurance_endorsements', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('insurance_subscription_id')
                ->constrained('insurance_subscriptions')->restrictOnDelete();
            $table->string('endorsement_type', 64); // coverage_amount, beneficiary, dates, other
            $table->json('before_values');
            $table->json('after_values');
            $table->date('effective_on');
            $table->text('reason')->nullable();
            $table->string('status', 32)->default('pending'); // pending, approved, rejected
            $table->foreignId('requested_by_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
        });

        // A10: Subscription cancellation records
        Schema::create('insurance_cancellations', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('insurance_subscription_id')
                ->constrained('insurance_subscriptions')->restrictOnDelete();
            $table->date('effective_on');
            $table->text('reason')->nullable();
            $table->string('refund_treatment', 32)->default('none'); // none, pro_rata, full
            $table->bigInteger('refund_amount_minor')->nullable();
            $table->foreignId('refund_customer_account_id')->nullable()->constrained('customer_accounts')->nullOnDelete();
            $table->foreignId('refund_journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->string('status', 32)->default('pending'); // pending, approved, rejected
            $table->foreignId('requested_by_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
        });

        // A10: Track reversals on premium payments
        Schema::table('insurance_premium_payments', function (Blueprint $table): void {
            $table->timestamp('reversed_at')->nullable()->after('status');
            $table->foreignId('reversal_journal_entry_id')
                ->nullable()
                ->after('reversed_at')
                ->constrained('journal_entries')->nullOnDelete();
        });

        // A11: Snapshot premium collection splits for reconciliation and reporting
        Schema::create('insurance_premium_payment_splits', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('insurance_premium_payment_id')
                ->constrained('insurance_premium_payments')->cascadeOnDelete();
            $table->foreignId('insurance_product_rule_version_split_id')
                ->nullable()
                ->constrained('insurance_product_rule_version_splits')->nullOnDelete();
            $table->string('split_type', 64);
            $table->bigInteger('amount_minor');
            $table->foreignId('ledger_account_id')->constrained('ledger_accounts')->restrictOnDelete();
            $table->timestamps();

            $table->unique(
                ['insurance_premium_payment_id', 'split_type'],
                'uniq_premium_payment_split_type'
            );
        });

        // A10/A12: Track reversals on claims
        Schema::table('insurance_claims', function (Blueprint $table): void {
            $table->timestamp('evidence_complete_at')->nullable()->after('settled_at');
            $table->timestamp('reversal_at')->nullable()->after('evidence_complete_at');
            $table->foreignId('reversal_journal_entry_id')
                ->nullable()
                ->after('reversal_at')
                ->constrained('journal_entries')->nullOnDelete();
        });

        // A11: Remittance batches (group premium payments owed to insurer partners)
        Schema::create('insurance_remittance_batches', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('insurance_partner_id')
                ->constrained('insurance_partners')->restrictOnDelete();
            $table->foreignId('agency_id')->constrained('agencies')->restrictOnDelete();
            $table->date('period_from');
            $table->date('period_to');
            $table->string('currency', 3)->default('XAF');
            $table->bigInteger('total_minor')->default(0);
            $table->string('status', 32)->default('draft'); // draft, approved, posted
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('journal_entry_id')
                ->nullable()
                ->constrained('journal_entries')->nullOnDelete();
            $table->timestamps();
        });

        // A11: Individual remittance line items
        Schema::create('insurance_remittance_items', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('insurance_remittance_batch_id')
                ->constrained('insurance_remittance_batches')->cascadeOnDelete();
            $table->foreignId('insurance_premium_payment_id')
                ->constrained('insurance_premium_payments')->restrictOnDelete();
            $table->foreignId('insurance_product_id')
                ->constrained('insurance_products')->restrictOnDelete();
            $table->string('split_type', 64);
            $table->bigInteger('amount_minor');
            $table->foreignId('ledger_account_id')->constrained('ledger_accounts')->restrictOnDelete();
            $table->timestamps();

            $table->unique(
                ['insurance_remittance_batch_id', 'insurance_premium_payment_id', 'split_type'],
                'uniq_remittance_payment_split'
            );
        });

        // A11: Mark payments as remitted
        Schema::table('insurance_premium_payments', function (Blueprint $table): void {
            $table->timestamp('remitted_at')->nullable()->after('reversal_journal_entry_id');
            $table->foreignId('remittance_batch_item_id')
                ->nullable()
                ->after('remitted_at')
                ->constrained('insurance_remittance_items')->nullOnDelete();
        });

        // A12: Required evidence configuration per product/claim type
        Schema::create('insurance_claim_evidence_configs', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('insurance_product_id')
                ->constrained('insurance_products')->cascadeOnDelete();
            $table->string('claim_type', 64);
            $table->string('document_type', 64);
            $table->boolean('is_required')->default(true);
            $table->text('description')->nullable();
            $table->timestamps();

            $table->unique(
                ['insurance_product_id', 'claim_type', 'document_type'],
                'uniq_evidence_config'
            );
        });

        // A13: Export audit records
        Schema::create('insurance_export_records', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('export_type', 64); // subscriptions, premiums, claims, commissions, remittances
            $table->foreignId('agency_id')->nullable()->constrained('agencies')->nullOnDelete();
            $table->foreignId('generated_by_user_id')->constrained('users')->restrictOnDelete();
            $table->json('filters')->nullable();
            $table->string('checksum', 64)->nullable();
            $table->string('source_query_version', 64);
            $table->unsignedInteger('record_count')->default(0);
            $table->timestamps();
        });

        // CHECK constraints
        DB::statement(
            'ALTER TABLE insurance_product_rule_versions ADD CONSTRAINT insurance_rule_versions_rate_or_fixed_set '.
            'CHECK (rate IS NOT NULL OR fixed_premium_minor IS NOT NULL)'
        );
        DB::statement(
            'ALTER TABLE insurance_remittance_items ADD CONSTRAINT insurance_remittance_items_amount_positive '.
            'CHECK (amount_minor > 0)'
        );
        DB::statement(
            'ALTER TABLE insurance_premium_payment_splits ADD CONSTRAINT insurance_premium_payment_splits_amount_positive '.
            'CHECK (amount_minor > 0)'
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE insurance_product_rule_versions DROP CONSTRAINT IF EXISTS insurance_rule_versions_rate_or_fixed_set');
        DB::statement('ALTER TABLE insurance_remittance_items DROP CONSTRAINT IF EXISTS insurance_remittance_items_amount_positive');
        DB::statement('ALTER TABLE insurance_premium_payment_splits DROP CONSTRAINT IF EXISTS insurance_premium_payment_splits_amount_positive');

        Schema::table('insurance_premium_payments', function (Blueprint $table): void {
            $table->dropForeign(['remittance_batch_item_id']);
            $table->dropColumn(['remitted_at', 'remittance_batch_item_id', 'reversed_at', 'reversal_journal_entry_id']);
        });

        Schema::dropIfExists('insurance_export_records');
        Schema::dropIfExists('insurance_claim_evidence_configs');
        Schema::dropIfExists('insurance_remittance_items');
        Schema::dropIfExists('insurance_remittance_batches');
        Schema::dropIfExists('insurance_premium_payment_splits');
        Schema::dropIfExists('insurance_cancellations');
        Schema::dropIfExists('insurance_endorsements');

        Schema::table('insurance_claims', function (Blueprint $table): void {
            $table->dropForeign(['reversal_journal_entry_id']);
            $table->dropColumn(['evidence_complete_at', 'reversal_at', 'reversal_journal_entry_id']);
        });

        Schema::table('insurance_premium_schedules', function (Blueprint $table): void {}); // drop whole table
        Schema::dropIfExists('insurance_premium_schedules');

        Schema::table('insurance_subscriptions', function (Blueprint $table): void {
            $table->dropForeign(['rule_version_id']);
            $table->dropColumn(['lifecycle_status', 'rule_version_id', 'grace_period_ends_on', 'cancelled_at']);
        });

        Schema::table('insurance_premium_assessments', function (Blueprint $table): void {
            $table->dropForeign(['rule_version_id']);
            $table->dropColumn(['rule_version_id', 'period_key']);
        });

        Schema::dropIfExists('insurance_product_rule_version_splits');
        Schema::dropIfExists('insurance_product_rule_versions');

        Schema::table('insurance_products', function (Blueprint $table): void {
            $table->dropColumn(['approval_status', 'business_model', 'report_category', 'new_business_enabled']);
        });
    }
};
