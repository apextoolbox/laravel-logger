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
                $headers = PayloadCollector::filterHeaders(
                    array_map(fn($values) => implode(', ', $values), $request->getHeaders())
                );

                $bodyContent = (string) $request->getBody();
                $request->getBody()->rewind();

                $payload = json_decode($bodyContent, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($payload)) {
                    $payload = PayloadCollector::filterBody($payload);
                } else {
                    $payload = $bodyContent;
                }

                static::$pendingRequests[$url] = [
                    'method' => $request->getMethod(),
                    'url' => $url,
                    'headers' => $headers,
                    'payload' => $payload,
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

                    $responseHeaders = PayloadCollector::filterHeaders(
                        array_map(fn($values) => implode(', ', $values), $response->getHeaders())
                    );

                    $responseBody = (string) $response->getBody();
                    $response->getBody()->rewind();

                    $contentType = $response->getHeaderLine('Content-Type');
                    $responseContent = PayloadCollector::filterResponseContent($responseBody, $contentType);

                    PayloadCollector::addOutgoingRequest([
                        'method' => $data['method'],
                        'uri' => $url,
                        'headers' => $data['headers'],
                        'payload' => $data['payload'],
                        'status_code' => $response->getStatusCode(),
                        'response_headers' => $responseHeaders,
                        'response' => $responseContent,
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
