<?php

namespace ApexToolbox\Logger\Http;

use ApexToolbox\Logger\PayloadCollector;
use Closure;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

class HttpRequestLogger
{
    private static array $pendingRequests = [];

    private static function shouldSkip(string $url): bool
    {
        $telemetryEndpoint = env('APEX_TOOLBOX_DEV_ENDPOINT') ?: 'https://apextoolbox.com/api/v1/telemetry';

        return str_starts_with($url, $telemetryEndpoint) || str_contains($url, 'apextoolbox.com');
    }

    public static function requestMiddleware(): Closure
    {
        return function (RequestInterface $request) {
            $url = (string) $request->getUri();

            if (!static::shouldSkip($url)) {
                static::$pendingRequests[$url] = [
                    'method' => $request->getMethod(),
                    'url' => $url,
                    'start_time' => microtime(true),
                ];
            }

            return $request;
        };
    }

    public static function responseMiddleware(): Closure
    {
        return function (ResponseInterface $response) {
            try {
                // Get URL from pending requests by checking response
                foreach (static::$pendingRequests as $url => $data) {
                    $duration = round((microtime(true) - $data['start_time']) * 1000, 2);

                    PayloadCollector::addOutgoingRequest([
                        'method' => $data['method'],
                        'uri' => $url,
                        'status_code' => $response->getStatusCode(),
                        'duration' => $duration,
                        'timestamp' => now()->toISOString(),
                    ]);

                    unset(static::$pendingRequests[$url]);
                    break; // Process one at a time
                }
            } catch (\Throwable $e) {
                // Silently fail
            }

            return $response;
        };
    }
}
