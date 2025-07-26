<?php

namespace ApexToolbox\Logger\Tests;

use ApexToolbox\Logger\LogBuffer;
use Illuminate\Support\Facades\Log;

class LoggerServiceProviderTest extends TestCase
{
    public function test_service_provider_registers_configuration()
    {
        $this->assertTrue($this->app['config']->has('logger'));
        $this->assertEquals('test-token', $this->app['config']->get('logger.token'));
    }

    public function test_log_listener_captures_log_entries()
    {
        // Clear buffer
        LogBuffer::flush();
        
        // Trigger a log entry
        Log::info('Test message', ['key' => 'value']);
        
        $entries = LogBuffer::all();
        $this->assertCount(1, $entries);
        
        $entry = $entries[0];
        $this->assertEquals('info', $entry['level']);
        $this->assertEquals('Test message', $entry['message']);
        $this->assertEquals(['key' => 'value'], $entry['context']);
        $this->assertArrayHasKey('time', $entry);
    }

    public function test_multiple_log_entries_are_captured()
    {
        LogBuffer::flush();
        
        Log::info('First message');
        Log::warning('Second message');
        Log::error('Third message');
        
        $entries = LogBuffer::all();
        $this->assertCount(3, $entries);
        
        $this->assertEquals('info', $entries[0]['level']);
        $this->assertEquals('warning', $entries[1]['level']);
        $this->assertEquals('error', $entries[2]['level']);
    }

    public function test_log_entries_include_timestamp()
    {
        LogBuffer::flush();
        
        $beforeTime = time();
        Log::info('Test message');
        $afterTime = time();
        
        $entries = LogBuffer::all();
        $this->assertCount(1, $entries);
        
        $entry = $entries[0];
        $this->assertArrayHasKey('time', $entry);
        
        // Handle both DateTime and Carbon instances
        $this->assertTrue(
            $entry['time'] instanceof \DateTime || 
            $entry['time'] instanceof \DateTimeInterface ||
            is_object($entry['time'])
        );
        
        $timestamp = method_exists($entry['time'], 'getTimestamp') ? 
            $entry['time']->getTimestamp() : 
            $entry['time']->timestamp;
        $this->assertGreaterThanOrEqual($beforeTime, $timestamp);
        $this->assertLessThanOrEqual($afterTime, $timestamp);
    }

    public function test_log_context_is_preserved()
    {
        LogBuffer::flush();
        
        $context = [
            'user_id' => 123,
            'action' => 'login',
            'ip' => '192.168.1.1',
            'nested' => ['key' => 'value']
        ];
        
        Log::info('User logged in', $context);
        
        $entries = LogBuffer::all();
        $this->assertCount(1, $entries);
        
        $entry = $entries[0];
        $this->assertEquals($context, $entry['context']);
    }
}