<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prompt_versions', function (Blueprint $table) {
            $table->id();
            $table->string('name', 64);
            $table->unsignedInteger('version');
            $table->text('system_prompt');
            $table->text('template');
            $table->boolean('is_active')->default(false);
            $table->timestamps();

            $table->unique(['name', 'version']);
            $table->index(['name', 'is_active']);
        });

        Schema::create('quests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('game_id')->constrained()->cascadeOnDelete();
            $table->string('title', 120);
            $table->text('description')->nullable();
            $table->string('difficulty', 16)->default('easy');
            $table->unsignedInteger('xp_reward');
            $table->unsignedInteger('max_score')->default(100);
            $table->unsignedInteger('min_duration_ms')->default(0);
            $table->unsignedInteger('cooldown_seconds')->default(0);
            $table->boolean('is_repeatable')->default(false);
            $table->boolean('is_active')->default(false);
            $table->string('source', 16)->default('manual');
            $table->foreignId('prompt_version_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('llm_generation_id')->nullable()->index();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['game_id', 'is_active', 'difficulty']);
        });

        Schema::create('quest_completions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('game_id')->constrained()->cascadeOnDelete();
            $table->foreignId('player_id')->constrained('game_players')->cascadeOnDelete();
            $table->foreignId('quest_id')->constrained()->cascadeOnDelete();
            // Equals quest_id for non-repeatable quests, NULL otherwise. Combined with the
            // unique index below this enforces "complete once" at the database level
            // (NULLs never collide, so repeatable quests are unaffected).
            $table->unsignedBigInteger('once_guard')->nullable();
            $table->unsignedInteger('score');
            $table->unsignedInteger('duration_ms')->nullable();
            $table->unsignedInteger('xp_awarded')->default(0);
            $table->string('status', 16)->default('accepted'); // accepted | flagged | rejected
            $table->unsignedInteger('risk_score')->default(0);
            $table->json('risk_reasons')->nullable();
            $table->string('reported_by', 16)->default('player'); // player | server
            $table->string('idempotency_key', 100);
            $table->json('client_meta')->nullable();
            $table->timestamp('completed_at');
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->unique(['game_id', 'idempotency_key']);
            $table->unique(['game_id', 'player_id', 'once_guard']);
            $table->index(['player_id', 'quest_id', 'completed_at']);
            $table->index(['game_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quest_completions');
        Schema::dropIfExists('quests');
        Schema::dropIfExists('prompt_versions');
    }
};
