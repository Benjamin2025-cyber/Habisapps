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
        DB::table('currencies')->insertOrIgnore([
            'code' => 'XAF',
            'name' => 'Franc CFA',
            'minor_unit' => 0,
            'is_base_currency' => true,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::statement(<<<'SQL'
ALTER TABLE currencies ADD CONSTRAINT currencies_status_valid
  CHECK (status IN ('active', 'inactive', 'archived'))
SQL);
        DB::statement(<<<'SQL'
ALTER TABLE currencies ADD CONSTRAINT currencies_minor_unit_valid
  CHECK (minor_unit BETWEEN 0 AND 8)
SQL);

        Schema::create('fx_authorizations', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('agency_id')->nullable()->constrained('agencies')->restrictOnDelete();
            $table->string('authorization_reference', 191);
            $table->string('authorization_type', 32)->index();
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->string('status', 32)->default('active')->index();
            $table->boolean('supports_purchase')->default(true);
            $table->boolean('supports_sale')->default(true);
            $table->json('metadata')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        DB::statement(<<<'SQL'
ALTER TABLE fx_authorizations ADD CONSTRAINT fx_authorizations_type_valid
  CHECK (authorization_type IN ('credit_institution', 'emf', 'postal_administration', 'dedicated_bureau', 'sub_delegate'))
SQL);
        DB::statement(<<<'SQL'
ALTER TABLE fx_authorizations ADD CONSTRAINT fx_authorizations_status_valid
  CHECK (status IN ('active', 'suspended', 'revoked'))
SQL);
        DB::statement(<<<'SQL'
ALTER TABLE fx_authorizations ADD CONSTRAINT fx_authorizations_dates_valid
  CHECK (effective_to IS NULL OR effective_to >= effective_from)
SQL);

        Schema::table('exchange_rates', function (Blueprint $table): void {
            $table->foreignId('approved_by_user_id')->nullable()->after('created_by_user_id')->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable()->after('approved_by_user_id');
            $table->date('effective_to')->nullable()->after('effective_on');
        });

        DB::statement(<<<'SQL'
ALTER TABLE exchange_rates ADD CONSTRAINT exchange_rates_status_valid
  CHECK (status IN ('draft', 'active', 'superseded', 'rejected'))
SQL);
        DB::statement(<<<'SQL'
ALTER TABLE exchange_rates ADD CONSTRAINT exchange_rates_pair_scope_valid
  CHECK (base_currency = 'XAF' AND quote_currency <> 'XAF')
SQL);

        Schema::table('till_currency_balances', function (Blueprint $table): void {
            $table->index(['currency', 'current_balance_minor'], 'idx_till_currency_balance_currency_amount');
        });

        Schema::table('fx_transactions', function (Blueprint $table): void {
            $table->string('slip_number', 64)->nullable()->unique()->after('transaction_number');
            $table->string('register_number', 64)->nullable()->unique()->after('slip_number');
            $table->string('client_identity_type', 64)->nullable()->after('client_identity_number');
            $table->string('client_identity_issuing_country', 2)->nullable()->after('client_identity_type');
        });

        DB::statement(<<<'SQL'
ALTER TABLE fx_transactions ADD CONSTRAINT fx_transactions_direction_valid
  CHECK (direction IN ('buy_foreign_currency', 'sell_foreign_currency'))
SQL);
        DB::statement(<<<'SQL'
ALTER TABLE fx_transactions ADD CONSTRAINT fx_transactions_status_valid
  CHECK (status IN ('posted', 'reversed'))
SQL);

        Schema::create('fx_reconciliations', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('till_id')->constrained('tills')->cascadeOnDelete();
            $table->foreignId('agency_id')->constrained('agencies')->restrictOnDelete();
            $table->date('business_date');
            $table->string('currency', 3);
            $table->bigInteger('counted_minor');
            $table->bigInteger('theoretical_minor');
            $table->bigInteger('variance_minor');
            $table->string('status', 32)->default('open')->index();
            $table->text('notes')->nullable();
            $table->foreignId('closed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->unique(['till_id', 'business_date', 'currency'], 'uniq_fx_reco_axis');
        });

        DB::statement(<<<'SQL'
ALTER TABLE fx_reconciliations ADD CONSTRAINT fx_reconciliations_status_valid
  CHECK (status IN ('open', 'closed', 'variance_blocked'))
SQL);
        DB::statement(<<<'SQL'
ALTER TABLE fx_reconciliations ADD CONSTRAINT fx_reconciliations_counted_non_negative
  CHECK (counted_minor >= 0 AND theoretical_minor >= 0)
SQL);

        Schema::table('fx_stock_movements', function (Blueprint $table): void {
            $table->foreignId('requested_by_user_id')->nullable()->after('journal_entry_id')->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by_user_id')->nullable()->after('requested_by_user_id')->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable()->after('approved_by_user_id');
        });

        DB::statement(<<<'SQL'
ALTER TABLE fx_stock_movements ADD CONSTRAINT fx_stock_movements_type_valid
  CHECK (movement_type IN ('partner_replenishment', 'partner_sale', 'adjustment_correction'))
SQL);
        DB::statement(<<<'SQL'
ALTER TABLE fx_stock_movements ADD CONSTRAINT fx_stock_movements_status_valid
  CHECK (status IN ('pending', 'posted', 'rejected'))
SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE fx_stock_movements DROP CONSTRAINT IF EXISTS fx_stock_movements_status_valid');
        DB::statement('ALTER TABLE fx_stock_movements DROP CONSTRAINT IF EXISTS fx_stock_movements_type_valid');
        Schema::table('fx_stock_movements', function (Blueprint $table): void {
            $table->dropColumn(['requested_by_user_id', 'approved_by_user_id', 'approved_at']);
        });

        DB::statement('ALTER TABLE fx_reconciliations DROP CONSTRAINT IF EXISTS fx_reconciliations_counted_non_negative');
        DB::statement('ALTER TABLE fx_reconciliations DROP CONSTRAINT IF EXISTS fx_reconciliations_status_valid');
        Schema::dropIfExists('fx_reconciliations');

        DB::statement('ALTER TABLE fx_transactions DROP CONSTRAINT IF EXISTS fx_transactions_status_valid');
        DB::statement('ALTER TABLE fx_transactions DROP CONSTRAINT IF EXISTS fx_transactions_direction_valid');
        Schema::table('fx_transactions', function (Blueprint $table): void {
            $table->dropColumn(['slip_number', 'register_number', 'client_identity_type', 'client_identity_issuing_country']);
        });

        Schema::table('till_currency_balances', function (Blueprint $table): void {
            $table->dropIndex('idx_till_currency_balance_currency_amount');
        });

        DB::statement('ALTER TABLE exchange_rates DROP CONSTRAINT IF EXISTS exchange_rates_pair_scope_valid');
        DB::statement('ALTER TABLE exchange_rates DROP CONSTRAINT IF EXISTS exchange_rates_status_valid');
        Schema::table('exchange_rates', function (Blueprint $table): void {
            $table->dropColumn(['approved_by_user_id', 'approved_at', 'effective_to']);
        });

        DB::statement('ALTER TABLE fx_authorizations DROP CONSTRAINT IF EXISTS fx_authorizations_dates_valid');
        DB::statement('ALTER TABLE fx_authorizations DROP CONSTRAINT IF EXISTS fx_authorizations_status_valid');
        DB::statement('ALTER TABLE fx_authorizations DROP CONSTRAINT IF EXISTS fx_authorizations_type_valid');
        Schema::dropIfExists('fx_authorizations');

        DB::statement('ALTER TABLE currencies DROP CONSTRAINT IF EXISTS currencies_minor_unit_valid');
        DB::statement('ALTER TABLE currencies DROP CONSTRAINT IF EXISTS currencies_status_valid');
    }
};
