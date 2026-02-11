<?php

namespace ApexToolbox\Logger\Tests;

use ApexToolbox\Logger\Middleware\LoggerMiddleware;
use ApexToolbox\Logger\PayloadCollector;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Config;

class LoggerMiddlewareTest extends TestCase
{
    private LoggerMiddleware $middleware;

    protected function setUp(): void
    {
        parent::setUp();
        $this->middleware = new LoggerMiddleware();
        PayloadCollector::clear();
    }

    protected function tearDown(): void
    {
        PayloadCollector::clear();
        parent::tearDown();
    }

    public function test_middleware_passes_request_through()
    {
        Config::set('logger.enabled', true);
        Config::set('logger.token', 'test-token');

        $request = Request::create('/api/test', 'GET');
        $expectedResponse = new Response('test response', 200);

        $response = $this->middleware->handle($request, function ($req) use ($expectedResponse) {
            return $expectedResponse;
        });

        $this->assertSame($expectedResponse, $response);
    }

    public function test_should_track_returns_false_when_no_token()
    {
        Config::set('logger.enabled', true);
        Config::set('logger.token', '');

        $request = Request::create('/api/test', 'GET');

        $method = new \ReflectionMethod(LoggerMiddleware::class, 'shouldTrack');
        $method->setAccessible(true);

        $result = $method->invoke($this->middleware, $request);

        $this->assertFalse($result);
    }

    public function test_should_track_returns_true_for_included_paths()
    {
        Config::set('logger.enabled', true);
        Config::set('logger.token', 'test-token');
        Config::set('logger.path_filters.include', ['api/*']);
        Config::set('logger.path_filters.exclude', []);

        $request = Request::create('/api/users', 'GET');

        $method = new \ReflectionMethod(LoggerMiddleware::class, 'shouldTrack');
        $method->setAccessible(true);

        $result = $method->invoke($this->middleware, $request);

        $this->assertTrue($result);
    }

    public function test_should_track_returns_false_for_excluded_paths()
    {
        Config::set('logger.enabled', true);
        Config::set('logger.token', 'test-token');
        Config::set('logger.path_filters.include', ['api/*']);
        Config::set('logger.path_filters.exclude', ['api/health']);

        $request = Request::create('/api/health', 'GET');

        $method = new \ReflectionMethod(LoggerMiddleware::class, 'shouldTrack');
        $method->setAccessible(true);

        $result = $method->invoke($this->middleware, $request);

        $this->assertFalse($result);
    }

    public function test_should_track_returns_false_for_non_included_paths()
    {
        Config::set('logger.enabled', true);
        Config::set('logger.token', 'test-token');
        Config::set('logger.path_filters.include', ['api/*']);
        Config::set('logger.path_filters.exclude', []);

        $request = Request::create('/web/dashboard', 'GET');

        $method = new \ReflectionMethod(LoggerMiddleware::class, 'shouldTrack');
        $method->setAccessible(true);

        $result = $method->invoke($this->middleware, $request);

        $this->assertFalse($result);
    }

    public function test_matches_pattern_handles_wildcards()
    {
        $method = new \ReflectionMethod(LoggerMiddleware::class, 'matchesPattern');
        $method->setAccessible(true);

        $this->assertTrue($method->invoke($this->middleware, 'api/*', 'api/users'));
        $this->assertTrue($method->invoke($this->middleware, 'api/*', 'api/posts/123'));
        $this->assertFalse($method->invoke($this->middleware, 'api/*', 'web/dashboard'));
        $this->assertTrue($method->invoke($this->middleware, '*', 'any/path'));
    }

    public function test_matches_pattern_handles_exact_matches()
    {
        $method = new \ReflectionMethod(LoggerMiddleware::class, 'matchesPattern');
        $method->setAccessible(true);

        $this->assertTrue($method->invoke($this->middleware, 'api/health', 'api/health'));
        $this->assertFalse($method->invoke($this->middleware, 'api/health', 'api/users'));
    }

    public function test_terminate_collects_and_sends_data_when_should_track()
    {
        Config::set('logger.enabled', true);
        Config::set('logger.token', 'test-token');
        Config::set('logger.path_filters.include', ['api/*']);

        $request = Request::create('/api/test', 'POST', ['key' => 'value']);
        $response = new Response('{"result": "success"}', 201);

        $this->middleware->handle($request, function ($req) use ($response) {
            return $response;
        });

        $this->middleware->terminate($request, $response);

        // Verify data was collected via reflection
        $reflection = new \ReflectionClass(PayloadCollector::class);
        $incomingRequestProperty = $reflection->getProperty('incomingRequest');
        $incomingRequestProperty->setAccessible(true);
        $incomingRequest = $incomingRequestProperty->getValue();

        $this->assertNotNull($incomingRequest);
        $this->assertEquals('POST', $incomingRequest['method']);
        $this->assertEquals('/api/test', $incomingRequest['uri']);
        $this->assertEquals(201, $incomingRequest['status_code']);
    }

    public function test_terminate_does_not_collect_when_should_not_track()
    {
        Config::set('logger.enabled', true);
        Config::set('logger.token', 'test-token');
        Config::set('logger.path_filters.include', ['api/*']);

        $request = Request::create('/web/dashboard', 'GET'); // Not in api/*
        $response = new Response('dashboard', 200);

        // Simulate middleware workflow
        $this->middleware->handle($request, function ($req) use ($response) {
            return $response;
        });

        $this->middleware->terminate($request, $response);

        // Verify no data was collected
        $reflection = new \ReflectionClass(PayloadCollector::class);
        $incomingRequestProperty = $reflection->getProperty('incomingRequest');
        $incomingRequestProperty->setAccessible(true);

        $this->assertNull($incomingRequestProperty->getValue());
    }

    public function test_terminate_handles_exceptions_silently()
    {
        Config::set('logger.enabled', true);
        Config::set('logger.token', 'test-token');
        Config::set('logger.path_filters.include', ['api/*']);

        $request = Request::create('/api/test', 'GET');
        $response = new Response('test', 200);

        // Simulate middleware workflow
        $this->middleware->handle($request, function ($req) use ($response) {
            return $response;
        });

        // The middleware has try-catch that silently handles exceptions
        $this->middleware->terminate($request, $response);

        $this->assertTrue(true); // If we get here, no exception was thrown
    }

    public function test_middleware_clears_payload_collector_on_handle()
    {
        Config::set('logger.enabled', true);
        Config::set('logger.token', 'test-token');

        // Set some data in PayloadCollector first
        PayloadCollector::addLog(['message' => 'Previous log']);

        $request = Request::create('/api/test', 'GET');

        $this->middleware->handle($request, function ($req) {
            return new Response('test', 200);
        });

        // Check that PayloadCollector was cleared
        $reflection = new \ReflectionClass(PayloadCollector::class);
        $logsProperty = $reflection->getProperty('logs');
        $logsProperty->setAccessible(true);

        $this->assertEmpty($logsProperty->getValue());
    }
}
