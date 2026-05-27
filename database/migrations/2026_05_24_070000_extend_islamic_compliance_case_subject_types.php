<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE islamic_compliance_cases DROP CONSTRAINT IF EXISTS islamic_compliance_cases_subject_type_valid');
        DB::statement(<<<'SQL'
ALTER TABLE islamic_compliance_cases ADD CONSTRAINT islamic_compliance_cases_subject_type_valid
  CHECK (subject_type IN (
    'islamic_product',
    'islamic_financing',
    'islamic_customer',
    'islamic_asset',
    'islamic_goods',
    'islamic_project',
    'islamic_supplier',
    'islamic_account',
    'investment_account',
    'islamic_contract',
    'islamic_transaction'
  ))
SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE islamic_compliance_cases DROP CONSTRAINT IF EXISTS islamic_compliance_cases_subject_type_valid');
        DB::statement(<<<'SQL'
ALTER TABLE islamic_compliance_cases ADD CONSTRAINT islamic_compliance_cases_subject_type_valid
  CHECK (subject_type IN (
    'islamic_product',
    'islamic_customer',
    'islamic_asset',
    'islamic_goods',
    'islamic_project',
    'islamic_supplier',
    'islamic_account',
    'islamic_contract',
    'islamic_transaction'
  ))
SQL);
    }
};
