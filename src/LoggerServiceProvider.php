<?php

namespace ApexToolbox\Logger;

use ApexToolbox\Logger\Handlers\ApexToolboxLogHandler;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Event;
use Illuminate\Queue\Events\JobAttempted;
use Illuminate\Console\Events\CommandFinished;

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

        $this->publishes([
            __DIR__ . '/config/logger.php' => $this->app->configPath('logger.php'),
        ], 'logger-config');
    }
}