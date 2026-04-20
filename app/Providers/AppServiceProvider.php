<?php

namespace App\Providers;

use App\Ai\Storage\PendingTurnTracker;
use App\Ai\Storage\TrackedDatabaseConversationStore;
use App\Services\OllamaClient;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Laravel\Ai\Contracts\ConversationStore;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(OllamaClient::class, fn () => OllamaClient::fromConfig());

        // Route conversation persistence through the tracked store so the
        // stream controller can pre-insert a pending turn, let the
        // middleware's success callback promote it, and fall back to
        // `canceled` when the stream aborts.
        $this->app->scoped(PendingTurnTracker::class);
        $this->app->scoped(ConversationStore::class, TrackedDatabaseConversationStore::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->warnIfRemoteAccessEnabled();
    }

    /**
     * Gail's tools (shell, PHP eval, filesystem, HTTP) are powerful by
     * design. Opting out of the loopback-only gate is a legitimate
     * operator choice, but it means the operator is responsible for
     * putting their own auth + transport security in front. Emit a
     * loud, once-per-boot warning so the decision is visible in logs.
     */
    protected function warnIfRemoteAccessEnabled(): void
    {
        if (! $this->app->runningInConsole() && config('gail.allow_remote', false)) {
            Log::warning(
                'GAIL_ALLOW_REMOTE is enabled — the loopback-only gate is OFF. '
                .'The shell, PHP eval, filesystem, and HTTP tools are now reachable '
                .'by any caller. Ensure your own auth and transport security are in place.'
            );
        }
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
