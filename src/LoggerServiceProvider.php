<?php

namespace ApexToolbox\Logger;

use ApexToolbox\Logger\Handlers\ApexToolboxLogHandler;
use ApexToolbox\Logger\Handlers\ApexToolboxExceptionHandler;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Event;
use Illuminate\Queue\Events\JobAttempted;
use Illuminate\Console\Events\CommandFinished;
use Throwable;

class LoggerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/config/logger.php', 'logger');
    }

    public function boot(): void
    {
        Event::listen(JobAttempted::class, function () {
            ApexToolboxLogHandler::flushBuffer();
        });

        Event::listen(CommandFinished::class, function () {
            ApexToolboxLogHandler::flushBuffer();
        });

        // Register exception handler using Laravel's Handler interface
        $this->app->extend(ExceptionHandler::class, function ($handler, $app) {
            $handler->reportable(function (Throwable $exception) {
                ApexToolboxExceptionHandler::capture($exception);
            });
            
            return $handler;
        });

        $this->publishes([
            __DIR__ . '/config/logger.php' => $this->app->configPath('logger.php'),
        ], 'logger-config');
    }
}