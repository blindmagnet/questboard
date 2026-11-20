<?php

use App\Http\Controllers\V1\Admin;
use App\Http\Controllers\V1\AuthController;
use App\Http\Controllers\V1\GameController;
use App\Http\Controllers\V1\LeaderboardController;
use App\Http\Controllers\V1\Platform;
use App\Http\Controllers\V1\PlayerController;
use App\Http\Controllers\V1\QuestController;
use App\Http\Controllers\V1\ServerCompletionController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->name('v1.')->group(function () {

    // --- Auth ---------------------------------------------------------------
    Route::middleware('throttle:auth')->group(function () {
        Route::post('auth/register', [AuthController::class, 'register'])->name('auth.register');
        Route::post('auth/login', [AuthController::class, 'login'])->name('auth.login');
    });

    // --- Game servers (API key + HMAC) ---------------------------------------
    Route::post('server/games/{game}/players/{player}/complete', ServerCompletionController::class)
        ->middleware(['throttle:server', 'server.signed', 'game', 'idempotent'])
        ->scopeBindings()->name('server.complete');

    Route::middleware(['auth:sanctum', 'throttle:api'])->group(function () {
        Route::post('auth/logout', [AuthController::class, 'logout'])->name('auth.logout');
        Route::get('me', [AuthController::class, 'me'])->name('me');
        Route::delete('me', [AuthController::class, 'destroy'])->name('me.destroy');

        Route::get('games', [GameController::class, 'index'])->name('games.index');

        Route::prefix('games/{game}')->middleware('game')->scopeBindings()->group(function () {
            Route::post('join', [GameController::class, 'join'])->name('games.join');

            // --- Player ------------------------------------------------------
            Route::middleware('member')->group(function () {
                Route::get('quests', [QuestController::class, 'index'])->name('quests.index');
                Route::get('quests/{quest}', [QuestController::class, 'show'])->name('quests.show');
                Route::post('quests/{quest}/complete', [QuestController::class, 'complete'])
                    ->middleware('idempotent')->name('quests.complete');
                Route::get('players/me', [PlayerController::class, 'me'])->name('players.me');
                Route::get('leaderboard', [LeaderboardController::class, 'index'])->name('leaderboard.index');
                Route::get('leaderboard/me', [LeaderboardController::class, 'me'])->name('leaderboard.me');
                Route::get('achievements', [Admin\AchievementController::class, 'index'])->name('achievements.index');
                Route::get('seasons', [Admin\SeasonController::class, 'index'])->name('seasons.index');
            });

            // --- Game admin --------------------------------------------------
            Route::middleware('can:manage-game,game')->group(function () {
                Route::post('quests', [Admin\QuestController::class, 'store'])->name('quests.store');
                Route::put('quests/{quest}', [Admin\QuestController::class, 'update'])->name('quests.update');
                Route::delete('quests/{quest}', [Admin\QuestController::class, 'destroy'])->name('quests.destroy');
                Route::post('quests/{quest}/publish', [Admin\QuestController::class, 'publish'])->name('quests.publish');
                Route::post('quests/generate', [Admin\GenerationController::class, 'store'])
                    ->middleware('throttle:generate')->name('quests.generate');
                Route::get('generations/{generation}', [Admin\GenerationController::class, 'show'])->name('generations.show');
                Route::get('usage', [Admin\GenerationController::class, 'usage'])->name('usage');

                Route::post('achievements', [Admin\AchievementController::class, 'store'])->name('achievements.store');
                Route::put('achievements/{achievement}', [Admin\AchievementController::class, 'update'])->name('achievements.update');
                Route::delete('achievements/{achievement}', [Admin\AchievementController::class, 'destroy'])->name('achievements.destroy');

                Route::post('seasons', [Admin\SeasonController::class, 'store'])->name('seasons.store');
                Route::get('seasons/{season}', [Admin\SeasonController::class, 'show'])->name('seasons.show');
                Route::put('seasons/{season}', [Admin\SeasonController::class, 'update'])->name('seasons.update');
                Route::delete('seasons/{season}', [Admin\SeasonController::class, 'destroy'])->name('seasons.destroy');
                Route::post('seasons/{season}/close', [Admin\SeasonController::class, 'close'])->name('seasons.close');

                Route::post('players/{player}/ban', [Admin\ModerationController::class, 'ban'])->name('players.ban');
                Route::delete('players/{player}/ban', [Admin\ModerationController::class, 'unban'])->name('players.unban');
                Route::get('flags', [Admin\ModerationController::class, 'flags'])->name('flags.index');
                Route::post('completions/{completion}/approve', [Admin\ModerationController::class, 'approve'])->name('completions.approve');
                Route::post('completions/{completion}/reject', [Admin\ModerationController::class, 'reject'])->name('completions.reject');

                Route::get('webhooks', [Admin\WebhookController::class, 'index'])->name('webhooks.index');
                Route::post('webhooks', [Admin\WebhookController::class, 'store'])->name('webhooks.store');
                Route::put('webhooks/{webhook}', [Admin\WebhookController::class, 'update'])->name('webhooks.update');
                Route::delete('webhooks/{webhook}', [Admin\WebhookController::class, 'destroy'])->name('webhooks.destroy');
                Route::get('webhooks/{webhook}/deliveries', [Admin\WebhookController::class, 'deliveries'])->name('webhooks.deliveries');
            });
        });

        // --- Platform admin --------------------------------------------------
        Route::prefix('admin')->middleware('can:platform-admin')->name('admin.')->group(function () {
            Route::get('games', [Platform\GameController::class, 'index'])->name('games.index');
            Route::post('games', [Platform\GameController::class, 'store'])->name('games.store');
            Route::get('games/{game}', [Platform\GameController::class, 'show'])->name('games.show');
            Route::put('games/{game}', [Platform\GameController::class, 'update'])->name('games.update');
            Route::delete('games/{game}', [Platform\GameController::class, 'destroy'])->name('games.destroy');
            Route::post('games/{game}/rotate-key', [Platform\GameController::class, 'rotateKey'])->name('games.rotate-key');
            Route::post('games/{game}/admins', [Platform\GameController::class, 'addAdmin'])->name('games.admins.store');
            Route::get('audit-logs', [Platform\AuditLogController::class, 'index'])->name('audit-logs.index');
            Route::get('prompt-versions', [Platform\PromptVersionController::class, 'index'])->name('prompts.index');
            Route::post('prompt-versions', [Platform\PromptVersionController::class, 'store'])->name('prompts.store');
            Route::post('prompt-versions/{promptVersion}/activate', [Platform\PromptVersionController::class, 'activate'])->name('prompts.activate');
        });
    });
});
