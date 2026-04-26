<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('otp_challenges', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('purpose', 64);
            $table->string('phone_number');
            $table->string('code_hash');
            $table->timestamp('expires_at')->index();
            $table->timestamp('used_at')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->unsignedTinyInteger('max_attempts')->default(5);
            $table->timestamp('last_sent_at')->nullable();
            $table->unsignedSmallInteger('resend_count')->default(0);
            $table->string('created_ip', 45)->nullable();
            $table->text('created_user_agent')->nullable();
            $table->timestamps();

            $table->index(['phone_number', 'purpose']);
            $table->index(['user_id', 'purpose', 'used_at']);
        });

        Schema::create('otp_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('otp_challenge_id')->constrained()->cascadeOnDelete();
            $table->string('channel', 32);
            $table->string('destination_hash', 64);
            $table->string('destination_masked');
            $table->string('status', 32);
            $table->string('provider_reference')->nullable();
            $table->text('error_summary')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();

            $table->index(['channel', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('otp_deliveries');
        Schema::dropIfExists('otp_challenges');
    }
};
