<?php

declare(strict_types=1);

namespace ApexToolbox\Logger\Handlers;

use ApexToolbox\Logger\PayloadCollector;
use Throwable;

class ExceptionHandler
{
    public static function capture(Throwable $exception): void
    {
        PayloadCollector::setException(static::buildExceptionData($exception));
    }

    private static function buildExceptionData(Throwable $exception): array
    {
        return [
            'hash' => static::generateHash($exception),
            'class' => get_class($exception),
            'message' => $exception->getMessage(),
            'code' => (string) $exception->getCode(),
            'file_path' => static::getRelativePath($exception->getFile()),
            'line_number' => $exception->getLine(),
            'source_context' => static::getSourceContext($exception->getFile(), $exception->getLine()),
            'stack_trace' => static::formatStackTrace($exception),
            'context' => [
                'environment' => config('app.env', 'production'),
            ],
        ];
    }

    private static function generateHash(Throwable $exception): string
    {
        $relativePath = static::getRelativePath($exception->getFile());

        return md5(get_class($exception) . ':' . $relativePath . ':' . $exception->getLine());
    }

    private static function getRelativePath(string $absolutePath): string
    {
        $basePath = base_path() . '/';

        if (str_starts_with($absolutePath, $basePath)) {
            return substr($absolutePath, strlen($basePath));
        }

        return $absolutePath;
    }

    private static function getSourceContext(string $filePath, int $errorLine, int $padding = 5): array
    {
        try {
            if (!is_file($filePath) || !is_readable($filePath)) {
                return [];
            }

            $fileLines = file($filePath);

            if ($fileLines === false) {
                return [];
            }

            $start = max(0, $errorLine - $padding - 1);
            $end = min(count($fileLines), $errorLine + $padding);

            // Skip leading empty lines
            while ($start < $end && trim($fileLines[$start]) === '') {
                $start++;
            }

            $codeLines = [];
            for ($i = $start; $i < $end; $i++) {
                $line = rtrim($fileLines[$i]);
                // Encode leading whitespace to survive TrimStrings middleware
                if (preg_match('/^(\s+)/', $line, $matches)) {
                    $spaces = str_replace(["\t", " "], ["&#9;", "&#32;"], $matches[1]);
                    $line = $spaces . substr($line, strlen($matches[1]));
                }
                $codeLines[] = $line;
            }

            return [
                'file' => static::getRelativePath($filePath),
                'error_line' => $errorLine,
                'start_line' => $start + 1,
                'code' => implode("\n", $codeLines),
            ];
        } catch (Throwable) {
            return [];
        }
    }

    private static function formatStackTrace(Throwable $exception): string
    {
        $basePath = base_path() . '/';
        $frames = $exception->getTrace();
        $lines = [];
        $vendorCount = 0;

        foreach ($frames as $i => $frame) {
            $file = $frame['file'] ?? '[internal]';
            $line = $frame['line'] ?? 0;
            $call = '';

            if (isset($frame['class'])) {
                $call = $frame['class'] . ($frame['type'] ?? '::') . ($frame['function'] ?? '');
            } elseif (isset($frame['function'])) {
                $call = $frame['function'];
            }

            $relativeFile = str_replace($basePath, '', $file);
            $isVendor = str_starts_with($relativeFile, 'vendor/');

            if ($isVendor) {
                $vendorCount++;
                // Show first vendor frame after app code, skip the rest
                if ($vendorCount > 1) {
                    continue;
                }
            } else {
                $vendorCount = 0;
            }

            $lines[] = "#$i $relativeFile($line): $call()";
        }

        $trace = implode("\n", $lines);

        if (strlen($trace) > 10000) {
            $trace = substr($trace, 0, 10000) . "\n... [truncated]";
        }

        return $trace;
    }
}
