<?php

namespace App\Providers;

use App\Domain\AntiCheat\RiskScorer;
use App\Domain\AntiCheat\Rules\BurstRule;
use App\Domain\AntiCheat\Rules\PerfectScoreRule;
use App\Domain\AntiCheat\Rules\TooFastRule;
use App\Domain\Llm\CircuitBreaker;
use App\Domain\Llm\LlmClient;
use App\Domain\Llm\Moderation;
use App\Domain\Llm\Providers\FakeLlmClient;
use App\Domain\Llm\Providers\HttpLlmClient;
use App\Domain\Progression\LevelCurve;
use App\Domain\Progression\XpCalculator;
use App\Domain\Webhooks\UrlGuard;
use App\Models\Game;
use App\Models\User;
use App\Support\Tenancy\CurrentGame;
use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\SecurityScheme;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(CurrentGame::class);

        $this->app->singleton(LevelCurve::class);
        $this->app->singleton(XpCalculator::class, fn () => new XpCalculator(
            config('questboard.progression.difficulty_multipliers'),
        ));

        $this->app->singleton(RiskScorer::class, fn () => new RiskScorer([
            new TooFastRule,
            new BurstRule((int) config('questboard.anti_cheat.burst_max_completions')),
            new PerfectScoreRule,
        ], (int) config('questboard.anti_cheat.review_threshold')));

        $this->app->singleton(LlmClient::class, function () {
            $config = config('questboard.llm');
            if ($config['driver'] !== 'http' || empty($config['api_key'])) {
                return new FakeLlmClient;
            }

            return new HttpLlmClient(
                $config['base_url'], $config['api_key'], $config['model'], $config['timeout_seconds'], $config['retries'],
                new CircuitBreaker(Cache::store(), 'llm', $config['circuit_breaker']['failure_threshold'], $config['circuit_breaker']['cooldown_seconds']),
            );
        });

        $this->app->singleton(Moderation::class, fn () => new Moderation(
            config('questboard.llm.moderation.blocked_terms'),
            (int) config('questboard.llm.moderation.max_title_length'),
            (int) config('questboard.llm.moderation.max_description_length'),
        ));

        $this->app->singleton(UrlGuard::class, fn () => new UrlGuard(
            (bool) config('questboard.webhooks.allow_insecure_urls'),
        ));
    }

    public function boot(): void
    {
        Model::shouldBeStrict(! $this->app->isProduction());

        Scramble::configure()->withDocumentTransformers(function (OpenApi $openApi) {
            $openApi->secure(SecurityScheme::http('bearer'));
        });

        Gate::define('platform-admin', fn (User $user) => $user->isPlatformAdmin());
        Gate::define('manage-game', fn (User $user, Game $game) => $user->canManage($game));

        RateLimiter::for('auth', fn (Request $request) => [
            Limit::perMinute(10)->by('ip:'.$request->ip()),
        ]);
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute((int) config('questboard.rate_limits.api_per_minute'))
            ->by($request->user()?->getAuthIdentifier() ?: $request->ip()));
        RateLimiter::for('server', fn (Request $request) => Limit::perMinute((int) config('questboard.rate_limits.server_per_minute'))
            ->by('server:'.$request->header('X-Api-Key', $request->ip())));
        RateLimiter::for('generate', fn (Request $request) => Limit::perMinute((int) config('questboard.rate_limits.generate_per_minute'))
            ->by('gen:'.($request->user()?->getAuthIdentifier() ?: $request->ip())));
    }
}
