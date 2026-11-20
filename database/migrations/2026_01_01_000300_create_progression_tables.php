<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('achievements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('game_id')->constrained()->cascadeOnDelete();
            $table->string('key', 64);
            $table->string('name');
            $table->string('description')->nullable();
            $table->json('rule');
            $table->string('icon')->nullable();
            $table->timestamps();

            $table->unique(['game_id', 'key']);
        });

        Schema::create('player_achievements', function (Blueprint $table) {
            $table->foreignId('player_id')->constrained('game_players')->cascadeOnDelete();
            $table->foreignId('achievement_id')->constrained()->cascadeOnDelete();
            $table->timestamp('unlocked_at');
            $table->primary(['player_id', 'achievement_id']);
        });

        Schema::create('seasons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('game_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->string('status', 16)->default('scheduled'); // scheduled | active | closed
            $table->boolean('auto_renew')->default(false);
            $table->unsignedInteger('renew_days')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->index(['game_id', 'status']);
        });

        Schema::create('season_scores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('season_id')->constrained()->cascadeOnDelete();
            $table->foreignId('player_id')->constrained('game_players')->cascadeOnDelete();
            $table->unsignedBigInteger('points')->default(0);
            // Filled when the season is closed (frozen snapshot).
            $table->unsignedInteger('final_rank')->nullable();
            $table->timestamps();

            $table->unique(['season_id', 'player_id']);
            $table->index(['season_id', 'points']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('season_scores');
        Schema::dropIfExists('seasons');
        Schema::dropIfExists('player_achievements');
        Schema::dropIfExists('achievements');
    }
};
