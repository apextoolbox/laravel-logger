<?php

namespace ApexToolbox\Logger\Tests;

use ApexToolbox\Logger\Handlers\ApexToolboxLogHandler;
use ApexToolbox\Logger\PayloadCollector;
use Illuminate\Support\Facades\Config;
use Monolog\Level;
use Monolog\LogRecord;

class ApexToolboxLogHandlerTest extends TestCase
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

    public function test_handler_adds_log_to_payload_collector(): void
    {
        Config::set('logger.enabled', true);
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

        // Check that log was added to PayloadCollector
        $reflection = new \ReflectionClass(PayloadCollector::class);
        $logsProperty = $reflection->getProperty('logs');
        $logsProperty->setAccessible(true);
        $logs = $logsProperty->getValue();
        
        $this->assertCount(1, $logs);
        $this->assertEquals('Test log message', $logs[0]['message']);
        $this->assertEquals('INFO', $logs[0]['level']);
        $this->assertEquals(['key' => 'value'], $logs[0]['context']);
        $this->assertEquals('test', $logs[0]['channel']);
    }

    public function test_handler_skips_when_no_token(): void
    {
        Config::set('logger.enabled', true);
        Config::set('logger.token', '');
        
        $handler = new ApexToolboxLogHandler();
        
        $record = new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: 'test',
            level: Level::Error,
            message: 'Error message',
            context: []
        );

        $handler->handle($record);

        // Should not add log when no token
        $reflection = new \ReflectionClass(PayloadCollector::class);
        $logsProperty = $reflection->getProperty('logs');
        $logsProperty->setAccessible(true);
        $logs = $logsProperty->getValue();
        
        $this->assertEmpty($logs);
    }

    public function test_handler_skips_when_disabled(): void
    {
        Config::set('logger.enabled', false);
        Config::set('logger.token', 'test-token');
        
        $handler = new ApexToolboxLogHandler();
        
        $record = new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: 'test',
            level: Level::Warning,
            message: 'Warning message',
            context: []
        );

        $handler->handle($record);

        // Should not add log when disabled
        $reflection = new \ReflectionClass(PayloadCollector::class);
        $logsProperty = $reflection->getProperty('logs');
        $logsProperty->setAccessible(true);
        $logs = $logsProperty->getValue();
        
        $this->assertEmpty($logs);
    }

    public function test_prepare_log_data_includes_all_fields(): void
    {
        $handler = new ApexToolboxLogHandler();
        
        $record = new LogRecord(
            datetime: new \DateTimeImmutable('2025-01-01 12:00:00'),
            channel: 'custom',
            level: Level::Debug,
            message: 'Debug message',
            context: ['user_id' => 123],
            extra: [
                'class' => 'TestClass',
                'function' => 'testMethod',
                'callType' => 'static'
            ]
        );

        $method = new \ReflectionMethod(ApexToolboxLogHandler::class, 'prepareLogData');
        $method->setAccessible(true);
        $result = $method->invoke($handler, $record);
        
        $this->assertEquals('DEBUG', $result['level']);
        $this->assertEquals('Debug message', $result['message']);
        $this->assertEquals(['user_id' => 123], $result['context']);
        $this->assertEquals('2025-01-01 12:00:00', $result['timestamp']);
        $this->assertEquals('custom', $result['channel']);
        $this->assertEquals('TestClass', $result['source_class']);
        $this->assertEquals('testMethod', $result['function']);
        $this->assertEquals('static', $result['callType']);
    }

    public function test_prepare_log_data_handles_missing_extra_fields(): void
    {
        $handler = new ApexToolboxLogHandler();
        
        $record = new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: 'test',
            level: Level::Info,
            message: 'Simple message',
            context: []
        );

        $method = new \ReflectionMethod(ApexToolboxLogHandler::class, 'prepareLogData');
        $method->setAccessible(true);
        $result = $method->invoke($handler, $record);
        
        $this->assertNull($result['source_class']);
        $this->assertNull($result['function']);
        $this->assertNull($result['callType']);
    }
}