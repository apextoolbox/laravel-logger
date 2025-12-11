<?php

namespace ApexToolbox\Logger;

use ApexToolbox\Logger\Database\QueryLogger;
use ApexToolbox\Logger\Handlers\ApexToolboxExceptionHandler;
use ApexToolbox\Logger\Http\HttpRequestLogger;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Console\Events\CommandFinished;
use Throwable;

class LoggerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/config/logger.php', 'logger');

        $this->app->singleton(QueryLogger::class);

        $this->app->extend(ExceptionHandler::class, function ($handler, $app) {
            $handler->reportable(function (Throwable $exception) use ($handler) {
                if ($handler->shouldReport($exception)) {
                    ApexToolboxExceptionHandler::logException($exception);
                }
            });

            return $handler;
        });
    }

    public function boot(): void
    {
        $this->registerQueryLogger();
        $this->registerHttpLogger();

        Event::listen(JobProcessing::class, function () {
            PayloadCollector::clear();
            PayloadCollector::setRequestId(Str::uuid7()->toString());
            $this->app->make(QueryLogger::class)->clear();
        });

        Event::listen(JobProcessed::class, function () {
            $this->app->make(QueryLogger::class)->detectN1Queries();
            PayloadCollector::send();
            PayloadCollector::clear();
        });

        Event::listen(JobFailed::class, function () {
            $this->app->make(QueryLogger::class)->detectN1Queries();
            PayloadCollector::send();
            PayloadCollector::clear();
        });

        Event::listen(CommandStarting::class, function () {
            PayloadCollector::clear();
            PayloadCollector::setRequestId(Str::uuid7()->toString());
            $this->app->make(QueryLogger::class)->clear();
        });

        Event::listen(CommandFinished::class, function () {
            $this->app->make(QueryLogger::class)->detectN1Queries();
            PayloadCollector::send();
            PayloadCollector::clear();
        });

        // Handle exceptions that occur outside of HTTP requests (CLI, queue, etc.)
        set_exception_handler(function (Throwable $exception) {
            ApexToolboxExceptionHandler::logException($exception);
            PayloadCollector::send(); // Send immediately for non-HTTP contexts
            report($exception);
        });

        // Handle fatal errors
        register_shutdown_function(function () {
            PayloadCollector::send();

            $error = error_get_last();

            if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
                $exception = new \ErrorException(
                    $error['message'],
                    0,
                    $error['type'],
                    $error['file'],
                    $error['line']
                );

                ApexToolboxExceptionHandler::logException($exception);
                PayloadCollector::send(); // Send immediately for fatal errors
            }
        });

        $this->publishes([
            __DIR__ . '/config/logger.php' => $this->app->configPath('logger.php'),
        ], 'logger-config');
    }

    private function registerQueryLogger(): void
    {
        if (!Config::get('logger.enabled', true) || !Config::get('logger.token')) {
            return;
        }

        DB::listen(function ($query) {
            $this->app->make(QueryLogger::class)->log($query);
        });
    }

    private function registerHttpLogger(): void
    {
        if (!Config::get('logger.enabled', true) || !Config::get('logger.token')) {
            return;
        }

        if ($this->app->runningUnitTests()) {
            return;
        }

        Http::globalRequestMiddleware(HttpRequestLogger::requestMiddleware());
        Http::globalResponseMiddleware(HttpRequestLogger::responseMiddleware());
    }
}