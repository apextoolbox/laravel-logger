<?php

namespace ApexToolbox\Logger\Tests;

use ApexToolbox\Logger\Handlers\ApexToolboxExceptionHandler;
use ApexToolbox\Logger\PayloadCollector;
use Illuminate\Support\Facades\Config;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Validation\ValidationException;
use Exception;

class ApexToolboxExceptionHandlerTest extends TestCase
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

    public function test_capture_stores_exception_when_enabled()
    {
        Config::set('logger.enabled', true);
        Config::set('logger.token', 'test-token');

        $exception = new Exception('Test exception');
        ApexToolboxExceptionHandler::capture($exception);

        // Check that exception is stored in PayloadCollector
        $reflection = new \ReflectionClass(PayloadCollector::class);
        $exceptionDataProperty = $reflection->getProperty('exceptionData');
        $exceptionDataProperty->setAccessible(true);
        $data = $exceptionDataProperty->getValue();
        
        $this->assertNotNull($data);
        $this->assertEquals('Exception', $data['class']);
        $this->assertEquals('Test exception', $data['message']);
    }

    public function test_capture_ignores_exception_when_disabled()
    {
        Config::set('logger.enabled', false);
        Config::set('logger.token', 'test-token');

        $exception = new Exception('Test exception');
        ApexToolboxExceptionHandler::capture($exception);

        // Check that no exception is stored in PayloadCollector
        $reflection = new \ReflectionClass(PayloadCollector::class);
        $exceptionDataProperty = $reflection->getProperty('exceptionData');
        $exceptionDataProperty->setAccessible(true);
        $data = $exceptionDataProperty->getValue();
        
        $this->assertNull($data);
    }

    public function test_capture_ignores_exception_when_no_token()
    {
        Config::set('logger.enabled', true);
        Config::set('logger.token', '');

        $exception = new Exception('Test exception');
        ApexToolboxExceptionHandler::capture($exception);

        // Check that no exception is stored in PayloadCollector
        $reflection = new \ReflectionClass(PayloadCollector::class);
        $exceptionDataProperty = $reflection->getProperty('exceptionData');
        $exceptionDataProperty->setAccessible(true);
        $data = $exceptionDataProperty->getValue();
        
        $this->assertNull($data);
    }


    public function test_clear_clears_payload_collector()
    {
        Config::set('logger.enabled', true);
        Config::set('logger.token', 'test-token');

        $exception = new Exception('Test exception');
        ApexToolboxExceptionHandler::capture($exception);

        PayloadCollector::clear();
        
        // Check that PayloadCollector was cleared
        $reflection = new \ReflectionClass(PayloadCollector::class);
        $exceptionDataProperty = $reflection->getProperty('exceptionData');
        $exceptionDataProperty->setAccessible(true);
        $data = $exceptionDataProperty->getValue();
        
        $this->assertNull($data);
    }

    public function test_capture_respects_laravel_should_report_method()
    {
        Config::set('logger.enabled', true);
        Config::set('logger.token', 'test-token');

        // Create a mock exception handler that returns false for shouldReport
        $mockHandler = $this->createMock(ExceptionHandler::class);
        $mockHandler->method('shouldReport')->willReturn(false);

        // Bind the mock handler to the container
        $this->app->instance(ExceptionHandler::class, $mockHandler);

        $validator = $this->app['validator']->make([], ['required_field' => 'required']);
        $exception = new ValidationException($validator);
        ApexToolboxExceptionHandler::capture($exception);

        // Check that no exception is stored in PayloadCollector
        $reflection = new \ReflectionClass(PayloadCollector::class);
        $exceptionDataProperty = $reflection->getProperty('exceptionData');
        $exceptionDataProperty->setAccessible(true);
        $data = $exceptionDataProperty->getValue();
        
        // Should be null because shouldReport returned false
        $this->assertNull($data);
    }

    public function test_capture_allows_exceptions_when_should_report_returns_true()
    {
        Config::set('logger.enabled', true);
        Config::set('logger.token', 'test-token');

        // Create a mock exception handler that returns true for shouldReport
        $mockHandler = $this->createMock(ExceptionHandler::class);
        $mockHandler->method('shouldReport')->willReturn(true);

        // Bind the mock handler to the container
        $this->app->instance(ExceptionHandler::class, $mockHandler);

        $exception = new Exception('Regular exception');
        ApexToolboxExceptionHandler::capture($exception);

        // Check that exception is stored in PayloadCollector
        $reflection = new \ReflectionClass(PayloadCollector::class);
        $exceptionDataProperty = $reflection->getProperty('exceptionData');
        $exceptionDataProperty->setAccessible(true);
        $data = $exceptionDataProperty->getValue();
        
        // Should capture the exception because shouldReport returned true
        $this->assertNotNull($data);
        $this->assertEquals('Regular exception', $data['message']);
    }
}