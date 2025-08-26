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

        $this->app->extend(ExceptionHandler::class, function ($handler, $app) {
            $handler->reportable(function (Throwable $exception) use ($handler) {
                if ($handler->shouldReport($exception)) {
                    ApexToolboxExceptionHandler::capture($exception);
                }
            });

            return $handler;
        });
    }

    public function boot(): void
    {
        Event::listen(JobAttempted::class, function () {
            ApexToolboxLogHandler::flushBuffer();
        });

        Event::listen(CommandFinished::class, function () {
            ApexToolboxLogHandler::flushBuffer();
        });

        set_exception_handler(function (Throwable $exception) {
            ApexToolboxExceptionHandler::capture($exception);
            report($exception);
        });

        register_shutdown_function(function () {
            ApexToolboxLogHandler::flushBuffer();
            
            $error = error_get_last();

            if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
                $exception = new \ErrorException(
                    $error['message'],
                    0,
                    $error['type'],
                    $error['file'],
                    $error['line']
                );

                ApexToolboxExceptionHandler::capture($exception);
            }
        });

        $this->publishes([
            __DIR__ . '/config/logger.php' => $this->app->configPath('logger.php'),
        ], 'logger-config');
    }
}