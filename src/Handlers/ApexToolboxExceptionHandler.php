<?php

namespace ApexToolbox\Logger\Handlers;

use Throwable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use ApexToolbox\Logger\LogBuffer;

class ApexToolboxExceptionHandler
{
    private static ?Throwable $currentException = null;
    private static bool $sentWithRequest = false;
    private static bool $shutdownRegistered = false;

    /**
     * Capture an exception for later sending
     */
    public static function capture(Throwable $exception): void
    {
        if (!Config::get('logger.enabled', true) || !Config::get('logger.token')) {
            return;
        }

        static::$currentException = $exception;
        static::$sentWithRequest = false;

        // Register shutdown function to send standalone if not attached to request
        static::scheduleStandaloneSend();
    }

    /**
     * Get current exception data for attachment to request payload
     * Marks exception as handled to prevent duplicate sending
     */
    public static function getForAttachment(): ?array
    {
        if (static::$currentException && !static::$sentWithRequest) {
            static::$sentWithRequest = true;

            return static::parseException(static::$currentException);
        }

        return null;
    }

    /**
     * Clear current exception (useful for testing)
     */
    public static function clear(): void
    {
        static::$currentException = null;
        static::$sentWithRequest = false;
    }

    /**
     * Parse exception into structured data
     */
    protected static function parseException(Throwable $exception): array
    {
        return [
            'hash' => static::generateExceptionHash($exception),
            'message' => $exception->getMessage(),
            'class' => get_class($exception),
            'file_path' => str_replace(base_path() . DIRECTORY_SEPARATOR, '', $exception->getFile()),
            'line_number' => $exception->getLine(),

            'code' => $exception->getCode(),
            'source_context' => static::extractSourceContext($exception->getFile(), $exception->getLine()),
            // 'stack_trace' => $exception->getTraceAsString(),
            // 'trace_array' => static::sanitizeTrace($exception->getTrace()),
            'timestamp' => now()->toISOString(),

            'context' => [
                'environment' => app()->environment(),
                // 'php_version' => PHP_VERSION,
                // 'laravel_version' => app()->version(),
            ],
        ];
    }

    /**
     * Extract source code context around the error line
     */
    protected static function extractSourceContext(string $file, int $line): ?array
    {
        try {
            if (!file_exists($file) || !is_readable($file)) {
                return null;
            }

            $lines = file($file, FILE_IGNORE_NEW_LINES);
            if (!$lines) {
                return null;
            }

            $totalLines = count($lines);

            $startLine = max(1, $line - 5);
            $endLine = min($totalLines, $line + 10);

            $context = [];
            for ($i = $startLine; $i <= $endLine; $i++) {
                $code = $lines[$i - 1] ?? ''; // Array is 0-indexed
                
                // Preserve whitespace by converting to HTML entities
                $code = str_replace(["\t", " "], ["&#9;", "&#32;"], $code);
                
                $context[] = [
                    'line_number' => $i,
                    'code' => $code,
                    'is_error_line' => $i === $line,
                ];
            }

            return [
                'file' => str_replace(base_path() . DIRECTORY_SEPARATOR, '', $file),
                'error_line' => $line,
                'context_start' => $startLine,
                'context_end' => $endLine,
                'lines' => $context,
            ];
        } catch (\Throwable $e) {
            // If we can't read the source, return null
            return null;
        }
    }

    /**
     * Sanitize stack trace to remove sensitive data and limit size
     */
    protected static function sanitizeTrace(array $trace): array
    {
        return array_map(function ($frame) {
            // Remove 'args' to avoid sensitive data and reduce payload size
            unset($frame['args']);
            return $frame;
        }, array_slice($trace, 0, 20)); // Limit to first 20 frames
    }

    /**
     * Generate unique hash for exception grouping
     */
    protected static function generateExceptionHash(Throwable $exception): string
    {
        // Create hash based on file, line, and class for grouping similar exceptions
        $key = $exception->getFile() . ':' . $exception->getLine() . ':' . get_class($exception);
        return hash('sha256', $key);
    }

    /**
     * Register shutdown function to send exception standalone if not sent with request
     */
    protected static function scheduleStandaloneSend(): void
    {
        if (static::$shutdownRegistered) {
            return;
        }

        static::$shutdownRegistered = true;

        register_shutdown_function(function () {
            if (static::$currentException && !static::$sentWithRequest) {
                static::sendStandalone();
            }
        });
    }

    /**
     * Send exception as standalone payload (not attached to request)
     */
    protected static function sendStandalone(): void
    {
        try {
            $data = [
                'type' => 'exception',
                'exception' => static::parseException(static::$currentException),
                'logs_trace_id' => Str::uuid7()->toString(),
                'logs' => LogBuffer::flush(LogBuffer::HTTP_CATEGORY),
                'timestamp' => now()->toISOString(),
            ];

            $url = static::getEndpointUrl();

            Http::withHeaders([
                    'Authorization' => 'Bearer ' . Config::get('logger.token'),
                    'Content-Type' => 'application/json',
                ])
                ->timeout(2)
                ->post($url, $data);

        } catch (Throwable $e) {
            // Silently fail to prevent infinite loops
        }
    }

    /**
     * Get the endpoint URL for sending data
     */
    protected static function getEndpointUrl(): string
    {
        if (env('APEX_TOOLBOX_DEV_ENDPOINT')) {
            return env('APEX_TOOLBOX_DEV_ENDPOINT');
        }

        return 'https://apextoolbox.com/api/v1/logs';
    }
}