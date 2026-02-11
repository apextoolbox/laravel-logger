<?php

namespace ApexToolbox\Logger\Tests;

use ApexToolbox\Logger\PayloadCollector;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Config;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use Mockery;

class PayloadCollectorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        PayloadCollector::clear();
    }

    protected function tearDown(): void
    {
        PayloadCollector::clear();
        parent::tearDown();
    }

    public function test_collect_stores_request_and_response_data()
    {
        Config::set('logger.enabled', true);
        Config::set('logger.token', 'test-token');

        $request = Request::create('/api/test', 'POST', ['key' => 'value']);
        $response = new Response('test response', 200);

        $startTime = microtime(true);
        $endTime = $startTime + 0.1; // 100ms duration

        PayloadCollector::collect($request, $response, $startTime, $endTime);

        // Use reflection to access private data
        $reflection = new \ReflectionClass(PayloadCollector::class);
        $incomingRequestProperty = $reflection->getProperty('incomingRequest');
        $incomingRequestProperty->setAccessible(true);
        $incomingRequest = $incomingRequestProperty->getValue();

        $this->assertNotNull($incomingRequest);
        $this->assertEquals('POST', $incomingRequest['method']);
        $this->assertEquals('/api/test', $incomingRequest['uri']);
        $this->assertEquals(['key' => 'value'], $incomingRequest['payload']);
        $this->assertEquals(200, $incomingRequest['status_code']);
        $this->assertEqualsWithDelta(100, $incomingRequest['duration'], 1); // 100ms ± 1ms
    }

    public function test_collect_ignores_when_disabled()
    {
        Config::set('logger.enabled', false);
        Config::set('logger.token', 'test-token');

        $request = Request::create('/api/test', 'GET');
        $response = new Response('test', 200);

        PayloadCollector::collect($request, $response, microtime(true));

        $reflection = new \ReflectionClass(PayloadCollector::class);
        $incomingRequestProperty = $reflection->getProperty('incomingRequest');
        $incomingRequestProperty->setAccessible(true);

        $this->assertNull($incomingRequestProperty->getValue());
    }

    public function test_collect_ignores_when_no_token()
    {
        Config::set('logger.enabled', true);
        Config::set('logger.token', '');

        $request = Request::create('/api/test', 'GET');
        $response = new Response('test', 200);

        PayloadCollector::collect($request, $response, microtime(true));

        $reflection = new \ReflectionClass(PayloadCollector::class);
        $incomingRequestProperty = $reflection->getProperty('incomingRequest');
        $incomingRequestProperty->setAccessible(true);

        $this->assertNull($incomingRequestProperty->getValue());
    }

    public function test_send_builds_payload_with_request_data()
    {
        Config::set('logger.enabled', true);
        Config::set('logger.token', 'test-token');

        $request = Request::create('/api/test', 'POST', ['key' => 'value']);
        $response = new Response('{"result": "success"}', 201);

        PayloadCollector::collect($request, $response, microtime(true));

        // Use reflection to verify buildPayload
        $reflection = new \ReflectionClass(PayloadCollector::class);
        $method = $reflection->getMethod('buildPayload');
        $method->setAccessible(true);
        $payload = $method->invoke(null);

        $this->assertArrayHasKey('trace_id', $payload);
        $this->assertArrayHasKey('request', $payload);
        $this->assertEquals('POST', $payload['request']['method']);
        $this->assertEquals('/api/test', $payload['request']['uri']);
        $this->assertArrayHasKey('payload', $payload['request']);
        $this->assertEquals(201, $payload['request']['status_code']);
        $this->assertArrayHasKey('response', $payload['request']);
        $this->assertArrayHasKey('duration', $payload['request']);
    }

    public function test_send_only_once()
    {
        Config::set('logger.enabled', true);
        Config::set('logger.token', 'test-token');

        $request = Request::create('/api/test', 'GET');
        $response = new Response('test', 200);

        PayloadCollector::collect($request, $response, microtime(true));

        // Mock the Guzzle client to track calls
        $sentCount = 0;
        $mock = new MockHandler([
            new GuzzleResponse(200),
            new GuzzleResponse(200),
        ]);
        $handlerStack = HandlerStack::create($mock);
        $mockClient = new Client(['handler' => $handlerStack]);

        // Use reflection to replace sendPayload behavior
        // Instead, we test via the $sent flag
        PayloadCollector::send();
        PayloadCollector::send(); // Second call should not send

        $reflection = new \ReflectionClass(PayloadCollector::class);
        $sentProperty = $reflection->getProperty('sent');
        $sentProperty->setAccessible(true);

        $this->assertTrue($sentProperty->getValue());
    }

    public function test_send_skips_when_no_data()
    {
        Config::set('logger.enabled', true);
        Config::set('logger.token', 'test-token');

        PayloadCollector::send(); // No data collected

        // Should not have set $sent since no data
        $reflection = new \ReflectionClass(PayloadCollector::class);
        $sentProperty = $reflection->getProperty('sent');
        $sentProperty->setAccessible(true);

        $this->assertFalse($sentProperty->getValue());
    }

    public function test_clear_resets_all_data()
    {
        Config::set('logger.enabled', true);
        Config::set('logger.token', 'test-token');

        $request = Request::create('/api/test', 'GET');
        $response = new Response('test', 200);

        PayloadCollector::collect($request, $response, microtime(true));
        PayloadCollector::clear();

        $reflection = new \ReflectionClass(PayloadCollector::class);

        $incomingRequestProperty = $reflection->getProperty('incomingRequest');
        $incomingRequestProperty->setAccessible(true);

        $this->assertNull($incomingRequestProperty->getValue());
    }

    public function test_collect_handles_null_response()
    {
        Config::set('logger.enabled', true);
        Config::set('logger.token', 'test-token');

        $request = Request::create('/api/test', 'GET');

        PayloadCollector::collect($request, null, microtime(true));

        $reflection = new \ReflectionClass(PayloadCollector::class);
        $incomingRequestProperty = $reflection->getProperty('incomingRequest');
        $incomingRequestProperty->setAccessible(true);
        $incomingRequest = $incomingRequestProperty->getValue();

        $this->assertNotNull($incomingRequest);
        $this->assertNull($incomingRequest['status_code']);
        $this->assertNull($incomingRequest['response']);
    }

    public function test_collect_calculates_duration_correctly()
    {
        Config::set('logger.enabled', true);
        Config::set('logger.token', 'test-token');

        $request = Request::create('/api/test', 'GET');
        $response = new Response('test', 200);

        $startTime = microtime(true);
        $endTime = $startTime + 0.5; // 500ms

        PayloadCollector::collect($request, $response, $startTime, $endTime);

        $reflection = new \ReflectionClass(PayloadCollector::class);
        $incomingRequestProperty = $reflection->getProperty('incomingRequest');
        $incomingRequestProperty->setAccessible(true);
        $incomingRequest = $incomingRequestProperty->getValue();

        $this->assertEquals(500, $incomingRequest['duration']);
    }

    public function test_add_log_stores_log_data()
    {
        Config::set('logger.enabled', true);
        Config::set('logger.token', 'test-token');

        $logData = [
            'level' => 'INFO',
            'message' => 'Test log message',
            'context' => ['key' => 'value'],
            'timestamp' => '2025-01-01 12:00:00'
        ];

        PayloadCollector::addLog($logData);

        $reflection = new \ReflectionClass(PayloadCollector::class);
        $logsProperty = $reflection->getProperty('logs');
        $logsProperty->setAccessible(true);
        $logs = $logsProperty->getValue();

        $this->assertCount(1, $logs);
        $this->assertEquals($logData, $logs[0]);
    }

    public function test_add_log_ignores_when_disabled()
    {
        Config::set('logger.enabled', false);
        Config::set('logger.token', 'test-token');

        PayloadCollector::addLog(['message' => 'test']);

        $reflection = new \ReflectionClass(PayloadCollector::class);
        $logsProperty = $reflection->getProperty('logs');
        $logsProperty->setAccessible(true);
        $logs = $logsProperty->getValue();

        $this->assertEmpty($logs);
    }

    public function test_send_includes_logs_in_payload()
    {
        Config::set('logger.enabled', true);
        Config::set('logger.token', 'test-token');

        $request = Request::create('/api/test', 'GET');
        $response = new Response('test', 200);

        $logData1 = ['level' => 'INFO', 'message' => 'First log'];
        $logData2 = ['level' => 'ERROR', 'message' => 'Error log'];

        PayloadCollector::collect($request, $response, microtime(true));
        PayloadCollector::addLog($logData1);
        PayloadCollector::addLog($logData2);

        $reflection = new \ReflectionClass(PayloadCollector::class);
        $method = $reflection->getMethod('buildPayload');
        $method->setAccessible(true);
        $payload = $method->invoke(null);

        $this->assertArrayHasKey('logs', $payload);
        $this->assertCount(2, $payload['logs']);
        $this->assertEquals($logData1, $payload['logs'][0]);
        $this->assertEquals($logData2, $payload['logs'][1]);

        $this->assertArrayHasKey('request', $payload);
        $this->assertArrayHasKey('method', $payload['request']);
        $this->assertArrayHasKey('status_code', $payload['request']);
    }

    public function test_clear_resets_logs()
    {
        Config::set('logger.enabled', true);
        Config::set('logger.token', 'test-token');

        PayloadCollector::addLog(['message' => 'test']);
        PayloadCollector::clear();

        $reflection = new \ReflectionClass(PayloadCollector::class);
        $logsProperty = $reflection->getProperty('logs');
        $logsProperty->setAccessible(true);
        $logs = $logsProperty->getValue();

        $this->assertEmpty($logs);
    }

    public function test_send_logs_only_payload()
    {
        Config::set('logger.enabled', true);
        Config::set('logger.token', 'test-token');

        PayloadCollector::addLog(['level' => 'INFO', 'message' => 'Standalone log']);

        $reflection = new \ReflectionClass(PayloadCollector::class);
        $method = $reflection->getMethod('buildPayload');
        $method->setAccessible(true);
        $payload = $method->invoke(null);

        $this->assertArrayHasKey('trace_id', $payload);
        $this->assertArrayHasKey('logs', $payload);
        $this->assertCount(1, $payload['logs']);
        $this->assertEquals('Standalone log', $payload['logs'][0]['message']);
        $this->assertArrayNotHasKey('request', $payload);
    }

    public function test_request_id_can_be_set_and_retrieved()
    {
        PayloadCollector::setRequestId('test-uuid-123');

        $this->assertEquals('test-uuid-123', PayloadCollector::getRequestId());
    }

    public function test_clear_resets_request_id()
    {
        PayloadCollector::setRequestId('test-uuid-123');
        PayloadCollector::clear();

        $this->assertNull(PayloadCollector::getRequestId());
    }

    public function test_collect_captures_user_agent()
    {
        Config::set('logger.enabled', true);
        Config::set('logger.token', 'test-token');

        $request = Request::create('/api/test', 'GET');
        $request->headers->set('User-Agent', 'TestBrowser/1.0');
        $response = new Response('test', 200);

        PayloadCollector::collect($request, $response, microtime(true));

        $reflection = new \ReflectionClass(PayloadCollector::class);
        $incomingRequestProperty = $reflection->getProperty('incomingRequest');
        $incomingRequestProperty->setAccessible(true);
        $incomingRequest = $incomingRequestProperty->getValue();

        $this->assertEquals('TestBrowser/1.0', $incomingRequest['user_agent']);
    }

    public function test_filter_headers_is_public()
    {
        Config::set('logger.headers.exclude', ['authorization']);

        $headers = PayloadCollector::filterHeaders([
            'authorization' => 'Bearer token',
            'content-type' => 'application/json',
        ]);

        $this->assertArrayNotHasKey('authorization', $headers);
        $this->assertArrayHasKey('content-type', $headers);
    }

    public function test_filter_body_is_public()
    {
        Config::set('logger.body.exclude', ['password']);
        Config::set('logger.body.mask', []);

        $body = PayloadCollector::filterBody([
            'password' => 'secret',
            'username' => 'john',
        ]);

        $this->assertArrayNotHasKey('password', $body);
        $this->assertArrayHasKey('username', $body);
    }

    public function test_filter_response_content_parses_json()
    {
        Config::set('logger.response.exclude', ['token']);
        Config::set('logger.response.mask', []);

        $content = json_encode(['token' => 'abc', 'name' => 'John']);
        $result = PayloadCollector::filterResponseContent($content, 'application/json');

        $this->assertIsArray($result);
        $this->assertArrayNotHasKey('token', $result);
        $this->assertEquals('John', $result['name']);
    }

    public function test_filter_response_content_truncates_large_non_json()
    {
        $content = str_repeat('x', 15000);
        $result = PayloadCollector::filterResponseContent($content, 'text/html');

        $this->assertIsString($result);
        $this->assertStringEndsWith('... [truncated]', $result);
        $this->assertEquals(10000 + strlen('... [truncated]'), strlen($result));
    }

    public function test_filter_response_content_returns_small_non_json_as_is()
    {
        $content = 'small response';
        $result = PayloadCollector::filterResponseContent($content, 'text/plain');

        $this->assertEquals('small response', $result);
    }

    public function test_add_outgoing_request_stores_data()
    {
        Config::set('logger.enabled', true);
        Config::set('logger.token', 'test-token');

        $requestData = [
            'method' => 'GET',
            'uri' => 'https://example.com/api',
            'headers' => ['Accept' => 'application/json'],
            'payload' => [],
            'status_code' => 200,
            'response_headers' => ['Content-Type' => 'application/json'],
            'response' => ['data' => 'test'],
            'duration' => 150.5,
            'timestamp' => '2025-01-01T00:00:00Z',
        ];

        PayloadCollector::addOutgoingRequest($requestData);

        $reflection = new \ReflectionClass(PayloadCollector::class);
        $outgoingProperty = $reflection->getProperty('outgoingRequests');
        $outgoingProperty->setAccessible(true);
        $outgoing = $outgoingProperty->getValue();

        $this->assertCount(1, $outgoing);
        $this->assertEquals($requestData, $outgoing[0]);
    }

    public function test_send_includes_outgoing_requests_in_payload()
    {
        Config::set('logger.enabled', true);
        Config::set('logger.token', 'test-token');

        PayloadCollector::addOutgoingRequest([
            'method' => 'POST',
            'uri' => 'https://example.com/api',
            'status_code' => 201,
            'duration' => 100,
            'timestamp' => '2025-01-01T00:00:00Z',
        ]);

        $reflection = new \ReflectionClass(PayloadCollector::class);
        $method = $reflection->getMethod('buildPayload');
        $method->setAccessible(true);
        $payload = $method->invoke(null);

        $this->assertArrayHasKey('outgoing_requests', $payload);
        $this->assertCount(1, $payload['outgoing_requests']);
        $this->assertEquals('POST', $payload['outgoing_requests'][0]['method']);
    }
}
