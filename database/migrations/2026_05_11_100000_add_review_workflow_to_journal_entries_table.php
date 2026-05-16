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
        DB::table('journal_entries')
            ->where('status', 'pending_review')
            ->update(['status' => 'submitted']);
        DB::table('journal_entries')
            ->where('status', 'archived')
            ->update(['status' => 'cancelled']);

        Schema::table('journal_entries', function (Blueprint $table): void {
            $table->timestamp('submitted_at')->nullable()->after('posted_at');
            $table->foreignId('submitted_by_user_id')->nullable()->after('created_by_user_id')->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable()->after('submitted_by_user_id');
            $table->foreignId('reviewed_by_user_id')->nullable()->after('reviewed_at')->constrained('users')->nullOnDelete();
            $table->text('review_comment')->nullable()->after('reviewed_by_user_id');
            $table->text('rejection_reason')->nullable()->after('review_comment');
        });

        DB::statement("ALTER TABLE journal_entries ADD CONSTRAINT journal_entries_status_allowed CHECK (status IN ('draft', 'submitted', 'approved', 'rejected', 'posted', 'reversed', 'cancelled'))");
        DB::statement("ALTER TABLE journal_entries ADD CONSTRAINT journal_entries_review_metadata_consistent CHECK ((status IN ('approved', 'rejected') AND reviewed_at IS NOT NULL AND reviewed_by_user_id IS NOT NULL) OR (status NOT IN ('approved', 'rejected')))");
        DB::statement("ALTER TABLE journal_entries ADD CONSTRAINT journal_entries_rejection_reason_consistent CHECK ((status = 'rejected' AND rejection_reason IS NOT NULL) OR status <> 'rejected')");
        DB::statement("ALTER TABLE journal_entries ADD CONSTRAINT journal_entries_post_metadata_consistent CHECK ((status IN ('posted', 'reversed') AND posted_at IS NOT NULL AND posted_by_user_id IS NOT NULL) OR (status NOT IN ('posted', 'reversed')))");
        DB::statement("ALTER TABLE journal_entries ADD CONSTRAINT journal_entries_reversal_metadata_consistent CHECK ((status = 'reversed' AND reversed_by_user_id IS NOT NULL) OR status <> 'reversed')");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE journal_entries DROP CONSTRAINT IF EXISTS journal_entries_reversal_metadata_consistent');
        DB::statement('ALTER TABLE journal_entries DROP CONSTRAINT IF EXISTS journal_entries_post_metadata_consistent');
        DB::statement('ALTER TABLE journal_entries DROP CONSTRAINT IF EXISTS journal_entries_rejection_reason_consistent');
        DB::statement('ALTER TABLE journal_entries DROP CONSTRAINT IF EXISTS journal_entries_review_metadata_consistent');
        DB::statement('ALTER TABLE journal_entries DROP CONSTRAINT IF EXISTS journal_entries_status_allowed');

        Schema::table('journal_entries', function (Blueprint $table): void {
            $table->dropForeign(['reviewed_by_user_id']);
            $table->dropForeign(['submitted_by_user_id']);
            $table->dropColumn([
                'rejection_reason',
                'review_comment',
                'reviewed_by_user_id',
                'reviewed_at',
                'submitted_by_user_id',
                'submitted_at',
            ]);
        });

        DB::table('journal_entries')
            ->where('status', 'submitted')
            ->update(['status' => 'pending_review']);
    }
};
