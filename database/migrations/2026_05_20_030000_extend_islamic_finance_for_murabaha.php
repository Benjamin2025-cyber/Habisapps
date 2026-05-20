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
        DB::statement('ALTER TABLE islamic_financings DROP CONSTRAINT IF EXISTS islamic_financings_client_id_foreign');
        DB::statement('ALTER TABLE islamic_financings ADD CONSTRAINT islamic_financings_client_id_foreign FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE RESTRICT');

        // Product-level Sharia compliance: add islamic_product_id to reviews
        Schema::table('islamic_compliance_reviews', function (Blueprint $table): void {
            $table->foreignId('islamic_product_id')->nullable()->after('id')
                ->constrained('islamic_products')->cascadeOnDelete();
            $table->foreignId('requested_by_user_id')->nullable()->after('checklist')
                ->constrained('users')->nullOnDelete();
            $table->string('status', 32)->default('pending')->index()->after('decision');
        });

        DB::statement(<<<'SQL'
ALTER TABLE islamic_compliance_reviews ADD CONSTRAINT islamic_compliance_reviews_target_check
  CHECK (
    (islamic_product_id IS NOT NULL AND islamic_financing_id IS NULL)
    OR
    (islamic_financing_id IS NOT NULL AND islamic_product_id IS NULL)
  )
SQL);

        DB::statement(<<<'SQL'
ALTER TABLE islamic_compliance_reviews ADD CONSTRAINT islamic_compliance_reviews_status_valid
  CHECK (status IN ('pending', 'approved', 'rejected'))
SQL);

        // Murabaha-specific columns on financings
        Schema::table('islamic_financings', function (Blueprint $table): void {
            $table->bigInteger('purchase_cost_minor')->nullable()->after('financed_amount_minor');
            $table->bigInteger('allowed_costs_minor')->default(0)->after('purchase_cost_minor');
            $table->bigInteger('markup_minor')->default(0)->after('allowed_costs_minor');
            $table->string('supplier_name', 255)->nullable()->after('markup_minor');
            $table->foreignId('approved_by_user_id')->nullable()->after('supplier_name')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable()->after('approved_by_user_id');
            $table->foreignId('journal_entry_id')->nullable()->after('approved_at')
                ->constrained('journal_entries')->nullOnDelete();
        });

        DB::statement(<<<'SQL'
ALTER TABLE islamic_financings ADD CONSTRAINT islamic_financings_murabaha_pricing_valid
  CHECK (
    contract_type <> 'murabaha'
    OR (
      sale_price_minor IS NOT NULL
      AND sale_price_minor = COALESCE(purchase_cost_minor, financed_amount_minor) + COALESCE(allowed_costs_minor, 0) + COALESCE(markup_minor, 0)
    )
  )
SQL);

        DB::statement(<<<'SQL'
ALTER TABLE islamic_financings ADD CONSTRAINT islamic_financings_amounts_non_negative
  CHECK (
    financed_amount_minor > 0
    AND (purchase_cost_minor IS NULL OR purchase_cost_minor > 0)
    AND allowed_costs_minor >= 0
    AND markup_minor >= 0
  )
SQL);

        // Installment schedule for Murabaha receivable
        Schema::create('islamic_financing_installments', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('islamic_financing_id')->constrained('islamic_financings')->cascadeOnDelete();
            $table->unsignedSmallInteger('installment_number');
            $table->date('due_on');
            $table->bigInteger('amount_minor');
            $table->bigInteger('paid_amount_minor')->default(0);
            $table->string('currency', 3)->default('XAF');
            $table->string('status', 32)->default('pending')->index();
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->timestamps();

            $table->unique(['islamic_financing_id', 'installment_number'], 'uniq_islamic_installment_number');
            $table->index(['islamic_financing_id', 'status', 'due_on'], 'idx_islamic_installment_status_due');
        });

        DB::statement(<<<'SQL'
ALTER TABLE islamic_financing_installments ADD CONSTRAINT islamic_financing_installments_amount_positive
  CHECK (amount_minor > 0 AND paid_amount_minor >= 0)
SQL);

        DB::statement(<<<'SQL'
ALTER TABLE islamic_financing_installments ADD CONSTRAINT islamic_financing_installments_status_valid
  CHECK (status IN ('pending', 'paid', 'overdue'))
SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE islamic_financings DROP CONSTRAINT IF EXISTS islamic_financings_client_id_foreign');
        DB::statement('ALTER TABLE islamic_financings ADD CONSTRAINT islamic_financings_client_id_foreign FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE');

        DB::statement('ALTER TABLE islamic_financing_installments DROP CONSTRAINT IF EXISTS islamic_financing_installments_status_valid');
        DB::statement('ALTER TABLE islamic_financing_installments DROP CONSTRAINT IF EXISTS islamic_financing_installments_amount_positive');
        Schema::dropIfExists('islamic_financing_installments');

        DB::statement('ALTER TABLE islamic_financings DROP CONSTRAINT IF EXISTS islamic_financings_amounts_non_negative');
        DB::statement('ALTER TABLE islamic_financings DROP CONSTRAINT IF EXISTS islamic_financings_murabaha_pricing_valid');

        Schema::table('islamic_financings', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('journal_entry_id');
            $table->dropColumn('approved_at');
            $table->dropConstrainedForeignId('approved_by_user_id');
            $table->dropColumn('supplier_name');
            $table->dropColumn('markup_minor');
            $table->dropColumn('allowed_costs_minor');
            $table->dropColumn('purchase_cost_minor');
        });

        DB::statement('ALTER TABLE islamic_compliance_reviews DROP CONSTRAINT IF EXISTS islamic_compliance_reviews_status_valid');
        DB::statement('ALTER TABLE islamic_compliance_reviews DROP CONSTRAINT IF EXISTS islamic_compliance_reviews_target_check');

        Schema::table('islamic_compliance_reviews', function (Blueprint $table): void {
            $table->dropColumn('status');
            $table->dropConstrainedForeignId('requested_by_user_id');
            $table->dropConstrainedForeignId('islamic_product_id');
        });
    }
};
