<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notification_deliveries', function (Blueprint $table): void {
            $table->unsignedSmallInteger('retry_count')->default(0)->after('status');
            $table->unsignedSmallInteger('max_attempts')->default(3)->after('retry_count');
            $table->timestamp('last_attempt_at')->nullable()->after('max_attempts');
            $table->timestamp('next_attempt_at')->nullable()->after('last_attempt_at')->index();
        });

        Schema::table('otp_deliveries', function (Blueprint $table): void {
            $table->unsignedSmallInteger('retry_count')->default(0)->after('status');
            $table->unsignedSmallInteger('max_attempts')->default(3)->after('retry_count');
            $table->timestamp('last_attempt_at')->nullable()->after('max_attempts');
            $table->timestamp('next_attempt_at')->nullable()->after('last_attempt_at')->index();
        });
    }

    public function down(): void
    {
        Schema::table('otp_deliveries', function (Blueprint $table): void {
            $table->dropColumn(['retry_count', 'max_attempts', 'last_attempt_at', 'next_attempt_at']);
        });

        Schema::table('notification_deliveries', function (Blueprint $table): void {
            $table->dropColumn(['retry_count', 'max_attempts', 'last_attempt_at', 'next_attempt_at']);
        });
    }
};
