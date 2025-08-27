<?php

namespace ApexToolbox\Logger\Tests;

use ApexToolbox\Logger\PayloadCollector;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Config;
use Exception;

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
        $requestDataProperty = $reflection->getProperty('requestData');
        $requestDataProperty->setAccessible(true);
        $requestData = $requestDataProperty->getValue();

        $responseDataProperty = $reflection->getProperty('responseData');
        $responseDataProperty->setAccessible(true);
        $responseData = $responseDataProperty->getValue();

        $this->assertNotNull($requestData);
        $this->assertNotNull($responseData);
        $this->assertEquals('POST', $requestData['method']);
        $this->assertEquals('/api/test', $requestData['uri']);
        $this->assertEquals(['key' => 'value'], $requestData['payload']);
        $this->assertEquals(200, $responseData['status_code']);
        $this->assertEqualsWithDelta(100, $responseData['duration'], 1); // 100ms ± 1ms
    }

    public function test_collect_ignores_when_disabled()
    {
        Config::set('logger.enabled', false);
        Config::set('logger.token', 'test-token');

        $request = Request::create('/api/test', 'GET');
        $response = new Response('test', 200);

        PayloadCollector::collect($request, $response, microtime(true));

        $reflection = new \ReflectionClass(PayloadCollector::class);
        $requestDataProperty = $reflection->getProperty('requestData');
        $requestDataProperty->setAccessible(true);
        
        $this->assertNull($requestDataProperty->getValue());
    }

    public function test_collect_ignores_when_no_token()
    {
        Config::set('logger.enabled', true);
        Config::set('logger.token', '');

        $request = Request::create('/api/test', 'GET');
        $response = new Response('test', 200);

        PayloadCollector::collect($request, $response, microtime(true));

        $reflection = new \ReflectionClass(PayloadCollector::class);
        $requestDataProperty = $reflection->getProperty('requestData');
        $requestDataProperty->setAccessible(true);
        
        $this->assertNull($requestDataProperty->getValue());
    }

    public function test_set_exception_stores_exception_data()
    {
        Config::set('logger.enabled', true);
        Config::set('logger.token', 'test-token');

        $exception = new Exception('Test exception', 500);
        PayloadCollector::setException($exception);

        $reflection = new \ReflectionClass(PayloadCollector::class);
        $exceptionDataProperty = $reflection->getProperty('exceptionData');
        $exceptionDataProperty->setAccessible(true);
        $exceptionData = $exceptionDataProperty->getValue();

        $this->assertNotNull($exceptionData);
        $this->assertEquals('Test exception', $exceptionData['message']);
        $this->assertEquals('Exception', $exceptionData['class']);
        $this->assertEquals(500, $exceptionData['code']);
        $this->assertArrayHasKey('hash', $exceptionData);
        $this->assertArrayHasKey('stack_trace', $exceptionData);
    }

    public function test_set_exception_respects_should_report()
    {
        Config::set('logger.enabled', true);
        Config::set('logger.token', 'test-token');

        // Mock exception handler that returns false for shouldReport
        $mockHandler = $this->createMock(\Illuminate\Contracts\Debug\ExceptionHandler::class);
        $mockHandler->method('shouldReport')->willReturn(false);
        $this->app->instance(\Illuminate\Contracts\Debug\ExceptionHandler::class, $mockHandler);

        $exception = new Exception('Should not be reported');
        PayloadCollector::setException($exception);

        $reflection = new \ReflectionClass(PayloadCollector::class);
        $exceptionDataProperty = $reflection->getProperty('exceptionData');
        $exceptionDataProperty->setAccessible(true);
        
        $this->assertNull($exceptionDataProperty->getValue());
    }

    public function test_send_creates_unified_payload()
    {
        Http::fake();

        Config::set('logger.enabled', true);
        Config::set('logger.token', 'test-token');

        $request = Request::create('/api/test', 'POST', ['key' => 'value']);
        $response = new Response('{"result": "success"}', 201);
        $exception = new Exception('Test exception');

        PayloadCollector::collect($request, $response, microtime(true));
        PayloadCollector::setException($exception);
        PayloadCollector::send();

        Http::assertSent(function ($request) {
            $data = $request->data();

            // Should include request data
            $this->assertEquals('POST', $data['method']);
            $this->assertEquals('/api/test', $data['uri']);
            $this->assertArrayHasKey('payload', $data);

            // Should include response data
            $this->assertEquals(201, $data['status_code']);
            $this->assertArrayHasKey('response', $data);
            $this->assertArrayHasKey('duration', $data);

            // Should include exception data
            $this->assertArrayHasKey('exception', $data);
            $this->assertEquals('Test exception', $data['exception']['message']);

            // Should include metadata
            $this->assertArrayHasKey('timestamp', $data);

            return $request->url() === 'https://apextoolbox.com/api/v1/logs';
        });
    }

    public function test_send_only_once()
    {
        Http::fake();

        Config::set('logger.enabled', true);
        Config::set('logger.token', 'test-token');

        $request = Request::create('/api/test', 'GET');
        $response = new Response('test', 200);

        PayloadCollector::collect($request, $response, microtime(true));
        PayloadCollector::send();
        PayloadCollector::send(); // Second call should not send

        Http::assertSentCount(1);
    }

    public function test_send_skips_when_no_data()
    {
        Http::fake();

        Config::set('logger.enabled', true);
        Config::set('logger.token', 'test-token');

        PayloadCollector::send(); // No data collected

        Http::assertNothingSent();
    }

    public function test_clear_resets_all_data()
    {
        Config::set('logger.enabled', true);
        Config::set('logger.token', 'test-token');

        $request = Request::create('/api/test', 'GET');
        $response = new Response('test', 200);
        $exception = new Exception('Test exception');

        PayloadCollector::collect($request, $response, microtime(true));
        PayloadCollector::setException($exception);
        PayloadCollector::clear();

        $reflection = new \ReflectionClass(PayloadCollector::class);
        
        $requestDataProperty = $reflection->getProperty('requestData');
        $requestDataProperty->setAccessible(true);
        
        $responseDataProperty = $reflection->getProperty('responseData');
        $responseDataProperty->setAccessible(true);
        
        $exceptionDataProperty = $reflection->getProperty('exceptionData');
        $exceptionDataProperty->setAccessible(true);

        $this->assertNull($requestDataProperty->getValue());
        $this->assertNull($responseDataProperty->getValue());
        $this->assertNull($exceptionDataProperty->getValue());
    }

    public function test_collect_handles_null_response()
    {
        Config::set('logger.enabled', true);
        Config::set('logger.token', 'test-token');

        $request = Request::create('/api/test', 'GET');

        PayloadCollector::collect($request, null, microtime(true));

        $reflection = new \ReflectionClass(PayloadCollector::class);
        $responseDataProperty = $reflection->getProperty('responseData');
        $responseDataProperty->setAccessible(true);
        $responseData = $responseDataProperty->getValue();

        $this->assertNotNull($responseData);
        $this->assertNull($responseData['status_code']);
        $this->assertNull($responseData['response']);
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
        $responseDataProperty = $reflection->getProperty('responseData');
        $responseDataProperty->setAccessible(true);
        $responseData = $responseDataProperty->getValue();

        $this->assertEquals(500, $responseData['duration']);
    }

    public function test_send_uses_dev_endpoint_when_available()
    {
        Http::fake();

        Config::set('logger.enabled', true);
        Config::set('logger.token', 'test-token');
        
        $originalValue = env('APEX_TOOLBOX_DEV_ENDPOINT');
        putenv('APEX_TOOLBOX_DEV_ENDPOINT=https://dev.apextoolbox.com/api/v1/logs');

        $request = Request::create('/api/test', 'GET');
        $response = new Response('test', 200);

        PayloadCollector::collect($request, $response, microtime(true));
        PayloadCollector::send();

        Http::assertSent(function ($request) {
            return $request->url() === 'https://dev.apextoolbox.com/api/v1/logs';
        });

        // Restore original value
        if ($originalValue !== false) {
            putenv("APEX_TOOLBOX_DEV_ENDPOINT=$originalValue");
        } else {
            putenv('APEX_TOOLBOX_DEV_ENDPOINT');
        }
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

    public function test_send_includes_logs_in_unified_payload()
    {
        Http::fake();

        Config::set('logger.enabled', true);
        Config::set('logger.token', 'test-token');

        $request = Request::create('/api/test', 'GET');
        $response = new Response('test', 200);
        
        $logData1 = ['level' => 'INFO', 'message' => 'First log'];
        $logData2 = ['level' => 'ERROR', 'message' => 'Error log'];

        PayloadCollector::collect($request, $response, microtime(true));
        PayloadCollector::addLog($logData1);
        PayloadCollector::addLog($logData2);
        PayloadCollector::send();

        Http::assertSent(function ($request) use ($logData1, $logData2) {
            $data = $request->data();

            // Should include logs in unified payload
            $this->assertArrayHasKey('logs', $data);
            $this->assertCount(2, $data['logs']);
            $this->assertEquals($logData1, $data['logs'][0]);
            $this->assertEquals($logData2, $data['logs'][1]);

            // Should also include request/response data
            $this->assertArrayHasKey('method', $data);
            $this->assertArrayHasKey('status_code', $data);

            return true;
        });
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
        Http::fake();

        Config::set('logger.enabled', true);
        Config::set('logger.token', 'test-token');

        // Add logs without request/response data
        PayloadCollector::addLog(['level' => 'INFO', 'message' => 'Standalone log']);
        PayloadCollector::send();

        Http::assertSent(function ($request) {
            $data = $request->data();

            // Should include logs
            $this->assertArrayHasKey('logs', $data);
            $this->assertCount(1, $data['logs']);
            $this->assertEquals('Standalone log', $data['logs'][0]['message']);

            // Should include metadata
            
            $this->assertArrayHasKey('timestamp', $data);

            // Should not include request/response data
            $this->assertArrayNotHasKey('method', $data);
            $this->assertArrayNotHasKey('status_code', $data);

            return true;
        });
    }
}