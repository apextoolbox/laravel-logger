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
            'stack_trace' => static::prepareStackTrace($exception->getTrace()),
            'timestamp' => now()->toISOString(),

            'context' => [
                'environment' => app()->environment(),
                'php_version' => PHP_VERSION,
                'laravel_version' => app()->version(),
            ],
        ];
    }

    /**
     * Prepare stack trace with code context for each frame
     */
    protected static function prepareStackTrace(array $trace): array
    {
        $basePath = base_path();
        $vendorPath = base_path('vendor');
        $frames = [];

        foreach ($trace as $entry) {
            if (!isset($entry['file'])) continue;

            // Remove args to avoid sensitive data
            unset($entry['args']);

            $isAppCode = str_starts_with($entry['file'], $basePath)
                && !str_starts_with($entry['file'], $vendorPath);

            $frame = [
                'file' => str_replace($basePath . DIRECTORY_SEPARATOR, '', $entry['file']),
                'line' => $entry['line'] ?? 0,
                'function' => $entry['function'] ?? '',
                'class' => $entry['class'] ?? '',
                'in_app' => $isAppCode,
                'code_context' => static::extractCodeContext($entry['file'], $entry['line'] ?? 0)
            ];

            $frames[] = $frame;
        }

        return $frames;
    }

    /**
     * Extract code context around a specific line
     */
    protected static function extractCodeContext(string $file, int $line): ?array
    {
        if (!file_exists($file) || !is_readable($file)) {
            return null;
        }

        $lines = file($file, FILE_IGNORE_NEW_LINES);
        if (!$lines) return null;

        $startLine = max(1, $line - 10);
        $endLine = min(count($lines), $line + 5);

        $context = [];
        for ($i = $startLine; $i <= $endLine; $i++) {
            $code = $lines[$i - 1] ?? '';

            // Preserve whitespace by converting to HTML entities
            $code = str_replace(["\t", " "], ["&#9;", "&#32;"], $code);

            $context[] = [
                'line_number' => $i,
                'code' => $code,
                'is_error_line' => $i === $line,
            ];
        }

        return [
            'lines' => $context,
            'context_start' => $startLine,
            'context_end' => $endLine
        ];
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

        } catch (Throwable) {
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