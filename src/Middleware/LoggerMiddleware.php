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
use Illuminate\Support\Str;
use Throwable;

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
            'ip_address' => $this->getRealIpAddress($request),
        ];
    }

    protected function getRealIpAddress(Request $request): string
    {
        $headers = [
            'CF-Connecting-IP',     // Cloudflare
            'X-Forwarded-For',      // Standard proxy header
            'X-Real-IP',            // Nginx proxy
            'X-Client-IP',          // Apache mod_proxy
            'HTTP_X_FORWARDED_FOR', // Alternative format
            'HTTP_X_REAL_IP',       // Alternative format
            'HTTP_CF_CONNECTING_IP', // Alternative Cloudflare format
        ];

        foreach ($headers as $header) {
            $value = $request->header($header) ?? $_SERVER[$header] ?? null;
            
            if ($value) {
                // Handle comma-separated IPs (X-Forwarded-For can contain multiple IPs)
                $ips = explode(',', $value);
                $ip = trim($ips[0]);
                
                // Validate IP address
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $ip;
                }
            }
        }

        // Fallback to request IP
        return $request->ip() ?? '127.0.0.1';
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
                    'ip_address' => $data['ip_address'],
                    'duration' => microtime(true) - LARAVEL_START,
                    'logs_trace_id' => Str::uuid7()->toString(),
                    'logs' => LogBuffer::flush(LogBuffer::HTTP_CATEGORY),
                ]);
        } catch (Throwable $e) {
            // Silently fail
        }
    }

    protected function getEndpointUrl(): string
    {
        // Only override endpoint if explicitly set for ApexToolbox package development
        if (env('APEX_TOOLBOX_DEV_ENDPOINT')) {
            return env('APEX_TOOLBOX_DEV_ENDPOINT');
        }

        // Production endpoint - hardcoded (used by all users, including their local dev)
        return 'https://apextoolbox.com/api/v1/logs';
    }
}
