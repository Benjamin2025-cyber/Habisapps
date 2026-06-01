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
        Schema::create('user_notifications', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('recipient_type', 32)->index();
            $table->unsignedBigInteger('recipient_id')->nullable()->index();
            $table->foreignId('agency_id')->nullable()->constrained('agencies')->nullOnDelete();
            $table->string('type', 16)->index();
            $table->string('category', 64)->index();
            $table->string('title');
            $table->text('message');
            $table->string('action_url')->nullable();
            $table->string('source_type', 128);
            $table->string('source_public_id', 64);
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('user_notification_reads', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_notification_id')->constrained('user_notifications')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('read_at');
            $table->timestamps();
            $table->unique(['user_notification_id', 'user_id'], 'user_notification_reads_notification_user_unique');
        });

        DB::statement("ALTER TABLE user_notifications ADD CONSTRAINT user_notifications_recipient_type_check CHECK (recipient_type IN ('user', 'agency', 'platform'))");
        DB::statement("ALTER TABLE user_notifications ADD CONSTRAINT user_notifications_type_check CHECK (type IN ('info', 'success', 'warning', 'error'))");
        DB::statement("ALTER TABLE user_notifications ADD CONSTRAINT user_notifications_recipient_consistent CHECK ((recipient_type = 'platform' AND recipient_id IS NULL) OR (recipient_type <> 'platform' AND recipient_id IS NOT NULL))");
        DB::statement('CREATE UNIQUE INDEX user_notifications_unread_source_unique ON user_notifications (recipient_type, COALESCE(recipient_id, 0), source_type, source_public_id, category)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS user_notifications_unread_source_unique');
        Schema::dropIfExists('user_notification_reads');
        Schema::dropIfExists('user_notifications');
    }
};
