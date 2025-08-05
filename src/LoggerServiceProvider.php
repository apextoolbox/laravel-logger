<?php

namespace ApexToolbox\Logger;

use ApexToolbox\Logger\Handlers\ApexToolboxLogHandler;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Event;
use Illuminate\Queue\Events\JobAttempted;
use Illuminate\Console\Events\CommandFinished;
use function Symfony\Component\Clock\now;

class LoggerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/config/logger.php', 'logger');
    }

    public function boot(): void
    {
        Log::listen(function ($log) {
            LogBuffer::add([
                'time' => now(),
                'level' => $log->level,
                'message' => $log->message,
                'context' => $log->context,
            ]);
        });

        Event::listen(JobAttempted::class, function () {
            ApexToolboxLogHandler::flushBuffer();
        });

        Event::listen(CommandFinished::class, function () {
            ApexToolboxLogHandler::flushBuffer();
        });

        $this->publishes([
            __DIR__ . '/config/logger.php' => $this->app->configPath('logger.php'),
        ], 'logger-config');
    }
}