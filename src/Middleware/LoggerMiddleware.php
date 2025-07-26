<?php

namespace ApexToolbox\Logger\Middleware;

use ApexToolbox\Logger\LogBuffer;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Config;

class LoggerMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);

        if ($this->shouldTrack($request)) {
            $data = $this->prepareTrackingData($request, $response);
            $this->sendSyncRequest($data);
        }

        return $response;
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

    protected function prepareTrackingData(Request $request, $response): array
    {
        return [
            'method' => $request->method(),
            'url' => $request->fullUrl(),
            'headers' => $this->filterHeaders($request->headers->all()),
            'body' => $this->filterBody($request->all()),
            'status' => $response->getStatusCode(),
            'response' => $this->getResponseContent($response),
        ];
    }

    protected function filterHeaders(array $headers): array
    {
        if (!Config::get('logger.headers.include_sensitive', false)) {
            $excludeHeaders = Config::get('logger.headers.exclude', []);
            return Arr::except($headers, $excludeHeaders);
        }

        return $headers;
    }

    protected function filterBody(array $body): array
    {
        $excludeFields = Config::get('logger.body.exclude', []);
        $filtered = Arr::except($body, $excludeFields);
        
        $maxSize = Config::get('logger.body.max_size', 10240);
        $serialized = json_encode($filtered);
        
        if (strlen($serialized) > $maxSize) {
            return ['_truncated' => 'Body too large, truncated'];
        }
        
        return $filtered;
    }

    protected function getResponseContent($response): array|string|null
    {
        if ($response instanceof JsonResponse) {
            return $response->getData(true);
        }

        if ($response instanceof Response) {
            $content = $response->getContent();
            $maxSize = Config::get('logger.body.max_size', 10240);
            
            if (strlen($content) > $maxSize) {
                return substr($content, 0, $maxSize) . '... [truncated]';
            }
            
            return $content;
        }

        return null;
    }

    protected function sendSyncRequest(array $data): void
    {
        try {
            $url = $this->getEndpointUrl();

            Http::withHeaders(['Authorization' => 'Bearer ' . Config::get('logger.token')])
                ->post($url, [
                    'method' => $data['method'],
                    'uri' => $data['url'],
                    'headers' => $data['headers'],
                    'payload' => $data['body'],
                    'status_code' => $data['status'],
                    'response' => $data['response'],
                    'duration' => microtime(true) - LARAVEL_START,
                    'logs' => LogBuffer::flush(),
                ]);
        } catch (\Throwable $e) {
            // Silently fail
        }
    }

    protected function getEndpointUrl(): string
    {
        // Only override endpoint if explicitly set for ApexToolbox package development
        // This requires both the dev endpoint AND a special dev flag to be set
        if (env('APEX_TOOLBOX_DEV_ENDPOINT') && env('APEX_TOOLBOX_DEV_MODE') === 'true') {
            return env('APEX_TOOLBOX_DEV_ENDPOINT');
        }

        // Production endpoint - hardcoded (used by all users, including their local dev)
        return 'https://apextoolbox.com/api/v1/logs';
    }
}
