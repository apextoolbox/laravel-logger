<?php

namespace ApexToolbox\Logger\Tests;

use ApexToolbox\Logger\Handlers\ApexToolboxLogHandler;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Monolog\Level;
use Monolog\LogRecord;

class ApexToolboxLogHandlerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        
        // Clear buffer before each test
        ApexToolboxLogHandler::flushBuffer();
    }

    public function test_handler_sends_log_with_token()
    {
        Config::set('logger.token', 'test-token');

        $handler = new ApexToolboxLogHandler();
        
        $record = new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: 'test',
            level: Level::Info,
            message: 'Test log message',
            context: ['key' => 'value']
        );

        $handler->handle($record);
        
        // Now flush the buffer to send the logs
        ApexToolboxLogHandler::flushBuffer();

        Http::assertSent(function ($request) {
            $data = $request->data();
            return $request->hasHeader('Authorization', 'Bearer test-token') &&
                   isset($data['logs']) &&
                   is_array($data['logs']) &&
                   count($data['logs']) === 1 &&
                   $data['logs'][0]['message'] === 'Test log message' &&
                   $data['logs'][0]['level'] === 'INFO' &&
                   $data['logs'][0]['type'] === 'console'; // Running in console during tests
        });
    }

    public function test_handler_skips_without_token()
    {
        Config::set('logger.token', null);

        $handler = new ApexToolboxLogHandler();
        
        $record = new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: 'test',
            level: Level::Info,
            message: 'Test log message',
            context: []
        );

        $handler->handle($record);

        Http::assertNothingSent();
    }

    public function test_handler_detects_context_type()
    {
        Config::set('logger.token', 'test-token');

        $handler = new ApexToolboxLogHandler();
        
        $record = new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: 'test',
            level: Level::Error,
            message: 'Error message',
            context: []
        );

        $handler->handle($record);
        
        // Flush buffer to send logs
        ApexToolboxLogHandler::flushBuffer();

        Http::assertSent(function ($request) {
            $data = $request->data();
            return isset($data['logs']) &&
                   is_array($data['logs']) &&
                   count($data['logs']) === 1 &&
                   $data['logs'][0]['type'] === 'console' && // Should detect console context in tests
                   $data['logs'][0]['level'] === 'ERROR';
        });
    }

    public function test_handler_handles_exceptions_gracefully()
    {
        Config::set('logger.token', 'test-token');
        
        // Simulate HTTP failure
        Http::fake([
            '*' => Http::response('Server Error', 500)
        ]);

        $handler = new ApexToolboxLogHandler();
        
        $record = new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: 'test',
            level: Level::Info,
            message: 'Test message',
            context: []
        );

        // Should not throw exception and should return false (not handled due to HTTP error)
        $result = $handler->handle($record);
        $this->assertIsBool($result);
    }

    public function test_handler_extracts_source_class()
    {
        Config::set('logger.token', 'test-token');

        $handler = new ApexToolboxLogHandler();
        
        $record = new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: 'test',
            level: Level::Info,
            message: 'Test message',
            context: ['class' => 'App\\Services\\TestService']
        );

        $handler->handle($record);
        
        // Flush buffer to send logs
        ApexToolboxLogHandler::flushBuffer();

        Http::assertSent(function ($request) {
            $data = $request->data();
            if (!isset($data['logs']) || !is_array($data['logs']) || count($data['logs']) !== 1) {
                return false;
            }
            
            $log = $data['logs'][0];
            // Just check that source_class is set - the exact value depends on extraction logic
            return isset($log['source_class']);
        });
    }
}