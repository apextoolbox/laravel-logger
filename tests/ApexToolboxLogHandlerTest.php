<?php

namespace ApexToolbox\Logger\Tests;

use ApexToolbox\Logger\Handlers\ApexToolboxLogHandler;
use ApexToolbox\Logger\LogBuffer;
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
        LogBuffer::flush();
        LogBuffer::flush(LogBuffer::HTTP_CATEGORY);
    }

    public function test_handler_adds_to_buffer(): void
    {
        Config::set('logger.token', 'test-token');
        $handler = new ApexToolboxLogHandler();
        
        $record = new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: 'test',
            level: Level::Info,
            message: 'Test message',
            context: ['key' => 'value']
        );

        $handler->handle($record);

        // Should be added to both default and HTTP categories
        $defaultEntries = LogBuffer::get();
        $httpEntries = LogBuffer::get(LogBuffer::HTTP_CATEGORY);
        
        $this->assertCount(1, $defaultEntries);
        $this->assertCount(1, $httpEntries);
        $this->assertEquals('Test message', $defaultEntries[0]['message']);
        $this->assertEquals('INFO', $defaultEntries[0]['level']);
    }

    public function test_handler_skips_without_token(): void
    {
        Config::set('logger.token', null);
        $handler = new ApexToolboxLogHandler();
        
        $record = new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: 'test',
            level: Level::Info,
            message: 'Test message',
            context: []
        );

        $handler->handle($record);

        $entries = LogBuffer::get();
        $this->assertCount(0, $entries);
    }

    public function test_flush_buffer_sends_http_request(): void
    {
        Config::set('logger.token', 'test-token');
        
        // Add some data to the buffer
        LogBuffer::add(['message' => 'test']);
        
        ApexToolboxLogHandler::flushBuffer();

        Http::assertSent(function ($request) {
            return $request->hasHeader('Authorization', 'Bearer test-token') &&
                   isset($request->data()['logs']) &&
                   is_array($request->data()['logs']);
        });
    }
}