<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_idempotency_keys', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 255);
            $table->string('method', 10);
            $table->string('path', 512);
            $table->string('actor_context', 512);
            $table->string('scope_hash', 64)->unique();
            $table->string('request_fingerprint', 64);
            $table->json('response_body')->nullable();
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->json('response_headers')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('expires_at')->index();
            $table->timestamps();

            $table->index(['method', 'path']);
            $table->index('key');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_idempotency_keys');
    }
};
