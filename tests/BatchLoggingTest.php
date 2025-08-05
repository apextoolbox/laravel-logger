<?php

namespace ApexToolbox\Logger\Tests;

use ApexToolbox\Logger\Handlers\ApexToolboxLogHandler;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Monolog\Level;
use Monolog\LogRecord;

class BatchLoggingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        
        // Clear buffer before each test
        ApexToolboxLogHandler::flushBuffer();
    }

    public function test_logs_are_buffered_instead_of_sent_immediately()
    {
        Config::set('logger.token', 'test-token');
        $handler = new ApexToolboxLogHandler();
        
        $record1 = new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: 'test',
            level: Level::Info,
            message: 'First log message',
            context: []
        );
        
        $record2 = new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: 'test',
            level: Level::Warning,
            message: 'Second log message',
            context: []
        );

        $handler->handle($record1);
        $handler->handle($record2);

        // No HTTP requests should be made yet (before flush)
        Http::assertNothingSent();
        
        // Buffer should contain both logs
        $reflection = new \ReflectionClass($handler);
        $bufferProperty = $reflection->getProperty('buffer');
        $bufferProperty->setAccessible(true);
        $buffer = $bufferProperty->getValue();
        
        $this->assertCount(2, $buffer);
    }

    public function test_flush_buffer_sends_all_logs_in_single_request()
    {
        Config::set('logger.token', 'test-token');
        $handler = new ApexToolboxLogHandler();
        
        $record1 = new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: 'test',
            level: Level::Info,
            message: 'First log message',
            context: []
        );
        
        $record2 = new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: 'test',
            level: Level::Error,
            message: 'Second log message',
            context: ['user_id' => 123]
        );

        $handler->handle($record1);
        $handler->handle($record2);

        ApexToolboxLogHandler::flushBuffer();

        Http::assertSent(function ($request) {
            $data = $request->data();
            
            if (!isset($data['logs']) || !is_array($data['logs']) || count($data['logs']) !== 2) {
                return false;
            }
            
            $firstLog = $data['logs'][0];
            $secondLog = $data['logs'][1];
            
            return $firstLog['message'] === 'First log message' &&
                   $secondLog['message'] === 'Second log message' &&
                   isset($secondLog['context']['user_id']) &&
                   $secondLog['context']['user_id'] === 123;
        });
    }

    public function test_buffer_is_cleared_after_flush()
    {
        Config::set('logger.token', 'test-token');
        $handler = new ApexToolboxLogHandler();
        
        $record = new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: 'test',
            level: Level::Info,
            message: 'Test message',
            context: []
        );

        $handler->handle($record);
        ApexToolboxLogHandler::flushBuffer();

        // Buffer should be empty after flush
        $reflection = new \ReflectionClass($handler);
        $bufferProperty = $reflection->getProperty('buffer');
        $bufferProperty->setAccessible(true);
        $buffer = $bufferProperty->getValue();
        
        $this->assertCount(0, $buffer);
    }

    public function test_flush_empty_buffer_does_nothing()
    {
        Config::set('logger.token', 'test-token');
        ApexToolboxLogHandler::flushBuffer();
        Http::assertNothingSent();
    }

    public function test_flush_handles_exceptions_gracefully()
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

        $handler->handle($record);
        
        // Should not throw exception
        ApexToolboxLogHandler::flushBuffer();
        $this->assertTrue(true);
    }

    public function test_batch_log_data_format()
    {
        Config::set('logger.token', 'test-token');
        $handler = new ApexToolboxLogHandler();
        
        $datetime = new \DateTimeImmutable('2023-12-01 15:30:45');
        $record = new LogRecord(
            datetime: $datetime,
            channel: 'custom',
            level: Level::Warning,
            message: 'Test warning message',
            context: ['user_id' => 456, 'action' => 'login']
        );

        $handler->handle($record);
        ApexToolboxLogHandler::flushBuffer();

        Http::assertSent(function ($request) use ($datetime) {
            $data = $request->data();
            $log = $data['logs'][0];
            
            return $log['level'] === 'WARNING' &&
                   $log['message'] === 'Test warning message' &&
                   $log['context']['user_id'] === 456 &&
                   $log['context']['action'] === 'login' &&
                   $log['timestamp'] === $datetime->format('Y-m-d H:i:s') &&
                   $log['channel'] === 'custom' &&
                   isset($log['type']) &&
                   isset($log['source_class']);
        });
    }

    public function test_send_method_uses_correct_endpoint()
    {
        Config::set('logger.token', 'test-token');
        ApexToolboxLogHandler::send([
            [
                'type' => 'console',
                'level' => 'INFO',
                'message' => 'Test message',
                'context' => [],
                'source_class' => null,
                'timestamp' => '2023-12-01 15:30:45',
                'channel' => 'test',
            ]
        ]);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://apextoolbox.com/api/v1/logs' &&
                   $request->hasHeader('Authorization', 'Bearer test-token') &&
                   $request->hasHeader('Content-Type', 'application/json');
        });
    }

    public function test_send_method_uses_dev_endpoint_when_configured()
    {
        Config::set('logger.token', 'test-token');
        putenv('APEX_TOOLBOX_DEV_ENDPOINT=https://dev.apextoolbox.com/api/logs');
        
        ApexToolboxLogHandler::send([
            [
                'type' => 'console',
                'level' => 'INFO', 
                'message' => 'Test message',
                'context' => [],
                'source_class' => null,
                'timestamp' => '2023-12-01 15:30:45',
                'channel' => 'test',
            ]
        ]);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://dev.apextoolbox.com/api/logs';
        });

        putenv('APEX_TOOLBOX_DEV_ENDPOINT');
    }
}