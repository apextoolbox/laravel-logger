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

    public function test_filter_headers_includes_all_when_no_exclusions()
    {
        Config::set('logger.headers.exclude', []);
        
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

    public function test_filter_response_excludes_sensitive_fields()
    {
        Config::set('logger.response.exclude', ['password', 'token', 'secret']);
        
        $response = [
            'user' => 'John',
            'password' => 'secret123',
            'token' => 'bearer-token',
            'secret' => 'api-key',
            'data' => ['id' => 123]
        ];
        
        $filtered = $this->invokePrivateMethod('filterResponse', [$response]);
        
        $this->assertArrayNotHasKey('password', $filtered);
        $this->assertArrayNotHasKey('token', $filtered);
        $this->assertArrayNotHasKey('secret', $filtered);
        $this->assertArrayHasKey('user', $filtered);
        $this->assertArrayHasKey('data', $filtered);
    }

    public function test_filter_response_truncates_large_content()
    {
        Config::set('logger.response.max_size', 20);
        
        $response = ['large_field' => str_repeat('x', 100)];
        
        $filtered = $this->invokePrivateMethod('filterResponse', [$response]);
        
        $this->assertArrayHasKey('_truncated', $filtered);
        $this->assertEquals('Response too large, truncated', $filtered['_truncated']);
    }

    public function test_get_response_content_filters_json_response()
    {
        Config::set('logger.response.exclude', ['password', 'token']);
        
        $data = ['user' => 'John', 'password' => 'secret', 'token' => 'abc123'];
        $response = new JsonResponse($data);
        
        $content = $this->invokePrivateMethod('getResponseContent', [$response]);
        
        $this->assertArrayNotHasKey('password', $content);
        $this->assertArrayNotHasKey('token', $content);
        $this->assertArrayHasKey('user', $content);
    }

    public function test_get_real_ip_address_from_cloudflare()
    {
        $request = Request::create('/test', 'GET');
        $request->headers->set('CF-Connecting-IP', '203.0.113.1');
        
        $ip = $this->invokePrivateMethod('getRealIpAddress', [$request]);
        
        $this->assertEquals('203.0.113.1', $ip);
    }

    public function test_get_real_ip_address_from_x_forwarded_for()
    {
        $request = Request::create('/test', 'GET');
        $request->headers->set('X-Forwarded-For', '203.0.113.2, 192.168.1.1');
        
        $ip = $this->invokePrivateMethod('getRealIpAddress', [$request]);
        
        $this->assertEquals('203.0.113.2', $ip);
    }

    public function test_get_real_ip_address_skips_private_ranges()
    {
        $request = Request::create('/test', 'GET');
        $request->headers->set('X-Forwarded-For', '192.168.1.1');
        
        $ip = $this->invokePrivateMethod('getRealIpAddress', [$request]);
        
        // Should fallback to request IP since 192.168.1.1 is private
        $this->assertEquals('127.0.0.1', $ip);
    }

    public function test_get_real_ip_address_fallback_to_request_ip()
    {
        $request = Request::create('/test', 'GET');
        // Mock the request IP method
        $request = Mockery::mock(Request::class);
        $request->shouldReceive('header')->andReturn(null);
        $request->shouldReceive('ip')->andReturn('127.0.0.1');
        
        $ip = $this->invokePrivateMethod('getRealIpAddress', [$request]);
        
        $this->assertEquals('127.0.0.1', $ip);
    }

    public function test_prepare_tracking_data_calculates_duration()
    {
        // Define LARAVEL_START constant for this test
        if (!defined('LARAVEL_START')) {
            define('LARAVEL_START', microtime(true) - 0.1); // 100ms ago
        }
        
        $request = Request::create('/test', 'GET');
        $response = new Response('test');
        
        $data = $this->invokePrivateMethod('prepareTrackingData', [$request, $response]);
        
        $this->assertArrayHasKey('duration', $data);
        $this->assertIsNumeric($data['duration']);
        $this->assertGreaterThan(0, $data['duration']);
    }

    public function test_prepare_tracking_data_includes_ip_address()
    {
        $request = Request::create('/test', 'GET');
        $request->headers->set('CF-Connecting-IP', '203.0.113.4');
        $response = new Response('test');
        
        $data = $this->invokePrivateMethod('prepareTrackingData', [$request, $response]);
        
        $this->assertArrayHasKey('ip_address', $data);
        $this->assertEquals('203.0.113.4', $data['ip_address']);
    }

    public function test_recursive_filtering_removes_nested_sensitive_data()
    {
        $excludeFields = ['password', 'token', 'ssn'];
        
        $data = [
            'user' => [
                'name' => 'John',
                'password' => 'secret123',
                'profile' => [
                    'email' => 'john@example.com',
                    'ssn' => '123-45-6789',
                    'address' => 'Main St',
                    'nested' => [
                        'token' => 'bearer-token',
                        'public_info' => 'visible'
                    ]
                ]
            ],
            'token' => 'top-level-token',
            'public_data' => 'visible'
        ];
        
        $filtered = $this->invokePrivateMethod('recursivelyFilterSensitiveData', [$data, $excludeFields]);
        
        // Top level sensitive data should be removed
        $this->assertArrayNotHasKey('token', $filtered);
        $this->assertArrayHasKey('public_data', $filtered);
        $this->assertArrayHasKey('user', $filtered);
        
        // Nested sensitive data should be removed
        $this->assertArrayNotHasKey('password', $filtered['user']);
        $this->assertArrayHasKey('name', $filtered['user']);
        $this->assertArrayHasKey('profile', $filtered['user']);
        
        // Deeply nested sensitive data should be removed
        $this->assertArrayNotHasKey('ssn', $filtered['user']['profile']);
        $this->assertArrayHasKey('email', $filtered['user']['profile']);
        $this->assertArrayHasKey('address', $filtered['user']['profile']);
        $this->assertArrayHasKey('nested', $filtered['user']['profile']);
        
        // Very deeply nested sensitive data should be removed
        $this->assertArrayNotHasKey('token', $filtered['user']['profile']['nested']);
        $this->assertArrayHasKey('public_info', $filtered['user']['profile']['nested']);
    }

    public function test_filter_body_uses_recursive_filtering()
    {
        Config::set('logger.body.exclude', ['password', 'secret']);
        
        $body = [
            'name' => 'John',
            'user' => [
                'password' => 'secret123',
                'email' => 'john@example.com',
                'nested' => [
                    'secret' => 'api-key',
                    'public' => 'data'
                ]
            ]
        ];
        
        $filtered = $this->invokePrivateMethod('filterBody', [$body]);
        
        $this->assertArrayHasKey('name', $filtered);
        $this->assertArrayHasKey('user', $filtered);
        $this->assertArrayNotHasKey('password', $filtered['user']);
        $this->assertArrayHasKey('email', $filtered['user']);
        $this->assertArrayNotHasKey('secret', $filtered['user']['nested']);
        $this->assertArrayHasKey('public', $filtered['user']['nested']);
    }

    public function test_filter_response_uses_recursive_filtering()
    {
        Config::set('logger.response.exclude', ['token', 'private_key']);
        
        $response = [
            'status' => 'success',
            'user' => [
                'id' => 123,
                'token' => 'bearer-token',
                'credentials' => [
                    'private_key' => 'rsa-key',
                    'public_key' => 'public-rsa'
                ]
            ]
        ];
        
        $filtered = $this->invokePrivateMethod('filterResponse', [$response]);
        
        $this->assertArrayHasKey('status', $filtered);
        $this->assertArrayHasKey('user', $filtered);
        $this->assertArrayHasKey('id', $filtered['user']);
        $this->assertArrayNotHasKey('token', $filtered['user']);
        $this->assertArrayHasKey('credentials', $filtered['user']);
        $this->assertArrayNotHasKey('private_key', $filtered['user']['credentials']);
        $this->assertArrayHasKey('public_key', $filtered['user']['credentials']);
    }

    public function test_recursive_filtering_is_case_insensitive()
    {
        $excludeFields = ['Password', 'TOKEN'];
        
        $data = [
            'password' => 'secret',
            'token' => 'bearer',
            'PASSWORD' => 'secret2',
            'Token' => 'bearer2',
            'user' => [
                'password' => 'nested-secret',
                'TOKEN' => 'nested-bearer'
            ]
        ];
        
        $filtered = $this->invokePrivateMethod('recursivelyFilterSensitiveData', [$data, $excludeFields]);
        
        // All variations should be filtered regardless of case
        $this->assertArrayNotHasKey('password', $filtered);
        $this->assertArrayNotHasKey('token', $filtered);
        $this->assertArrayNotHasKey('PASSWORD', $filtered);
        $this->assertArrayNotHasKey('Token', $filtered);
        $this->assertArrayNotHasKey('password', $filtered['user']);
        $this->assertArrayNotHasKey('TOKEN', $filtered['user']);
    }

    public function test_masking_replaces_sensitive_fields_with_default_value()
    {
        $excludeFields = [];
        $maskFields = ['ssn', 'phone'];
        
        $data = [
            'name' => 'John',
            'ssn' => '123-45-6789',
            'phone' => '555-1234',
            'email' => 'john@example.com'
        ];
        
        $filtered = $this->invokePrivateMethod('recursivelyFilterSensitiveData', [$data, $excludeFields, $maskFields]);
        
        $this->assertEquals('John', $filtered['name']);
        $this->assertEquals('*******', $filtered['ssn']);
        $this->assertEquals('*******', $filtered['phone']);
        $this->assertEquals('john@example.com', $filtered['email']);
    }

    public function test_masking_works_with_nested_arrays()
    {
        $excludeFields = [];
        $maskFields = ['ssn', 'phone'];
        
        $data = [
            'user' => [
                'name' => 'John',
                'ssn' => '123-45-6789',
                'profile' => [
                    'phone' => '555-1234',
                    'address' => 'Main St'
                ]
            ]
        ];
        
        $filtered = $this->invokePrivateMethod('recursivelyFilterSensitiveData', [$data, $excludeFields, $maskFields]);
        
        $this->assertEquals('John', $filtered['user']['name']);
        $this->assertEquals('*******', $filtered['user']['ssn']);
        $this->assertEquals('*******', $filtered['user']['profile']['phone']);
        $this->assertEquals('Main St', $filtered['user']['profile']['address']);
    }

    public function test_masking_is_case_insensitive()
    {
        $excludeFields = [];
        $maskFields = ['SSN', 'Phone'];
        
        $data = [
            'ssn' => '123-45-6789',
            'phone' => '555-1234',
            'SSN' => '987-65-4321',
            'PHONE' => '555-5678'
        ];
        
        $filtered = $this->invokePrivateMethod('recursivelyFilterSensitiveData', [$data, $excludeFields, $maskFields]);
        
        $this->assertEquals('*******', $filtered['ssn']);
        $this->assertEquals('*******', $filtered['phone']);
        $this->assertEquals('*******', $filtered['SSN']);
        $this->assertEquals('*******', $filtered['PHONE']);
    }

    public function test_masking_with_custom_mask_value()
    {
        $excludeFields = [];
        $maskFields = ['ssn'];
        $customMaskValue = '[MASKED]';
        
        $data = ['ssn' => '123-45-6789'];
        
        $filtered = $this->invokePrivateMethod('recursivelyFilterSensitiveData', [$data, $excludeFields, $maskFields, $customMaskValue]);
        
        $this->assertEquals('[MASKED]', $filtered['ssn']);
    }

    public function test_exclude_takes_precedence_over_mask()
    {
        $excludeFields = ['ssn'];
        $maskFields = ['ssn', 'phone'];
        
        $data = [
            'ssn' => '123-45-6789',
            'phone' => '555-1234'
        ];
        
        $filtered = $this->invokePrivateMethod('recursivelyFilterSensitiveData', [$data, $excludeFields, $maskFields]);
        
        // SSN should be excluded (not present), phone should be masked
        $this->assertArrayNotHasKey('ssn', $filtered);
        $this->assertEquals('*******', $filtered['phone']);
    }

    public function test_filter_body_uses_mask_configuration()
    {
        Config::set('logger.body.exclude', []);
        Config::set('logger.body.mask', ['ssn', 'phone']);
        
        $body = [
            'name' => 'John',
            'ssn' => '123-45-6789',
            'phone' => '555-1234'
        ];
        
        $filtered = $this->invokePrivateMethod('filterBody', [$body]);
        
        $this->assertEquals('John', $filtered['name']);
        $this->assertEquals('*******', $filtered['ssn']);
        $this->assertEquals('*******', $filtered['phone']);
    }

    public function test_filter_response_uses_mask_configuration()
    {
        Config::set('logger.response.exclude', []);
        Config::set('logger.response.mask', ['email', 'address']);
        
        $response = [
            'status' => 'success',
            'user' => [
                'id' => 123,
                'email' => 'john@example.com',
                'address' => 'Main St'
            ]
        ];
        
        $filtered = $this->invokePrivateMethod('filterResponse', [$response]);
        
        $this->assertEquals('success', $filtered['status']);
        $this->assertEquals(123, $filtered['user']['id']);
        $this->assertEquals('*******', $filtered['user']['email']);
        $this->assertEquals('*******', $filtered['user']['address']);
    }

    public function test_get_response_content_handles_non_array_json_response()
    {
        // Test integer response
        $response = new JsonResponse(123);
        $content = $this->invokePrivateMethod('getResponseContent', [$response]);
        $this->assertEquals(123, $content);
        
        // Test string response
        $response = new JsonResponse('hello world');
        $content = $this->invokePrivateMethod('getResponseContent', [$response]);
        $this->assertEquals('hello world', $content);
        
        // Test boolean response
        $response = new JsonResponse(true);
        $content = $this->invokePrivateMethod('getResponseContent', [$response]);
        $this->assertTrue($content);
        
        // Test null response (JsonResponse converts null to empty array)
        $response = new JsonResponse(null);
        $content = $this->invokePrivateMethod('getResponseContent', [$response]);
        $this->assertEquals([], $content);
    }

    public function test_get_response_content_filters_array_json_response_but_not_primitives()
    {
        Config::set('logger.response.exclude', ['password']);
        
        // Array response should be filtered
        $arrayData = ['user' => 'John', 'password' => 'secret'];
        $response = new JsonResponse($arrayData);
        $content = $this->invokePrivateMethod('getResponseContent', [$response]);
        
        $this->assertIsArray($content);
        $this->assertEquals('John', $content['user']);
        $this->assertArrayNotHasKey('password', $content);
        
        // Primitive responses should pass through unchanged
        $response = new JsonResponse(42);
        $content = $this->invokePrivateMethod('getResponseContent', [$response]);
        $this->assertEquals(42, $content);
    }

    public function test_get_response_content_handles_mixed_response_types()
    {
        // Test float
        $response = new JsonResponse(3.14);
        $content = $this->invokePrivateMethod('getResponseContent', [$response]);
        $this->assertEquals(3.14, $content);
        
        // Test empty array (should still be filtered)
        $response = new JsonResponse([]);
        $content = $this->invokePrivateMethod('getResponseContent', [$response]);
        $this->assertIsArray($content);
        $this->assertEmpty($content);
        
        // Test array with numeric keys
        $response = new JsonResponse([1, 2, 3]);
        $content = $this->invokePrivateMethod('getResponseContent', [$response]);
        $this->assertIsArray($content);
        $this->assertEquals([1, 2, 3], $content);
    }

    public function test_middleware_attaches_exception_data_when_available()
    {
        Config::set('logger.enabled', true);
        Config::set('logger.token', 'test-token');
        
        // Simulate an exception being captured
        $exception = new \Exception('Test exception', 500);
        \ApexToolbox\Logger\Handlers\ApexToolboxExceptionHandler::capture($exception);
        
        $request = Request::create('/api/test', 'GET');
        $response = new Response('test response');
        
        $data = $this->invokePrivateMethod('prepareTrackingData', [$request, $response]);
        
        $this->assertArrayHasKey('exception', $data);
        $this->assertEquals('Exception', $data['exception']['class']);
        $this->assertEquals('Test exception', $data['exception']['message']);
        $this->assertEquals(500, $data['exception']['code']);
        $this->assertArrayHasKey('hash', $data['exception']);
        $this->assertArrayHasKey('timestamp', $data['exception']);
        $this->assertArrayHasKey('file_path', $data['exception']);
        $this->assertArrayHasKey('line_number', $data['exception']);
        $this->assertArrayHasKey('stack_trace', $data['exception']);
        $this->assertArrayHasKey('context', $data['exception']);
        
        // Verify stack trace structure
        $this->assertIsArray($data['exception']['stack_trace']);
        if (!empty($data['exception']['stack_trace'])) {
            $frame = $data['exception']['stack_trace'][0];
            $this->assertArrayHasKey('file', $frame);
            $this->assertArrayHasKey('line', $frame);
            $this->assertArrayHasKey('function', $frame);
            $this->assertArrayHasKey('class', $frame);
            $this->assertArrayHasKey('in_app', $frame);
            $this->assertArrayHasKey('code_context', $frame);
        }
        
        // Verify context structure
        $this->assertIsArray($data['exception']['context']);
        $this->assertArrayHasKey('environment', $data['exception']['context']);
        $this->assertArrayHasKey('php_version', $data['exception']['context']);
        $this->assertArrayHasKey('laravel_version', $data['exception']['context']);
        
        // Clean up
        \ApexToolbox\Logger\Handlers\ApexToolboxExceptionHandler::clear();
    }

    public function test_middleware_does_not_include_exception_when_none_available()
    {
        // Ensure no exception is captured
        \ApexToolbox\Logger\Handlers\ApexToolboxExceptionHandler::clear();
        
        $request = Request::create('/api/test', 'GET');
        $response = new Response('test response');
        
        $data = $this->invokePrivateMethod('prepareTrackingData', [$request, $response]);
        
        $this->assertArrayNotHasKey('exception', $data);
    }

    public function test_middleware_handles_null_response_with_exception()
    {
        Config::set('logger.enabled', true);
        Config::set('logger.token', 'test-token');
        
        $exception = new \RuntimeException('Runtime error');
        \ApexToolbox\Logger\Handlers\ApexToolboxExceptionHandler::capture($exception);
        
        $request = Request::create('/api/test', 'POST');
        
        $data = $this->invokePrivateMethod('prepareTrackingData', [$request, null]);
        
        $this->assertNull($data['status']);
        $this->assertNull($data['response']);
        $this->assertArrayHasKey('exception', $data);
        $this->assertEquals('RuntimeException', $data['exception']['class']);
        $this->assertEquals('Runtime error', $data['exception']['message']);
        $this->assertArrayHasKey('hash', $data['exception']);
        $this->assertArrayHasKey('stack_trace', $data['exception']);
        $this->assertArrayHasKey('context', $data['exception']);
        
        // Clean up
        \ApexToolbox\Logger\Handlers\ApexToolboxExceptionHandler::clear();
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