<?php

declare(strict_types=1);

namespace ApexToolbox\Logger\Middleware;

use ApexToolbox\Logger\PayloadCollector;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Symfony\Component\HttpFoundation\Response;
use Throwable;
use Closure;

class LoggerMiddleware
{
    private ?float $startTime = null;

    public function handle(Request $request, Closure $next)
    {
        PayloadCollector::clear();

        $this->startTime = defined('LARAVEL_START')
            ? LARAVEL_START
            : ($request->server('REQUEST_TIME_FLOAT') ?: microtime(true));

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        try {
            if ($this->shouldTrack($request)) {
                $endTime = microtime(true);
                
                // Use captured startTime, fallback to request time if not available
                $startTime = $this->startTime ?? (
                    defined('LARAVEL_START')
                        ? LARAVEL_START
                        : ($request->server('REQUEST_TIME_FLOAT') ?: $endTime)
                );

                PayloadCollector::collect($request, $response, $startTime, $endTime);
                PayloadCollector::send();
            }
        } catch (Throwable $e) {
            // Silently fail
        }
    }

    protected function shouldTrack(Request $request): bool
    {
        if (!Config::get('logger.token')) {
            return false;
        }

        if (!Config::get('logger.enabled', true)) {
            return false;
        }

        $path = $request->path();
        $includes = Config::get('logger.path_filters.include', ['api/*']);
        $excludes = Config::get('logger.path_filters.exclude', []);

        // Check excludes first
        foreach ($excludes as $pattern) {
            if ($this->matchesPattern($pattern, $path)) {
                return false;
            }
        }

        // Check includes
        foreach ($includes as $pattern) {
            if ($this->matchesPattern($pattern, $path)) {
                return true;
            }
        }

        return false;
    }

    protected function matchesPattern(string $pattern, string $path): bool
    {
        // Handle wildcard '*' to match everything
        if ($pattern === '*') {
            return true;
        }
        
        // Use fnmatch for pattern matching
        return fnmatch($pattern, $path);
    }
}
