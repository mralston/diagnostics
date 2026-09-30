<?php

namespace Mralston\Diagnostics;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Mralston\Diagnostics\Console\ListCommand;
use Mralston\Diagnostics\Console\PruneCommand;
use Mralston\Diagnostics\Console\ReapCommand;
use Mralston\Diagnostics\Console\RunCommand;
use Throwable;

class DiagnosticsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/diagnostics.php', 'diagnostics');

        $this->app->singleton(DiagnosticsManager::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        // <x-diagnostics::outcome :run="$run" /> and any other components in the package.
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'diagnostics');
        Blade::anonymousComponentPath(__DIR__.'/../resources/views/components', 'diagnostics');

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/diagnostics.php' => config_path('diagnostics.php'),
            ], 'diagnostics-config');

            if (method_exists($this, 'publishesMigrations')) {
                $this->publishesMigrations([
                    __DIR__.'/../database/migrations' => database_path('migrations'),
                ], 'diagnostics-migrations');
            }

            $this->commands([
                RunCommand::class,
                ListCommand::class,
                PruneCommand::class,
                ReapCommand::class,
            ]);
        }

        $this->registerRoutes();
        $this->registerChannels();
    }

    private function registerRoutes(): void
    {
        if (! config('diagnostics.routes.enabled', true)) {
            return;
        }

        Route::group([
            'prefix' => config('diagnostics.routes.prefix', 'diagnostics'),
            'middleware' => config('diagnostics.routes.middleware', ['web', 'auth']),
            'as' => 'diagnostics.',
        ], function () {
            $this->loadRoutesFrom(__DIR__.'/../routes/api.php');
        });
    }

    /**
     * Channel authorisation is registered from here so hosts get it without
     * touching their own channels file. It is inert in an app that never
     * broadcasts.
     */
    private function registerChannels(): void
    {
        try {
            require __DIR__.'/../routes/channels.php';
        } catch (Throwable) {
            // Broadcasting is not available in this application.
        }
    }
}
