<?php

namespace ApexToolbox\Logger\Tests;

use ApexToolbox\Logger\LogBuffer;

class LogBufferTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Clear the buffer before each test
        LogBuffer::flush();
        LogBuffer::flush(LogBuffer::HTTP_CATEGORY);
    }

    public function test_can_add_log_entry(): void
    {
        $entry = ['message' => 'test', 'timestamp' => time()];
        
        LogBuffer::add($entry);
        
        $entries = LogBuffer::get();
        $this->assertCount(1, $entries);
        $this->assertEquals($entry, $entries[0]);
    }

    public function test_can_add_multiple_entries(): void
    {
        $entry1 = ['message' => 'test1', 'timestamp' => time()];
        $entry2 = ['message' => 'test2', 'timestamp' => time()];
        
        LogBuffer::add($entry1);
        LogBuffer::add($entry2);
        
        $entries = LogBuffer::get();
        $this->assertCount(2, $entries);
        $this->assertEquals($entry1, $entries[0]);
        $this->assertEquals($entry2, $entries[1]);
    }

    public function test_can_flush_entries(): void
    {
        $entry1 = ['message' => 'test1'];
        $entry2 = ['message' => 'test2'];
        
        LogBuffer::add($entry1);
        LogBuffer::add($entry2);
        
        $flushed = LogBuffer::flush();
        
        $this->assertCount(2, $flushed);
        $this->assertEquals($entry1, $flushed[0]);
        $this->assertEquals($entry2, $flushed[1]);
        
        // Buffer should be empty after flush
        $this->assertCount(0, LogBuffer::get());
    }

    public function test_can_add_with_categories(): void
    {
        $entry1 = ['message' => 'default'];
        $entry2 = ['message' => 'http'];
        
        LogBuffer::add($entry1);
        LogBuffer::add($entry2, LogBuffer::HTTP_CATEGORY);
        
        $defaultEntries = LogBuffer::get();
        $httpEntries = LogBuffer::get(LogBuffer::HTTP_CATEGORY);
        
        $this->assertCount(1, $defaultEntries);
        $this->assertCount(1, $httpEntries);
        $this->assertEquals($entry1, $defaultEntries[0]);
        $this->assertEquals($entry2, $httpEntries[0]);
    }
}