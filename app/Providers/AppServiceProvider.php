<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->configureModels();
        $this->configurePasswordRules();
        $this->configureDates();
    }

    /**
     * Lazy loading is an error in development, not a convenience: an N+1 in the
     * tally page is the single most likely way to miss the 2-second target.
     */
    private function configureModels(): void
    {
        Model::preventLazyLoading(! $this->app->isProduction());
        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());

        // Surface slow queries during development instead of discovering them
        // once the office has a few years of records.
        if (! $this->app->isProduction()) {
            DB::whenQueryingForLongerThan(500, function (): void {
                logger()->warning('Slow database query exceeded 500ms.');
            });
        }
    }

    private function configurePasswordRules(): void
    {
        Password::defaults(fn (): Password => $this->app->isProduction()
            ? Password::min(12)->letters()->numbers()->mixedCase()->uncompromised()
            : Password::min(8));
    }

    private function configureDates(): void
    {
        Date::use(Carbon::class);
    }
}
