<?php

namespace ApexToolbox\Logger\Tests;

use ApexToolbox\Logger\LogBuffer;
use ApexToolbox\Logger\Middleware\LoggerMiddleware;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Config;
use Mockery;

class LoggerMiddlewareTest extends TestCase
{
    private LoggerMiddleware $middleware;

    protected function setUp(): void
    {
        parent::setUp();
        $this->middleware = new LoggerMiddleware();
        LogBuffer::flush();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_middleware_passes_request_through()
    {
        $request = Request::create('/api/test', 'GET');
        $expectedResponse = new Response('test response');
        
        $response = $this->middleware->handle($request, function ($req) use ($expectedResponse) {
            return $expectedResponse;
        });
        
        $this->assertSame($expectedResponse, $response);
    }

    public function test_should_track_returns_false_when_no_token()
    {
        Config::set('logger.token', '');
        
        $request = Request::create('/api/test', 'GET');
        $shouldTrack = $this->invokePrivateMethod('shouldTrack', [$request]);
        
        $this->assertFalse($shouldTrack);
    }

    public function test_should_track_returns_true_for_included_paths()
    {
        Config::set('logger.token', 'test-token');
        Config::set('logger.path_filters.include', ['api/*']);
        
        $request = Request::create('/api/test', 'GET');
        $shouldTrack = $this->invokePrivateMethod('shouldTrack', [$request]);
        
        $this->assertTrue($shouldTrack);
    }

    public function test_should_track_returns_false_for_excluded_paths()
    {
        Config::set('logger.token', 'test-token');
        Config::set('logger.path_filters.include', ['api/*']);
        Config::set('logger.path_filters.exclude', ['api/health']);
        
        $request = Request::create('/api/health', 'GET');
        $shouldTrack = $this->invokePrivateMethod('shouldTrack', [$request]);
        
        $this->assertFalse($shouldTrack);
    }

    public function test_should_track_returns_false_for_non_included_paths()
    {
        Config::set('logger.token', 'test-token');
        Config::set('logger.path_filters.include', ['api/*']);
        
        $request = Request::create('/admin/test', 'GET');
        $shouldTrack = $this->invokePrivateMethod('shouldTrack', [$request]);
        
        $this->assertFalse($shouldTrack);
    }

    public function test_matches_pattern_handles_wildcards()
    {
        $this->assertTrue($this->invokePrivateMethod('matchesPattern', ['api/*', 'api/test']));
        $this->assertTrue($this->invokePrivateMethod('matchesPattern', ['api/*', 'api/users/123']));
        $this->assertFalse($this->invokePrivateMethod('matchesPattern', ['api/*', 'admin/test']));
        $this->assertTrue($this->invokePrivateMethod('matchesPattern', ['*', 'any/path']));
    }

    public function test_matches_pattern_handles_exact_matches()
    {
        $this->assertTrue($this->invokePrivateMethod('matchesPattern', ['api/health', 'api/health']));
        $this->assertFalse($this->invokePrivateMethod('matchesPattern', ['api/health', 'api/test']));
    }

    public function test_prepare_tracking_data_includes_all_fields()
    {
        $request = Request::create('/api/test', 'POST', ['name' => 'John']);
        $request->headers->set('Authorization', 'Bearer token');
        $request->headers->set('Content-Type', 'application/json');
        
        $response = new JsonResponse(['status' => 'success']);
        
        $data = $this->invokePrivateMethod('prepareTrackingData', [$request, $response]);
        
        $this->assertEquals('POST', $data['method']);
        $this->assertStringContainsString('/api/test', $data['url']);
        $this->assertIsArray($data['headers']);
        $this->assertIsArray($data['body']);
        $this->assertEquals(200, $data['status']);
        $this->assertIsArray($data['response']);
    }

    public function test_filter_headers_excludes_sensitive_headers()
    {
        Config::set('logger.headers.include_sensitive', false);
        Config::set('logger.headers.exclude', ['authorization', 'cookie']);
        
        $headers = [
            'authorization' => ['Bearer token'],
            'cookie' => ['session=123'],
            'content-type' => ['application/json'],
            'accept' => ['application/json']
        ];
        
        $filtered = $this->invokePrivateMethod('filterHeaders', [$headers]);
        
        $this->assertArrayNotHasKey('authorization', $filtered);
        $this->assertArrayNotHasKey('cookie', $filtered);
        $this->assertArrayHasKey('content-type', $filtered);
        $this->assertArrayHasKey('accept', $filtered);
    }

    public function test_filter_headers_includes_all_when_sensitive_allowed()
    {
        Config::set('logger.headers.include_sensitive', true);
        
        $headers = [
            'authorization' => ['Bearer token'],
            'content-type' => ['application/json']
        ];
        
        $filtered = $this->invokePrivateMethod('filterHeaders', [$headers]);
        
        $this->assertArrayHasKey('authorization', $filtered);
        $this->assertArrayHasKey('content-type', $filtered);
    }

    public function test_filter_body_excludes_sensitive_fields()
    {
        Config::set('logger.body.exclude', ['password', 'secret']);
        
        $body = [
            'name' => 'John',
            'password' => 'secret123',
            'secret' => 'token',
            'email' => 'john@example.com'
        ];
        
        $filtered = $this->invokePrivateMethod('filterBody', [$body]);
        
        $this->assertArrayNotHasKey('password', $filtered);
        $this->assertArrayNotHasKey('secret', $filtered);
        $this->assertArrayHasKey('name', $filtered);
        $this->assertArrayHasKey('email', $filtered);
    }

    public function test_filter_body_truncates_large_content()
    {
        Config::set('logger.body.max_size', 10);
        
        $body = ['large_field' => str_repeat('a', 100)];
        
        $filtered = $this->invokePrivateMethod('filterBody', [$body]);
        
        $this->assertArrayHasKey('_truncated', $filtered);
        $this->assertEquals('Body too large, truncated', $filtered['_truncated']);
    }

    public function test_get_response_content_handles_json_response()
    {
        $data = ['status' => 'success', 'data' => ['id' => 123]];
        $response = new JsonResponse($data);
        
        $content = $this->invokePrivateMethod('getResponseContent', [$response]);
        
        $this->assertEquals($data, $content);
    }

    public function test_get_response_content_handles_regular_response()
    {
        $response = new Response('Hello World');
        
        $content = $this->invokePrivateMethod('getResponseContent', [$response]);
        
        $this->assertEquals('Hello World', $content);
    }

    public function test_get_response_content_truncates_large_content()
    {
        Config::set('logger.body.max_size', 10);
        
        $response = new Response(str_repeat('a', 100));
        
        $content = $this->invokePrivateMethod('getResponseContent', [$response]);
        
        $this->assertStringContainsString('... [truncated]', $content);
        $this->assertLessThanOrEqual(25, strlen($content)); // 10 chars + "... [truncated]"
    }

    public function test_get_endpoint_url_returns_production_by_default()
    {
        $url = $this->invokePrivateMethod('getEndpointUrl', []);
        
        $this->assertEquals('https://apextoolbox.com/api/v1/logs', $url);
    }

    public function test_get_endpoint_url_uses_default_production_endpoint()
    {
        // This test verifies the default behavior
        $url = $this->invokePrivateMethod('getEndpointUrl', []);
        
        $this->assertEquals('https://apextoolbox.com/api/v1/logs', $url);
    }

    public function test_send_sync_request_runs_without_errors()
    {
        Http::fake();
        
        Config::set('logger.token', 'test-token');
        
        $data = [
            'method' => 'GET',
            'url' => 'https://example.com/api/test',
            'headers' => ['content-type' => 'application/json'],
            'body' => ['test' => 'data'],
            'status' => 200,
            'response' => ['success' => true]
        ];
        
        // Test that method runs without throwing exception
        try {
            $this->invokePrivateMethod('sendSyncRequest', [$data]);
            $this->assertTrue(true);
        } catch (\Exception $e) {
            $this->fail('sendSyncRequest should not throw exceptions: ' . $e->getMessage());
        }
    }

    public function test_send_sync_request_handles_exceptions_silently()
    {
        Http::fake(function () {
            throw new \Exception('Network error');
        });
        
        Config::set('logger.token', 'test-token');
        
        $data = [
            'method' => 'GET',
            'url' => 'https://example.com/api/test',
            'headers' => [],
            'body' => [],
            'status' => 200,
            'response' => []
        ];
        
        // Should not throw exception
        $this->invokePrivateMethod('sendSyncRequest', [$data]);
        
        $this->assertTrue(true); // If we get here, no exception was thrown
    }

    private function invokePrivateMethod(string $methodName, array $parameters = [], $object = null)
    {
        $target = $object ?: $this->middleware;
        $reflection = new \ReflectionClass($target);
        $method = $reflection->getMethod($methodName);
        $method->setAccessible(true);
        
        return $method->invokeArgs($target, $parameters);
    }
}