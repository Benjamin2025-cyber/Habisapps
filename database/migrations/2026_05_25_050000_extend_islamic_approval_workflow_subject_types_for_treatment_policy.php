<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE islamic_approval_workflows DROP CONSTRAINT IF EXISTS islamic_approval_workflows_subject_type_valid');
        DB::statement(<<<'SQL'
ALTER TABLE islamic_approval_workflows ADD CONSTRAINT islamic_approval_workflows_subject_type_valid
CHECK (
    subject_type IN (
        'islamic_product',
        'islamic_contract_template',
        'islamic_screening_policy',
        'islamic_exception',
        'islamic_mapping',
        'islamic_treatment_policy',
        'islamic_corrective_action'
    )
)
SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE islamic_approval_workflows DROP CONSTRAINT IF EXISTS islamic_approval_workflows_subject_type_valid');
        DB::statement(<<<'SQL'
ALTER TABLE islamic_approval_workflows ADD CONSTRAINT islamic_approval_workflows_subject_type_valid
CHECK (
    subject_type IN (
        'islamic_product',
        'islamic_contract_template',
        'islamic_screening_policy',
        'islamic_exception',
        'islamic_mapping',
        'islamic_corrective_action'
    )
)
SQL);
    }
};
