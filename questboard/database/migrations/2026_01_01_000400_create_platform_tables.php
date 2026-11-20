<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outbox_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('game_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('type', 64);
            $table->json('payload');
            $table->string('status', 16)->default('pending'); // pending | published | failed
            $table->unsignedInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('available_at');
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'available_at']);
        });

        Schema::create('webhook_endpoints', function (Blueprint $table) {
            $table->id();
            $table->foreignId('game_id')->constrained()->cascadeOnDelete();
            $table->string('url', 2048);
            $table->text('secret');
            $table->json('events');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('consecutive_failures')->default(0);
            $table->timestamp('disabled_at')->nullable();
            $table->timestamps();
        });

        Schema::create('webhook_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('endpoint_id')->constrained('webhook_endpoints')->cascadeOnDelete();
            $table->uuid('event_id');
            $table->string('event_type', 64);
            $table->string('status', 16)->default('pending'); // pending | delivered | failed
            $table->unsignedSmallInteger('status_code')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('next_retry_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();

            $table->unique(['endpoint_id', 'event_id']);
        });

        Schema::create('llm_generations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('game_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('prompt_version_id')->nullable()->constrained()->nullOnDelete();
            $table->json('input');
            $table->json('output')->nullable();
            $table->unsignedInteger('tokens_in')->default(0);
            $table->unsignedInteger('tokens_out')->default(0);
            $table->decimal('cost_estimate', 10, 6)->default(0);
            $table->string('status', 16)->default('queued'); // queued | running | succeeded | failed | rejected
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->text('error')->nullable();
            $table->json('moderation_result')->nullable();
            $table->timestamps();

            $table->index(['game_id', 'created_at']);
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('game_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 64);
            $table->string('subject_type', 64)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->json('diff')->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('request_id', 64)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['game_id', 'created_at']);
            $table->index(['subject_type', 'subject_id']);
        });

        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->id();
            $table->string('key', 100);
            $table->string('scope', 191);
            $table->string('request_hash', 64);
            $table->unsignedSmallInteger('response_status');
            $table->json('response');
            $table->timestamp('expires_at')->index();
            $table->timestamps();

            $table->unique(['scope', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('llm_generations');
        Schema::dropIfExists('webhook_deliveries');
        Schema::dropIfExists('webhook_endpoints');
        Schema::dropIfExists('outbox_events');
    }
};
