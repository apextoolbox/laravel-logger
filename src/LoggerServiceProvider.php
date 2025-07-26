<?php

namespace ApexToolbox\Logger;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
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

        $this->publishes([
            __DIR__ . '/config/logger.php' => $this->app->configPath('logger.php'),
        ], 'logger-config');
    }
}