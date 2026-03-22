<?php

namespace ApexToolbox\Logger;

use ApexToolbox\Logger\Handlers\ExceptionHandler;
use ApexToolbox\Logger\Http\HttpRequestLogger;
use Illuminate\Contracts\Debug\ExceptionHandler as LaravelExceptionHandler;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\ServiceProvider;
use Throwable;

class LoggerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/config/apextoolbox.php', 'apextoolbox');
    }

    public function boot(): void
    {
        $this->registerHttpLogger();
        $this->registerExceptionHandler();

        $this->publishes([
            __DIR__ . '/config/apextoolbox.php' => $this->app->configPath('apextoolbox.php'),
        ], 'logger-config');
    }

    private function registerExceptionHandler(): void
    {
        if (!Config::get('apextoolbox.enabled', true) || !Config::get('apextoolbox.token')) {
            return;
        }

        if ($this->app->runningUnitTests()) {
            return;
        }

        $this->callAfterResolving(LaravelExceptionHandler::class, function ($handler) {
            if (method_exists($handler, 'reportable')) {
                $handler->reportable(function (Throwable $e) {
                    ExceptionHandler::capture($e);

                    return false;
                });
            }
        });
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
