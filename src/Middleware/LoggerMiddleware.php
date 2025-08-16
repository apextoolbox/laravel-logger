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

        try {
            if ($this->shouldTrack($request)) {
                $data = $this->prepareTrackingData($request, $response);
                $this->sendSyncRequest($data);
            }
        } catch (Throwable $e) {
            // Silently fail
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
        $start = defined('LARAVEL_START') ? LARAVEL_START : $request->server('REQUEST_TIME_FLOAT');

        return [
            'method' => $request->method(),
            'url' => $request->fullUrl(),
            'headers' => $this->filterHeaders($request->headers->all()),
            'body' => $this->filterBody($request->all()),
            'status' => $response->getStatusCode(),
            'response' => $this->getResponseContent($response),
            'ip_address' => $this->getRealIpAddress($request),
            'duration' => $start ? floor((microtime(true) - $start) * 1000) : null,
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
        $excludeHeaders = Config::get('logger.headers.exclude', []);

        return Arr::except($headers, $excludeHeaders);
    }

    protected function filterBody(array $body): array
    {
        $excludeFields = Config::get('logger.body.exclude', []);
        $maskFields = Config::get('logger.body.mask', []);
        $filtered = $this->recursivelyFilterSensitiveData($body, $excludeFields, $maskFields);
        
        $maxSize = Config::get('logger.body.max_size', 10240);
        $serialized = json_encode($filtered);
        
        if (strlen($serialized) > $maxSize) {
            return ['_truncated' => 'Body too large, truncated'];
        }
        
        return $filtered;
    }

    protected function filterResponse(array $response): array
    {
        $excludeFields = Config::get('logger.response.exclude', []);
        $maskFields = Config::get('logger.response.mask', []);
        $filtered = $this->recursivelyFilterSensitiveData($response, $excludeFields, $maskFields);

        $maxSize = Config::get('logger.response.max_size', 10240);
        $serialized = json_encode($filtered);

        if (strlen($serialized) > $maxSize) {
            return ['_truncated' => 'Response too large, truncated'];
        }

        return $filtered;
    }

    protected function getResponseContent($response): array|string|null
    {
        if ($response instanceof JsonResponse) {
            return $this->filterResponse($response->getData(true));
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
                    'duration' => $data['duration'],
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

    protected function recursivelyFilterSensitiveData(array $data, array $excludeFields, array $maskFields = [], string $maskValue = '*******'): array
    {
        $filtered = [];
        
        foreach ($data as $key => $value) {
            $keyLower = strtolower($key);
            
            // Skip if key is in exclude list (case-insensitive)
            if (in_array($keyLower, array_map('strtolower', $excludeFields))) {
                continue;
            }
            
            // Mask if key is in mask list (case-insensitive)
            if (in_array($keyLower, array_map('strtolower', $maskFields))) {
                $filtered[$key] = $maskValue;
                continue;
            }
            
            // Recursively filter nested arrays
            if (is_array($value)) {
                $filtered[$key] = $this->recursivelyFilterSensitiveData($value, $excludeFields, $maskFields, $maskValue);
            } else {
                $filtered[$key] = $value;
            }
        }
        
        return $filtered;
    }
}
