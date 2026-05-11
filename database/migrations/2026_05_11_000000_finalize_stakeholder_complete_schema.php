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
        Schema::create('operation_codes', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('code', 64)->unique();
            $table->string('label');
            $table->string('module', 64)->index();
            $table->string('operation_type', 64)->nullable()->index();
            $table->string('direction', 32)->nullable();
            $table->string('status', 32)->default('active')->index();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('account_products', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('agency_id')->nullable()->constrained('agencies')->nullOnDelete();
            $table->foreignId('ledger_account_id')->nullable()->constrained('ledger_accounts')->nullOnDelete();
            $table->string('code', 64);
            $table->string('name');
            $table->string('account_family', 64)->index();
            $table->bigInteger('minimum_balance_minor')->default(0);
            $table->string('currency', 3)->default('XAF');
            $table->boolean('allows_recovery_debit')->default(false);
            $table->boolean('is_recovery_account')->default(false);
            $table->boolean('is_ordinary_savings')->default(false);
            $table->string('status', 32)->default('active')->index();
            $table->json('rules')->nullable();
            $table->timestamps();

            $table->unique(['agency_id', 'code']);
        });

        Schema::table('customer_accounts', function (Blueprint $table): void {
            $table->foreignId('account_product_id')->nullable()->after('account_type')->constrained('account_products')->nullOnDelete();
            $table->foreignId('manager_user_id')->nullable()->after('agency_id')->constrained('users')->nullOnDelete();
            $table->string('account_title')->nullable()->after('account_number');
            $table->string('currency', 3)->default('XAF')->after('account_title');
            $table->bigInteger('unavailable_amount_minor')->default(0)->after('currency');
            $table->string('signature_path')->nullable()->after('unavailable_amount_minor');
        });

        Schema::table('loan_products', function (Blueprint $table): void {
            $table->foreignId('ledger_account_id')->nullable()->after('status')->constrained('ledger_accounts')->nullOnDelete();
            $table->bigInteger('min_amount_minor')->nullable()->after('ledger_account_id');
            $table->bigInteger('max_amount_minor')->nullable()->after('min_amount_minor');
            $table->unsignedTinyInteger('due_date_day')->nullable()->after('max_amount_minor');
            $table->unsignedSmallInteger('penalty_grace_days')->nullable()->after('due_date_day');
            $table->unsignedSmallInteger('min_grace_period_days')->nullable()->after('penalty_grace_days');
            $table->unsignedSmallInteger('max_grace_period_days')->nullable()->after('min_grace_period_days');
            $table->decimal('interest_rate', 12, 6)->nullable()->after('max_grace_period_days');
            $table->decimal('tax_rate', 12, 6)->nullable()->after('interest_rate');
            $table->decimal('insurance_rate', 12, 6)->nullable()->after('tax_rate');
            $table->bigInteger('fee_amount_minor')->nullable()->after('insurance_rate');
            $table->bigInteger('floor_amount_minor')->nullable()->after('fee_amount_minor');
            $table->string('tax_policy_key', 128)->nullable()->after('fee_policy_key');
            $table->string('insurance_policy_key', 128)->nullable()->after('tax_policy_key');
            $table->string('guarantee_deposit_policy_key', 128)->nullable()->after('insurance_policy_key');
            $table->string('guarantee_deposit_type', 32)->nullable()->after('guarantee_deposit_policy_key');
            $table->decimal('guarantee_deposit_value', 18, 6)->nullable()->after('guarantee_deposit_type');
            $table->string('penalty_formula_type', 64)->nullable()->after('guarantee_deposit_value');
            $table->string('penalty_formula_base', 64)->nullable()->after('penalty_formula_type');
            $table->string('penalty_value_type', 32)->nullable()->after('penalty_formula_base');
            $table->decimal('penalty_value', 18, 6)->nullable()->after('penalty_value_type');
            $table->string('operation_type', 64)->nullable()->after('penalty_value');
            $table->decimal('constant_value', 18, 6)->nullable()->after('operation_type');
            $table->json('rules')->nullable()->after('constant_value');
        });

        Schema::table('loans', function (Blueprint $table): void {
            $table->foreignId('credit_agent_id')->nullable()->after('loan_product_id')->constrained('users')->nullOnDelete();
            $table->foreignId('amortization_account_id')->nullable()->after('credit_agent_id')->constrained('customer_accounts')->nullOnDelete();
            $table->foreignId('unpaid_account_id')->nullable()->after('amortization_account_id')->constrained('customer_accounts')->nullOnDelete();
            $table->foreignId('recovery_account_id')->nullable()->after('unpaid_account_id')->constrained('customer_accounts')->nullOnDelete();
            $table->foreignId('transfer_account_id')->nullable()->after('recovery_account_id')->constrained('customer_accounts')->nullOnDelete();
            $table->string('processing_level', 64)->nullable()->after('status');
            $table->string('financed_activity_code', 64)->nullable()->after('purpose');
            $table->text('activity_address')->nullable()->after('financed_activity_code');
            $table->text('entrepreneur_address')->nullable()->after('activity_address');
            $table->decimal('applied_interest_rate', 12, 6)->nullable()->after('entrepreneur_address');
            $table->decimal('applied_tax_rate', 12, 6)->nullable()->after('applied_interest_rate');
            $table->date('first_installment_date')->nullable()->after('applied_tax_rate');
            $table->unsignedSmallInteger('number_of_installments')->nullable()->after('first_installment_date');
            $table->unsignedSmallInteger('grace_period_duration')->nullable()->after('number_of_installments');
            $table->unsignedSmallInteger('tranche_duration')->nullable()->after('grace_period_duration');
            $table->unsignedSmallInteger('total_loan_duration')->nullable()->after('tranche_duration');
            $table->bigInteger('dossier_fees_minor')->nullable()->after('total_loan_duration');
            $table->bigInteger('dossier_fees_tax_minor')->nullable()->after('dossier_fees_minor');
            $table->bigInteger('guarantee_deposit_amount_minor')->nullable()->after('dossier_fees_tax_minor');
            $table->bigInteger('insurance_amount_minor')->nullable()->after('guarantee_deposit_amount_minor');
            $table->bigInteger('outstanding_principal_minor')->nullable()->after('insurance_amount_minor');
            $table->bigInteger('installment_amount_minor')->nullable()->after('outstanding_principal_minor');
            $table->bigInteger('total_unpaid_amount_minor')->nullable()->after('installment_amount_minor');
            $table->bigInteger('due_amount_minor')->nullable()->after('total_unpaid_amount_minor');
            $table->bigInteger('total_interest_repaid_minor')->default(0)->after('due_amount_minor');
            $table->bigInteger('total_penalties_paid_minor')->default(0)->after('total_interest_repaid_minor');
            $table->bigInteger('total_principal_repaid_minor')->default(0)->after('total_penalties_paid_minor');
            $table->unsignedSmallInteger('installments_repaid_count')->default(0)->after('total_principal_repaid_minor');
            $table->date('last_repayment_date')->nullable()->after('installments_repaid_count');
            $table->date('next_repayment_date')->nullable()->after('last_repayment_date');
            $table->bigInteger('global_outstanding_amount_minor')->nullable()->after('next_repayment_date');
            $table->bigInteger('capitalized_interest_minor')->default(0)->after('global_outstanding_amount_minor');
            $table->bigInteger('cumulative_capitalized_interest_minor')->default(0)->after('capitalized_interest_minor');
        });

        Schema::table('loan_schedule_lines', function (Blueprint $table): void {
            $table->bigInteger('penalty_minor')->default(0)->after('tax_minor');
            $table->bigInteger('capitalized_interest_minor')->default(0)->after('penalty_minor');
            $table->bigInteger('remaining_principal_minor')->nullable()->after('capitalized_interest_minor');
            $table->bigInteger('total_installment_minor')->nullable()->after('remaining_principal_minor');
        });

        Schema::create('loan_approvals', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('loan_id')->constrained('loans')->cascadeOnDelete();
            $table->foreignId('agency_id')->constrained('agencies')->restrictOnDelete();
            $table->string('step', 32)->index();
            $table->string('decision', 32)->default('pending')->index();
            $table->foreignId('acted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('acted_at')->nullable();
            $table->text('comments')->nullable();
            $table->timestamps();

            $table->unique(['loan_id', 'step']);
            $table->index(['agency_id', 'step', 'decision']);
        });

        Schema::create('collateral_items', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('collateral_id')->constrained('collaterals')->cascadeOnDelete();
            $table->unsignedInteger('quantity')->default(1);
            $table->string('description');
            $table->string('reference', 128)->nullable();
            $table->string('chassis_number', 128)->nullable();
            $table->string('registration_number', 128)->nullable();
            $table->bigInteger('amount_minor')->nullable();
            $table->string('currency', 3)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('loan_transfers', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('agency_id')->constrained('agencies')->restrictOnDelete();
            $table->foreignId('loan_id')->nullable()->constrained('loans')->cascadeOnDelete();
            $table->foreignId('initial_manager_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('new_manager_id')->constrained('users')->restrictOnDelete();
            $table->string('transfer_reason');
            $table->date('transfer_date')->index();
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('delinquency_trackings', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->foreignId('loan_id')->constrained('loans')->cascadeOnDelete();
            $table->foreignId('agency_id')->constrained('agencies')->restrictOnDelete();
            $table->date('tracking_date')->index();
            $table->string('reason_code', 64)->nullable();
            $table->string('appointment_type', 64)->nullable();
            $table->date('appointment_date')->nullable();
            $table->bigInteger('promised_amount_minor')->nullable();
            $table->string('currency', 3)->default('XAF');
            $table->text('comments')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['agency_id', 'tracking_date']);
            $table->index(['loan_id', 'tracking_date']);
        });

        Schema::create('loan_charge_assessments', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('loan_id')->constrained('loans')->cascadeOnDelete();
            $table->foreignId('loan_schedule_line_id')->nullable()->constrained('loan_schedule_lines')->nullOnDelete();
            $table->string('charge_type', 64)->index();
            $table->bigInteger('base_amount_minor')->nullable();
            $table->decimal('rate', 12, 6)->nullable();
            $table->bigInteger('assessed_amount_minor');
            $table->string('currency', 3)->default('XAF');
            $table->timestamp('assessed_at')->nullable();
            $table->date('due_on')->nullable();
            $table->string('status', 32)->default('assessed')->index();
            $table->timestamp('paid_at')->nullable();
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->foreignId('reversal_journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['loan_id', 'charge_type', 'status']);
        });

        Schema::create('loan_arrears', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('loan_id')->constrained('loans')->cascadeOnDelete();
            $table->foreignId('loan_schedule_line_id')->nullable()->constrained('loan_schedule_lines')->nullOnDelete();
            $table->date('due_on')->index();
            $table->bigInteger('original_due_minor');
            $table->bigInteger('paid_minor')->default(0);
            $table->bigInteger('unpaid_minor');
            $table->bigInteger('penalty_base_minor')->nullable();
            $table->string('currency', 3)->default('XAF');
            $table->string('status', 32)->default('open')->index();
            $table->timestamp('last_penalized_at')->nullable();
            $table->timestamps();

            $table->index(['loan_id', 'status', 'due_on']);
        });

        Schema::create('loan_recovery_accounts', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('loan_id')->constrained('loans')->cascadeOnDelete();
            $table->foreignId('customer_account_id')->constrained('customer_accounts')->restrictOnDelete();
            $table->unsignedSmallInteger('priority')->default(1);
            $table->boolean('is_primary')->default(false);
            $table->string('status', 32)->default('active')->index();
            $table->json('mandate_metadata')->nullable();
            $table->timestamps();

            $table->unique(['loan_id', 'customer_account_id'], 'uniq_loan_recovery_account');
            $table->index(['loan_id', 'priority']);
        });

        Schema::create('loan_recovery_attempts', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('loan_id')->constrained('loans')->cascadeOnDelete();
            $table->foreignId('loan_recovery_account_id')->nullable()->constrained('loan_recovery_accounts')->nullOnDelete();
            $table->foreignId('customer_account_id')->nullable()->constrained('customer_accounts')->nullOnDelete();
            $table->foreignId('batch_run_id')->nullable()->constrained('batch_runs')->nullOnDelete();
            $table->bigInteger('requested_amount_minor');
            $table->bigInteger('recovered_amount_minor')->default(0);
            $table->string('currency', 3)->default('XAF');
            $table->string('status', 32)->default('pending')->index();
            $table->timestamp('attempted_at')->nullable();
            $table->text('failure_reason')->nullable();
            $table->foreignId('teller_transaction_id')->nullable()->constrained('teller_transactions')->nullOnDelete();
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->timestamps();

            $table->index(['loan_id', 'status']);
        });

        Schema::table('tills', function (Blueprint $table): void {
            $table->foreignId('ledger_account_id')->nullable()->after('assigned_user_id')->constrained('ledger_accounts')->nullOnDelete();
            $table->string('daily_state', 32)->default('closed')->after('status')->index();
            $table->bigInteger('opening_balance_minor')->nullable()->after('daily_state');
            $table->bigInteger('last_closing_balance_minor')->nullable()->after('opening_balance_minor');
            $table->timestamp('last_closing_at')->nullable()->after('last_closing_balance_minor');
            $table->boolean('requires_denominations')->default(true)->after('last_closing_at');
            $table->string('nature', 64)->nullable()->after('requires_denominations');
            $table->boolean('is_central_till')->default(false)->after('nature');
            $table->bigInteger('max_balance_limit_minor')->nullable()->after('is_central_till');
            $table->bigInteger('max_withdrawal_limit_minor')->nullable()->after('max_balance_limit_minor');
            $table->string('currency', 3)->default('XAF')->after('max_withdrawal_limit_minor');
        });

        Schema::table('teller_transactions', function (Blueprint $table): void {
            $table->date('transaction_date')->nullable()->after('agency_id')->index();
            $table->foreignId('till_id')->nullable()->after('transaction_date')->constrained('tills')->nullOnDelete();
            $table->string('event_number', 64)->nullable()->after('reference')->unique();
            $table->foreignId('offset_ledger_account_id')->nullable()->after('customer_account_id')->constrained('ledger_accounts')->nullOnDelete();
            $table->foreignId('operation_code_id')->nullable()->after('offset_ledger_account_id')->constrained('operation_codes')->nullOnDelete();
            $table->string('operation_code', 64)->nullable()->after('operation_code_id');
            $table->string('depositor_name')->nullable()->after('operation_code');
            $table->string('depositor_address')->nullable()->after('depositor_name');
            $table->text('description')->nullable()->after('depositor_address');
        });

        Schema::table('till_reconciliations', function (Blueprint $table): void {
            $table->timestamp('reconciliation_date')->nullable()->after('teller_session_id')->index();
            $table->bigInteger('theoretical_balance_minor')->nullable()->after('reconciliation_date');
            $table->bigInteger('actual_balance_minor')->nullable()->after('theoretical_balance_minor');
            $table->bigInteger('difference_minor')->nullable()->after('actual_balance_minor');
            $table->string('currency', 3)->default('XAF')->after('difference_minor');
        });

        $this->createInsuranceTables();
        $this->createHrTables();
        $this->createFxTables();
        $this->createIslamicFinanceTables();
        $this->createReportingAndNotificationTables();

        $this->addIntegrityConstraints();
    }

    private function createInsuranceTables(): void
    {
        Schema::create('insurance_partners', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('agency_id')->nullable()->constrained('agencies')->nullOnDelete();
            $table->foreignId('ledger_account_id')->nullable()->constrained('ledger_accounts')->nullOnDelete();
            $table->string('code', 64);
            $table->string('name');
            $table->string('phone_number', 32)->nullable();
            $table->string('email')->nullable();
            $table->text('address')->nullable();
            $table->string('status', 32)->default('active')->index();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['agency_id', 'code']);
        });

        Schema::create('insurance_products', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('insurance_partner_id')->nullable()->constrained('insurance_partners')->nullOnDelete();
            $table->string('code', 64)->unique();
            $table->string('name');
            $table->string('product_type', 64)->index();
            $table->string('premium_calculation_type', 64)->nullable();
            $table->decimal('premium_rate', 12, 6)->nullable();
            $table->bigInteger('fixed_premium_minor')->nullable();
            $table->string('currency', 3)->default('XAF');
            $table->string('payment_mode', 64)->nullable();
            $table->boolean('is_refundable')->default(false);
            $table->string('status', 32)->default('active')->index();
            $table->json('rules')->nullable();
            $table->timestamps();
        });

        Schema::create('insurance_product_coverages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('insurance_product_id')->constrained('insurance_products')->cascadeOnDelete();
            $table->string('coverage_code', 64);
            $table->string('coverage_name');
            $table->text('description')->nullable();
            $table->timestamps();

            $table->unique(['insurance_product_id', 'coverage_code'], 'uniq_insurance_product_coverage');
        });

        Schema::create('insurance_subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->foreignId('agency_id')->constrained('agencies')->restrictOnDelete();
            $table->foreignId('loan_id')->nullable()->constrained('loans')->nullOnDelete();
            $table->foreignId('insurance_product_id')->constrained('insurance_products')->restrictOnDelete();
            $table->string('subscription_number', 64)->unique();
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->bigInteger('coverage_amount_minor')->nullable();
            $table->string('currency', 3)->default('XAF');
            $table->string('status', 32)->default('active')->index();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['client_id', 'status']);
            $table->index(['loan_id', 'status']);
        });

        Schema::create('insurance_premium_assessments', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('insurance_subscription_id')->constrained('insurance_subscriptions')->cascadeOnDelete();
            $table->foreignId('loan_id')->nullable()->constrained('loans')->nullOnDelete();
            $table->bigInteger('base_amount_minor')->nullable();
            $table->decimal('rate', 12, 6)->nullable();
            $table->bigInteger('premium_amount_minor');
            $table->string('currency', 3)->default('XAF');
            $table->date('due_on')->nullable();
            $table->timestamp('assessed_at')->nullable();
            $table->string('status', 32)->default('assessed')->index();
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('insurance_premium_payments', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('insurance_premium_assessment_id')->constrained('insurance_premium_assessments')->cascadeOnDelete();
            $table->foreignId('customer_account_id')->nullable()->constrained('customer_accounts')->nullOnDelete();
            $table->foreignId('teller_transaction_id')->nullable()->constrained('teller_transactions')->nullOnDelete();
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->bigInteger('amount_minor');
            $table->string('currency', 3)->default('XAF');
            $table->string('payment_method', 64)->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->string('status', 32)->default('posted')->index();
            $table->timestamps();
        });

        Schema::create('insurance_claims', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->foreignId('agency_id')->constrained('agencies')->restrictOnDelete();
            $table->foreignId('insurance_subscription_id')->constrained('insurance_subscriptions')->restrictOnDelete();
            $table->string('claim_number', 64)->unique();
            $table->string('claim_type', 64)->index();
            $table->date('incident_date')->nullable();
            $table->text('description')->nullable();
            $table->string('status', 32)->default('pending')->index();
            $table->bigInteger('claimed_amount_minor')->nullable();
            $table->bigInteger('indemnified_amount_minor')->nullable();
            $table->string('currency', 3)->default('XAF');
            $table->timestamp('settled_at')->nullable();
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('insurance_claim_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('insurance_claim_id')->constrained('insurance_claims')->cascadeOnDelete();
            $table->foreignId('document_id')->constrained('documents')->cascadeOnDelete();
            $table->string('document_type', 64)->nullable();
            $table->timestamps();

            $table->unique(['insurance_claim_id', 'document_id'], 'uniq_claim_document');
        });
    }

    private function createHrTables(): void
    {
        Schema::create('hr_employees', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('agency_id')->nullable()->constrained('agencies')->nullOnDelete();
            $table->foreignId('supervisor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('employee_number', 64)->unique();
            $table->string('first_name', 128);
            $table->string('last_name', 128);
            $table->string('photo_path')->nullable();
            $table->string('identity_number', 128)->nullable();
            $table->string('phone_number', 32)->nullable();
            $table->string('email')->nullable();
            $table->string('job_title', 128)->nullable();
            $table->string('service_name', 128)->nullable();
            $table->date('hired_on')->nullable();
            $table->string('contract_type', 32)->nullable();
            $table->bigInteger('base_salary_minor')->nullable();
            $table->string('currency', 3)->default('XAF');
            $table->string('emergency_contact_name')->nullable();
            $table->string('emergency_contact_phone', 32)->nullable();
            $table->string('status', 32)->default('active')->index();
            $table->text('professional_history')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('hr_contracts', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('hr_employee_id')->constrained('hr_employees')->cascadeOnDelete();
            $table->string('contract_number', 64)->unique();
            $table->string('contract_type', 32);
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->bigInteger('base_salary_minor')->nullable();
            $table->string('currency', 3)->default('XAF');
            $table->string('status', 32)->default('active')->index();
            $table->foreignId('document_id')->nullable()->constrained('documents')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('hr_employee_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('hr_employee_id')->constrained('hr_employees')->cascadeOnDelete();
            $table->foreignId('document_id')->constrained('documents')->cascadeOnDelete();
            $table->string('document_type', 64)->nullable();
            $table->timestamps();

            $table->unique(['hr_employee_id', 'document_id']);
        });

        Schema::create('hr_attendance_records', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('hr_employee_id')->constrained('hr_employees')->cascadeOnDelete();
            $table->date('attendance_date')->index();
            $table->timestamp('checked_in_at')->nullable();
            $table->timestamp('checked_out_at')->nullable();
            $table->unsignedInteger('late_minutes')->default(0);
            $table->unsignedInteger('absence_minutes')->default(0);
            $table->string('status', 32)->default('present')->index();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['hr_employee_id', 'attendance_date']);
        });

        Schema::create('hr_leave_requests', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('hr_employee_id')->constrained('hr_employees')->cascadeOnDelete();
            $table->string('leave_type', 64)->index();
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('status', 32)->default('pending')->index();
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->text('reason')->nullable();
            $table->timestamps();
        });

        Schema::create('hr_payroll_runs', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('agency_id')->nullable()->constrained('agencies')->nullOnDelete();
            $table->string('period_key', 32)->index();
            $table->date('period_starts_on');
            $table->date('period_ends_on');
            $table->string('status', 32)->default('draft')->index();
            $table->bigInteger('gross_amount_minor')->default(0);
            $table->bigInteger('deduction_amount_minor')->default(0);
            $table->bigInteger('net_amount_minor')->default(0);
            $table->string('currency', 3)->default('XAF');
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('hr_payroll_slips', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('hr_payroll_run_id')->constrained('hr_payroll_runs')->cascadeOnDelete();
            $table->foreignId('hr_employee_id')->constrained('hr_employees')->restrictOnDelete();
            $table->string('slip_number', 64)->unique();
            $table->bigInteger('gross_amount_minor')->default(0);
            $table->bigInteger('deduction_amount_minor')->default(0);
            $table->bigInteger('net_amount_minor')->default(0);
            $table->string('currency', 3)->default('XAF');
            $table->string('status', 32)->default('draft')->index();
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('hr_payroll_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('hr_payroll_slip_id')->constrained('hr_payroll_slips')->cascadeOnDelete();
            $table->string('line_type', 64)->index();
            $table->string('label');
            $table->bigInteger('amount_minor');
            $table->string('currency', 3)->default('XAF');
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('hr_salary_advances', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('hr_employee_id')->constrained('hr_employees')->cascadeOnDelete();
            $table->bigInteger('amount_minor');
            $table->bigInteger('remaining_amount_minor');
            $table->string('currency', 3)->default('XAF');
            $table->date('granted_on')->nullable();
            $table->string('status', 32)->default('active')->index();
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('hr_sanctions', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('hr_employee_id')->constrained('hr_employees')->cascadeOnDelete();
            $table->string('sanction_type', 64)->index();
            $table->date('sanction_date')->nullable();
            $table->bigInteger('deduction_amount_minor')->nullable();
            $table->string('currency', 3)->default('XAF');
            $table->text('reason')->nullable();
            $table->foreignId('document_id')->nullable()->constrained('documents')->nullOnDelete();
            $table->string('status', 32)->default('active')->index();
            $table->timestamps();
        });
    }

    private function createFxTables(): void
    {
        Schema::create('currencies', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 3)->unique();
            $table->string('name');
            $table->unsignedTinyInteger('minor_unit')->default(2);
            $table->boolean('is_base_currency')->default(false);
            $table->string('status', 32)->default('active')->index();
            $table->timestamps();
        });

        Schema::create('exchange_rates', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('base_currency', 3);
            $table->string('quote_currency', 3);
            $table->decimal('reference_rate', 20, 8);
            $table->decimal('buy_margin_rate', 12, 6)->default(0);
            $table->decimal('sell_margin_rate', 12, 6)->default(0);
            $table->decimal('buy_rate', 20, 8);
            $table->decimal('sell_rate', 20, 8);
            $table->date('effective_on')->index();
            $table->string('status', 32)->default('active')->index();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['base_currency', 'quote_currency', 'effective_on'], 'uniq_fx_rate_day');
        });

        Schema::create('till_currency_balances', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('till_id')->constrained('tills')->cascadeOnDelete();
            $table->string('currency', 3);
            $table->bigInteger('opening_balance_minor')->default(0);
            $table->bigInteger('current_balance_minor')->default(0);
            $table->bigInteger('last_closing_balance_minor')->nullable();
            $table->timestamp('last_reconciled_at')->nullable();
            $table->timestamps();

            $table->unique(['till_id', 'currency']);
        });

        Schema::create('fx_transactions', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('agency_id')->constrained('agencies')->restrictOnDelete();
            $table->foreignId('till_id')->nullable()->constrained('tills')->nullOnDelete();
            $table->foreignId('client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->string('transaction_number', 64)->unique();
            $table->date('transaction_date')->index();
            $table->string('direction', 32)->index();
            $table->string('foreign_currency', 3);
            $table->bigInteger('foreign_amount_minor');
            $table->string('local_currency', 3)->default('XAF');
            $table->bigInteger('local_amount_minor');
            $table->decimal('reference_rate', 20, 8);
            $table->decimal('applied_rate', 20, 8);
            $table->decimal('margin_rate', 12, 6)->default(0);
            $table->bigInteger('margin_amount_minor')->nullable();
            $table->string('client_name')->nullable();
            $table->string('client_identity_number', 128)->nullable();
            $table->string('status', 32)->default('posted')->index();
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('fx_stock_movements', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('agency_id')->constrained('agencies')->restrictOnDelete();
            $table->foreignId('till_id')->nullable()->constrained('tills')->nullOnDelete();
            $table->string('currency', 3);
            $table->string('movement_type', 64)->index();
            $table->bigInteger('amount_minor');
            $table->date('movement_date')->index();
            $table->string('counterparty_name')->nullable();
            $table->string('status', 32)->default('posted')->index();
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    private function createIslamicFinanceTables(): void
    {
        Schema::create('islamic_products', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('agency_id')->nullable()->constrained('agencies')->nullOnDelete();
            $table->string('code', 64)->unique();
            $table->string('name');
            $table->string('contract_type', 64)->index();
            $table->decimal('default_margin_rate', 12, 6)->nullable();
            $table->string('profit_sharing_method', 64)->nullable();
            $table->string('status', 32)->default('active')->index();
            $table->json('rules')->nullable();
            $table->timestamps();
        });

        Schema::create('islamic_financings', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->foreignId('agency_id')->constrained('agencies')->restrictOnDelete();
            $table->foreignId('islamic_product_id')->constrained('islamic_products')->restrictOnDelete();
            $table->foreignId('loan_id')->nullable()->constrained('loans')->nullOnDelete();
            $table->string('contract_number', 64)->unique();
            $table->string('contract_type', 64)->index();
            $table->bigInteger('financed_amount_minor');
            $table->bigInteger('sale_price_minor')->nullable();
            $table->bigInteger('residual_value_minor')->nullable();
            $table->string('currency', 3)->default('XAF');
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->string('status', 32)->default('draft')->index();
            $table->json('terms')->nullable();
            $table->timestamps();
        });

        Schema::create('islamic_financed_assets', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('islamic_financing_id')->constrained('islamic_financings')->cascadeOnDelete();
            $table->string('asset_type', 64)->index();
            $table->string('description');
            $table->bigInteger('purchase_amount_minor')->nullable();
            $table->string('currency', 3)->default('XAF');
            $table->string('ownership_status', 64)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('islamic_profit_sharing_terms', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('islamic_financing_id')->constrained('islamic_financings')->cascadeOnDelete();
            $table->decimal('institution_share_rate', 12, 6);
            $table->decimal('client_share_rate', 12, 6);
            $table->string('loss_sharing_rule', 128)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('islamic_compliance_reviews', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('islamic_financing_id')->nullable()->constrained('islamic_financings')->cascadeOnDelete();
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('decision', 32)->default('pending')->index();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('comments')->nullable();
            $table->json('checklist')->nullable();
            $table->timestamps();
        });
    }

    private function createReportingAndNotificationTables(): void
    {
        Schema::create('emf_regulatory_accounts', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('code', 64)->unique();
            $table->string('name');
            $table->string('account_class', 32)->nullable();
            $table->foreignId('parent_emf_regulatory_account_id')->nullable()->constrained('emf_regulatory_accounts')->nullOnDelete();
            $table->string('status', 32)->default('active')->index();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('emf_ledger_account_mappings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('emf_regulatory_account_id')->constrained('emf_regulatory_accounts')->cascadeOnDelete();
            $table->foreignId('ledger_account_id')->constrained('ledger_accounts')->cascadeOnDelete();
            $table->string('status', 32)->default('active')->index();
            $table->timestamps();

            $table->unique(['emf_regulatory_account_id', 'ledger_account_id'], 'uniq_emf_ledger_mapping');
        });

        Schema::create('operation_account_mappings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('operation_code_id')->constrained('operation_codes')->cascadeOnDelete();
            $table->foreignId('debit_ledger_account_id')->nullable()->constrained('ledger_accounts')->nullOnDelete();
            $table->foreignId('credit_ledger_account_id')->nullable()->constrained('ledger_accounts')->nullOnDelete();
            $table->string('currency', 3)->nullable();
            $table->string('status', 32)->default('active')->index();
            $table->json('rules')->nullable();
            $table->timestamps();
        });

        Schema::create('report_definitions', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('code', 64)->unique();
            $table->string('name');
            $table->string('report_type', 64)->index();
            $table->string('module', 64)->nullable()->index();
            $table->string('status', 32)->default('active')->index();
            $table->json('definition')->nullable();
            $table->timestamps();
        });

        Schema::create('report_runs', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('report_definition_id')->constrained('report_definitions')->restrictOnDelete();
            $table->foreignId('agency_id')->nullable()->constrained('agencies')->nullOnDelete();
            $table->date('period_starts_on')->nullable();
            $table->date('period_ends_on')->nullable();
            $table->string('status', 32)->default('pending')->index();
            $table->timestamp('generated_at')->nullable();
            $table->foreignId('generated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('document_id')->nullable()->constrained('documents')->nullOnDelete();
            $table->json('parameters')->nullable();
            $table->json('summary')->nullable();
            $table->timestamps();
        });

        Schema::create('dashboard_definitions', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('code', 64)->unique();
            $table->string('name');
            $table->string('audience', 64)->nullable();
            $table->string('status', 32)->default('active')->index();
            $table->json('layout')->nullable();
            $table->timestamps();
        });

        Schema::create('dashboard_widgets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('dashboard_definition_id')->constrained('dashboard_definitions')->cascadeOnDelete();
            $table->string('code', 64);
            $table->string('title');
            $table->string('widget_type', 64);
            $table->unsignedSmallInteger('position')->default(0);
            $table->json('configuration')->nullable();
            $table->timestamps();

            $table->unique(['dashboard_definition_id', 'code'], 'uniq_dashboard_widget');
        });

        Schema::create('notification_templates', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('code', 64)->unique();
            $table->string('channel', 32)->index();
            $table->string('subject')->nullable();
            $table->text('body_template');
            $table->string('status', 32)->default('active')->index();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('notification_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('notification_template_id')->nullable()->constrained('notification_templates')->nullOnDelete();
            $table->nullableMorphs('recipient');
            $table->string('channel', 32)->index();
            $table->string('destination');
            $table->string('subject')->nullable();
            $table->text('body');
            $table->string('status', 32)->default('pending')->index();
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->text('failure_reason')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('sms_messages', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->nullableMorphs('owner');
            $table->string('phone_number', 32)->index();
            $table->text('message');
            $table->string('direction', 32)->default('outbound')->index();
            $table->string('status', 32)->default('pending')->index();
            $table->string('provider_reference')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    private function addIntegrityConstraints(): void
    {
        DB::statement('ALTER TABLE account_products ADD CONSTRAINT account_products_min_balance_non_negative CHECK (minimum_balance_minor >= 0)');
        DB::statement('ALTER TABLE customer_accounts ADD CONSTRAINT customer_accounts_unavailable_non_negative CHECK (unavailable_amount_minor >= 0)');
        DB::statement('ALTER TABLE loan_products ADD CONSTRAINT loan_products_amount_limits_valid CHECK (min_amount_minor IS NULL OR max_amount_minor IS NULL OR max_amount_minor >= min_amount_minor)');
        DB::statement('ALTER TABLE loan_products ADD CONSTRAINT loan_products_due_date_valid CHECK (due_date_day IS NULL OR (due_date_day >= 1 AND due_date_day <= 31))');
        DB::statement('ALTER TABLE loans ADD CONSTRAINT loans_projection_amounts_non_negative CHECK ((dossier_fees_minor IS NULL OR dossier_fees_minor >= 0) AND (dossier_fees_tax_minor IS NULL OR dossier_fees_tax_minor >= 0) AND (guarantee_deposit_amount_minor IS NULL OR guarantee_deposit_amount_minor >= 0) AND (insurance_amount_minor IS NULL OR insurance_amount_minor >= 0) AND (outstanding_principal_minor IS NULL OR outstanding_principal_minor >= 0) AND (installment_amount_minor IS NULL OR installment_amount_minor >= 0) AND (total_unpaid_amount_minor IS NULL OR total_unpaid_amount_minor >= 0) AND (due_amount_minor IS NULL OR due_amount_minor >= 0))');
        DB::statement('ALTER TABLE loan_schedule_lines ADD CONSTRAINT loan_schedule_lines_added_amounts_non_negative CHECK (penalty_minor >= 0 AND capitalized_interest_minor >= 0 AND (remaining_principal_minor IS NULL OR remaining_principal_minor >= 0) AND (total_installment_minor IS NULL OR total_installment_minor >= 0))');
        DB::statement('ALTER TABLE loan_charge_assessments ADD CONSTRAINT loan_charge_assessments_amount_non_negative CHECK (assessed_amount_minor >= 0 AND (base_amount_minor IS NULL OR base_amount_minor >= 0))');
        DB::statement('ALTER TABLE loan_arrears ADD CONSTRAINT loan_arrears_amounts_non_negative CHECK (original_due_minor >= 0 AND paid_minor >= 0 AND unpaid_minor >= 0 AND (penalty_base_minor IS NULL OR penalty_base_minor >= 0))');
        DB::statement('ALTER TABLE loan_recovery_attempts ADD CONSTRAINT loan_recovery_attempts_amounts_non_negative CHECK (requested_amount_minor >= 0 AND recovered_amount_minor >= 0)');
        DB::statement('ALTER TABLE collateral_items ADD CONSTRAINT collateral_items_amount_positive CHECK (amount_minor IS NULL OR amount_minor > 0)');
        DB::statement('ALTER TABLE insurance_premium_assessments ADD CONSTRAINT insurance_premium_assessments_amount_positive CHECK (premium_amount_minor > 0)');
        DB::statement('ALTER TABLE insurance_premium_payments ADD CONSTRAINT insurance_premium_payments_amount_positive CHECK (amount_minor > 0)');
        DB::statement('ALTER TABLE hr_leave_requests ADD CONSTRAINT hr_leave_dates_valid CHECK (ends_on >= starts_on)');
        DB::statement('ALTER TABLE hr_salary_advances ADD CONSTRAINT hr_salary_advances_amounts_non_negative CHECK (amount_minor > 0 AND remaining_amount_minor >= 0)');
        DB::statement('ALTER TABLE exchange_rates ADD CONSTRAINT exchange_rates_positive CHECK (reference_rate > 0 AND buy_rate > 0 AND sell_rate > 0)');
        DB::statement('ALTER TABLE till_currency_balances ADD CONSTRAINT till_currency_balances_non_negative CHECK (opening_balance_minor >= 0 AND current_balance_minor >= 0 AND (last_closing_balance_minor IS NULL OR last_closing_balance_minor >= 0))');
        DB::statement('ALTER TABLE fx_transactions ADD CONSTRAINT fx_transactions_amounts_positive CHECK (foreign_amount_minor > 0 AND local_amount_minor > 0)');
        DB::statement('ALTER TABLE fx_stock_movements ADD CONSTRAINT fx_stock_movements_amount_positive CHECK (amount_minor > 0)');
        DB::statement('ALTER TABLE islamic_profit_sharing_terms ADD CONSTRAINT islamic_profit_sharing_terms_rates_valid CHECK (institution_share_rate >= 0 AND client_share_rate >= 0)');
    }

    public function down(): void
    {
        $this->dropIntegrityConstraints();

        Schema::dropIfExists('sms_messages');
        Schema::dropIfExists('notification_deliveries');
        Schema::dropIfExists('notification_templates');
        Schema::dropIfExists('dashboard_widgets');
        Schema::dropIfExists('dashboard_definitions');
        Schema::dropIfExists('report_runs');
        Schema::dropIfExists('report_definitions');
        Schema::dropIfExists('operation_account_mappings');
        Schema::dropIfExists('emf_ledger_account_mappings');
        Schema::dropIfExists('emf_regulatory_accounts');

        Schema::dropIfExists('islamic_compliance_reviews');
        Schema::dropIfExists('islamic_profit_sharing_terms');
        Schema::dropIfExists('islamic_financed_assets');
        Schema::dropIfExists('islamic_financings');
        Schema::dropIfExists('islamic_products');

        Schema::dropIfExists('fx_stock_movements');
        Schema::dropIfExists('fx_transactions');
        Schema::dropIfExists('till_currency_balances');
        Schema::dropIfExists('exchange_rates');
        Schema::dropIfExists('currencies');

        Schema::dropIfExists('hr_sanctions');
        Schema::dropIfExists('hr_salary_advances');
        Schema::dropIfExists('hr_payroll_lines');
        Schema::dropIfExists('hr_payroll_slips');
        Schema::dropIfExists('hr_payroll_runs');
        Schema::dropIfExists('hr_leave_requests');
        Schema::dropIfExists('hr_attendance_records');
        Schema::dropIfExists('hr_employee_documents');
        Schema::dropIfExists('hr_contracts');
        Schema::dropIfExists('hr_employees');

        Schema::dropIfExists('insurance_claim_documents');
        Schema::dropIfExists('insurance_claims');
        Schema::dropIfExists('insurance_premium_payments');
        Schema::dropIfExists('insurance_premium_assessments');
        Schema::dropIfExists('insurance_subscriptions');
        Schema::dropIfExists('insurance_product_coverages');
        Schema::dropIfExists('insurance_products');
        Schema::dropIfExists('insurance_partners');

        Schema::table('till_reconciliations', function (Blueprint $table): void {
            $table->dropColumn([
                'reconciliation_date',
                'theoretical_balance_minor',
                'actual_balance_minor',
                'difference_minor',
                'currency',
            ]);
        });

        Schema::table('teller_transactions', function (Blueprint $table): void {
            $table->dropForeign(['till_id']);
            $table->dropForeign(['offset_ledger_account_id']);
            $table->dropForeign(['operation_code_id']);
            $table->dropColumn([
                'transaction_date',
                'till_id',
                'event_number',
                'offset_ledger_account_id',
                'operation_code_id',
                'operation_code',
                'depositor_name',
                'depositor_address',
                'description',
            ]);
        });

        Schema::table('tills', function (Blueprint $table): void {
            $table->dropForeign(['ledger_account_id']);
            $table->dropColumn([
                'ledger_account_id',
                'daily_state',
                'opening_balance_minor',
                'last_closing_balance_minor',
                'last_closing_at',
                'requires_denominations',
                'nature',
                'is_central_till',
                'max_balance_limit_minor',
                'max_withdrawal_limit_minor',
                'currency',
            ]);
        });

        Schema::dropIfExists('loan_recovery_attempts');
        Schema::dropIfExists('loan_recovery_accounts');
        Schema::dropIfExists('loan_arrears');
        Schema::dropIfExists('loan_charge_assessments');
        Schema::dropIfExists('delinquency_trackings');
        Schema::dropIfExists('loan_transfers');
        Schema::dropIfExists('collateral_items');
        Schema::dropIfExists('loan_approvals');

        Schema::table('loan_schedule_lines', function (Blueprint $table): void {
            $table->dropColumn([
                'penalty_minor',
                'capitalized_interest_minor',
                'remaining_principal_minor',
                'total_installment_minor',
            ]);
        });

        Schema::table('loans', function (Blueprint $table): void {
            $table->dropForeign(['credit_agent_id']);
            $table->dropForeign(['amortization_account_id']);
            $table->dropForeign(['unpaid_account_id']);
            $table->dropForeign(['recovery_account_id']);
            $table->dropForeign(['transfer_account_id']);
            $table->dropColumn([
                'credit_agent_id',
                'amortization_account_id',
                'unpaid_account_id',
                'recovery_account_id',
                'transfer_account_id',
                'processing_level',
                'financed_activity_code',
                'activity_address',
                'entrepreneur_address',
                'applied_interest_rate',
                'applied_tax_rate',
                'first_installment_date',
                'number_of_installments',
                'grace_period_duration',
                'tranche_duration',
                'total_loan_duration',
                'dossier_fees_minor',
                'dossier_fees_tax_minor',
                'guarantee_deposit_amount_minor',
                'insurance_amount_minor',
                'outstanding_principal_minor',
                'installment_amount_minor',
                'total_unpaid_amount_minor',
                'due_amount_minor',
                'total_interest_repaid_minor',
                'total_penalties_paid_minor',
                'total_principal_repaid_minor',
                'installments_repaid_count',
                'last_repayment_date',
                'next_repayment_date',
                'global_outstanding_amount_minor',
                'capitalized_interest_minor',
                'cumulative_capitalized_interest_minor',
            ]);
        });

        Schema::table('loan_products', function (Blueprint $table): void {
            $table->dropForeign(['ledger_account_id']);
            $table->dropColumn([
                'ledger_account_id',
                'min_amount_minor',
                'max_amount_minor',
                'due_date_day',
                'penalty_grace_days',
                'min_grace_period_days',
                'max_grace_period_days',
                'interest_rate',
                'tax_rate',
                'insurance_rate',
                'fee_amount_minor',
                'floor_amount_minor',
                'tax_policy_key',
                'insurance_policy_key',
                'guarantee_deposit_policy_key',
                'guarantee_deposit_type',
                'guarantee_deposit_value',
                'penalty_formula_type',
                'penalty_formula_base',
                'penalty_value_type',
                'penalty_value',
                'operation_type',
                'constant_value',
                'rules',
            ]);
        });

        Schema::table('customer_accounts', function (Blueprint $table): void {
            $table->dropForeign(['account_product_id']);
            $table->dropForeign(['manager_user_id']);
            $table->dropColumn([
                'account_product_id',
                'manager_user_id',
                'account_title',
                'currency',
                'unavailable_amount_minor',
                'signature_path',
            ]);
        });

        Schema::dropIfExists('account_products');
        Schema::dropIfExists('operation_codes');
    }

    private function dropIntegrityConstraints(): void
    {
        foreach ([
            'account_products_min_balance_non_negative' => 'account_products',
            'customer_accounts_unavailable_non_negative' => 'customer_accounts',
            'loan_products_amount_limits_valid' => 'loan_products',
            'loan_products_due_date_valid' => 'loan_products',
            'loans_projection_amounts_non_negative' => 'loans',
            'loan_schedule_lines_added_amounts_non_negative' => 'loan_schedule_lines',
            'loan_charge_assessments_amount_non_negative' => 'loan_charge_assessments',
            'loan_arrears_amounts_non_negative' => 'loan_arrears',
            'loan_recovery_attempts_amounts_non_negative' => 'loan_recovery_attempts',
            'collateral_items_amount_positive' => 'collateral_items',
            'insurance_premium_assessments_amount_positive' => 'insurance_premium_assessments',
            'insurance_premium_payments_amount_positive' => 'insurance_premium_payments',
            'hr_leave_dates_valid' => 'hr_leave_requests',
            'hr_salary_advances_amounts_non_negative' => 'hr_salary_advances',
            'exchange_rates_positive' => 'exchange_rates',
            'till_currency_balances_non_negative' => 'till_currency_balances',
            'fx_transactions_amounts_positive' => 'fx_transactions',
            'fx_stock_movements_amount_positive' => 'fx_stock_movements',
            'islamic_profit_sharing_terms_rates_valid' => 'islamic_profit_sharing_terms',
        ] as $constraint => $table) {
            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$constraint}");
        }
    }
};
