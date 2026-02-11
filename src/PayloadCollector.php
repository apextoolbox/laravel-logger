<?php

namespace ApexToolbox\Logger;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class PayloadCollector
{
    private static ?string $requestId = null;
    private static ?array $incomingRequest = null;
    private static array $logs = [];
    private static array $outgoingRequests = [];
    private static bool $sent = false;

    public static function setRequestId(string $requestId): void
    {
        static::$requestId = $requestId;
    }

    public static function getRequestId(): ?string
    {
        return static::$requestId;
    }

    /**
     * Collect request and response data
     */
    public static function collect(Request $request, $response, float $startTime, ?float $endTime = null): void
    {
        if (! static::isEnabled()) {
            return;
        }

        $endTime = $endTime ?: microtime(true);

        static::$incomingRequest = [
            'method' => $request->method(),
            'uri' => $request->getRequestUri(),
            'headers' => static::filterHeaders($request->headers->all()),
            'payload' => static::filterBody($request->all()),
            'ip_address' => static::getRealIpAddress($request),
            'user_agent' => $request->userAgent(),
            'status_code' => $response ? $response->getStatusCode() : null,
            'response' => $response ? static::getResponseContent($response) : null,
            'duration' => round(($endTime - $startTime) * 1000),
        ];
    }

    /**
     * Add log entry
     */
    public static function addLog(array $logData): void
    {
        if (!static::isEnabled()) {
            return;
        }

        static::$logs[] = $logData;
    }

    public static function addOutgoingRequest(array $requestData): void
    {
        if (!static::isEnabled()) {
            return;
        }

        static::$outgoingRequests[] = $requestData;
    }

    /**
     * Send collected data
     */
    public static function send(): void
    {
        if (!static::isEnabled() || static::$sent) {
            return;
        }

        if (!static::$incomingRequest && empty(static::$logs) && empty(static::$outgoingRequests)) {
            return;
        }

        try {
            $payload = static::buildPayload();
            static::sendPayload($payload);
            static::$sent = true;
        } catch (Throwable $e) {
            // Silently fail to avoid disrupting the application
        }
    }

    /**
     * Clear collected data (for next request)
     */
    public static function clear(): void
    {
        static::$requestId = null;
        static::$incomingRequest = null;
        static::$logs = [];
        static::$outgoingRequests = [];
        static::$sent = false;
    }

    /**
     * Check if logging is enabled
     */
    private static function isEnabled(): bool
    {
        return Config::get('logger.enabled', true) && Config::get('logger.token');
    }

    private static function buildPayload(): array
    {
        $payload = [
            'trace_id' => Str::uuid7()->toString(),
        ];

        if (static::$incomingRequest) {
            $payload['request'] = static::$incomingRequest;
        }

        if (!empty(static::$logs)) {
            $payload['logs'] = static::$logs;
        }

        if (!empty(static::$outgoingRequests)) {
            $payload['outgoing_requests'] = static::$outgoingRequests;
        }

        return $payload;
    }

    /**
     * Send payload to endpoint
     */
    private static function sendPayload(array $payload): void
    {
        $url = static::getEndpointUrl();

        try {
            $client = new \GuzzleHttp\Client(['timeout' => 5]);
            $client->post($url, [
                'json' => $payload,
                'headers' => [
                    'Authorization' => 'Bearer ' . Config::get('logger.token'),
                    'Content-Type' => 'application/json',
                ],
            ]);
        } catch (Throwable $e) {
            // Silently fail
        }
    }

    /**
     * Get endpoint URL
     */
    private static function getEndpointUrl(): string
    {
        return env('APEX_TOOLBOX_DEV_ENDPOINT') ?: 'https://apextoolbox.com/api/v1/telemetry';
    }

    /**
     * Filter headers to exclude sensitive data
     */
    public static function filterHeaders(array $headers): array
    {
        $excludeFields = Config::get('logger.headers.exclude', []);

        return static::recursivelyFilterSensitiveData($headers, $excludeFields);
    }

    /**
     * Filter body to exclude sensitive data
     */
    public static function filterBody(array $body): array
    {
        $excludeFields = Config::get('logger.body.exclude', []);
        $maskFields = Config::get('logger.body.mask', []);

        return static::recursivelyFilterSensitiveData($body, $excludeFields, $maskFields);
    }

    /**
     * Filter response content - parses JSON and applies response filtering, truncates non-JSON > 10KB
     */
    public static function filterResponseContent(string $content, ?string $contentType): array|string
    {
        if ($contentType && str_contains($contentType, 'application/json')) {
            $decoded = json_decode($content, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $excludeFields = Config::get('logger.response.exclude', []);
                $maskFields = Config::get('logger.response.mask', []);

                return static::recursivelyFilterSensitiveData($decoded, $excludeFields, $maskFields);
            }
        }

        if (strlen($content) > 10000) {
            return substr($content, 0, 10000) . '... [truncated]';
        }

        return $content;
    }

    /**
     * Get response content with filtering
     */
    private static function getResponseContent(Response $response): false|array|string
    {
        $content = $response->getContent();
        $contentType = $response->headers->get('content-type');

        return static::filterResponseContent($content, $contentType);
    }

    /**
     * Get real IP address from request
     */
    private static function getRealIpAddress(Request $request): ?string
    {
        $headers = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_FORWARDED',
                   'HTTP_X_CLUSTER_CLIENT_IP', 'HTTP_FORWARDED_FOR', 'HTTP_FORWARDED'];

        foreach ($headers as $header) {
            if ($request->server($header)) {
                $ips = explode(',', $request->server($header));
                $ip = trim($ips[0]);

                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $ip;
                }
            }
        }

        return $request->ip();
    }

    /**
     * Recursively filter sensitive data
     */
    private static function recursivelyFilterSensitiveData(
        array $data,
        array $excludeFields,
        array $maskFields = [],
        string $maskValue = '*******'
    ): array {
        $filtered = [];

        foreach ($data as $key => $value) {
            $keyLower = strtolower($key);

            if (in_array($keyLower, array_map('strtolower', $excludeFields))) {
                continue;
            }

            if (in_array($keyLower, array_map('strtolower', $maskFields))) {
                $filtered[$key] = $maskValue;
                continue;
            }

            if (is_array($value)) {
                $filtered[$key] = static::recursivelyFilterSensitiveData($value, $excludeFields, $maskFields, $maskValue);
            } else {
                $filtered[$key] = $value;
            }
        }

        return $filtered;
    }
}
