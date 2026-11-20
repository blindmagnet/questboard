<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->morphs('tokenable');
            $table->text('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('games', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug', 64)->unique();
            // Only a SHA-256 hash of the server API key is stored.
            $table->string('api_key_hash', 64)->nullable()->unique();
            $table->string('api_key_prefix', 16)->nullable();
            // Shared secret for HMAC request signing (encrypted at rest via model cast).
            $table->text('signing_secret')->nullable();
            $table->unsignedBigInteger('quota_llm_tokens_monthly')->default(200000);
            $table->unsignedInteger('quota_daily_xp_per_player')->default(5000);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('game_admins', function (Blueprint $table) {
            $table->foreignId('game_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->primary(['game_id', 'user_id']);
        });

        Schema::create('game_players', function (Blueprint $table) {
            $table->id();
            $table->foreignId('game_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('xp')->default(0);
            $table->unsignedInteger('level')->default(1);
            $table->unsignedInteger('risk_score')->default(0);
            $table->timestamp('banned_at')->nullable();
            $table->timestamp('shadow_banned_at')->nullable();
            $table->string('ban_reason')->nullable();
            $table->timestamps();

            $table->unique(['game_id', 'user_id']);
            $table->index(['game_id', 'xp']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('game_players');
        Schema::dropIfExists('game_admins');
        Schema::dropIfExists('games');
        Schema::dropIfExists('personal_access_tokens');
    }
};
