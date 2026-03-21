<?php

namespace ApexToolbox\Logger;

use ApexToolbox\Logger\Http\HttpRequestLogger;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;

class LoggerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/config/apextoolbox.php', 'apextoolbox');
    }

    public function boot(): void
    {
        $this->registerHttpLogger();

        $this->publishes([
            __DIR__ . '/config/apextoolbox.php' => $this->app->configPath('apextoolbox.php'),
        ], 'logger-config');
    }

    private function registerHttpLogger(): void
    {
        if (!Config::get('apextoolbox.enabled', true) || !Config::get('apextoolbox.token')) {
            return;
        }

        if ($this->app->runningUnitTests()) {
            return;
        }

        Http::globalRequestMiddleware(HttpRequestLogger::requestMiddleware());
        Http::globalResponseMiddleware(HttpRequestLogger::responseMiddleware());
    }
}
