<?php

namespace ApiTypes;

use ApiTypes\Commands\GenerateCommand;
use Illuminate\Support\ServiceProvider;

class ApiTypesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/api-types.php', 'api-types');
    }

    public function boot(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__.'/../config/api-types.php' => config_path('api-types.php'),
        ], 'api-types-config');

        $this->commands([GenerateCommand::class]);
    }
}
