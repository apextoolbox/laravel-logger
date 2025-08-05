<?php

namespace ApexToolbox\Logger\Services;

class ContextDetector
{
    public function detect(): string
    {
        // Check if running in console (CLI)
        if (app()->runningInConsole()) {
            // Further detect if it's a queue worker
            if ($this->isQueueWorker()) {
                return 'queue';
            }
            return 'console';
        }

        // Default to HTTP context
        return 'http';
    }

    protected function isQueueWorker(): bool
    {
        // Check command line arguments for queue worker indicators
        if (isset($_SERVER['argv'])) {
            $argv = implode(' ', $_SERVER['argv']);
            
            // Laravel queue worker patterns
            if (str_contains($argv, 'queue:work')) {
                return true;
            }
            
            if (str_contains($argv, 'queue:listen')) {
                return true;
            }
            
            if (str_contains($argv, 'horizon')) {
                return true;
            }
        }

        // Check environment variables that might indicate queue processing
        if (getenv('LARAVEL_QUEUE_WORKER') === 'true') {
            return true;
        }

        // Check if we're in a queue job context by looking for queue-specific variables
        if (isset($_ENV['QUEUE_CONNECTION']) || isset($_SERVER['QUEUE_CONNECTION'])) {
            return true;
        }

        // Check process name if available
        if (function_exists('cli_get_process_title')) {
            $processTitle = cli_get_process_title();
            if ($processTitle && (
                str_contains($processTitle, 'queue:work') ||
                str_contains($processTitle, 'horizon')
            )) {
                return true;
            }
        }

        return false;
    }
}