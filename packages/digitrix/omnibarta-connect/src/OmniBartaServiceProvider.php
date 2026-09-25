<?php

namespace Digitrix\OmniBarta;

use Illuminate\Support\ServiceProvider;

class OmniBartaServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/omnibarta.php', 'omnibarta');
        $this->app->singleton(OmniBartaClient::class);
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../routes/api.php');
        $this->publishes([__DIR__.'/../config/omnibarta.php' => config_path('omnibarta.php')], 'omnibarta-config');
    }
}
