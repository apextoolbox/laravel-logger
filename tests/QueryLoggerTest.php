<?php

namespace ApexToolbox\Logger\Tests;

use ApexToolbox\Logger\Database\QueryLogger;
use ApexToolbox\Logger\PayloadCollector;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Config;
use Mockery;

class QueryLoggerTest extends TestCase
{
    private QueryLogger $queryLogger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->queryLogger = new QueryLogger();
        PayloadCollector::clear();
    }

    protected function tearDown(): void
    {
        PayloadCollector::clear();
        parent::tearDown();
    }

    public function test_log_captures_query_data()
    {
        Config::set('logger.enabled', true);
        Config::set('logger.token', 'test-token');

        $event = $this->createQueryEvent('SELECT * FROM users WHERE id = ?', [1], 2.5);

        $this->queryLogger->log($event);

        $queries = PayloadCollector::getQueries();
        $this->assertCount(1, $queries);
        $this->assertEquals('SELECT * FROM users WHERE id = ?', $queries[0]['sql']);
        $this->assertEquals([1], $queries[0]['bindings']);
        $this->assertEquals(2.5, $queries[0]['duration']);
    }

    public function test_detect_n1_queries_marks_repeated_patterns()
    {
        Config::set('logger.enabled', true);
        Config::set('logger.token', 'test-token');

        // Add same query pattern 5 times (N+1 threshold is 3)
        for ($i = 1; $i <= 5; $i++) {
            $event = $this->createQueryEvent("SELECT * FROM posts WHERE user_id = ?", [$i], 1.0);
            $this->queryLogger->log($event);
        }

        $this->queryLogger->detectN1Queries();

        $queries = PayloadCollector::getQueries();
        $this->assertCount(5, $queries);

        foreach ($queries as $query) {
            $this->assertTrue($query['is_n1']);
            $this->assertEquals(5, $query['duplicate_count']);
        }
    }

    public function test_detect_n1_queries_does_not_mark_unique_queries()
    {
        Config::set('logger.enabled', true);
        Config::set('logger.token', 'test-token');

        $this->queryLogger->log($this->createQueryEvent('SELECT * FROM users', [], 1.0));
        $this->queryLogger->log($this->createQueryEvent('SELECT * FROM posts', [], 1.0));
        $this->queryLogger->log($this->createQueryEvent('SELECT * FROM comments', [], 1.0));

        $this->queryLogger->detectN1Queries();

        $queries = PayloadCollector::getQueries();
        foreach ($queries as $query) {
            $this->assertFalse($query['is_n1']);
            $this->assertEquals(1, $query['duplicate_count']);
        }
    }

    public function test_clear_resets_query_patterns()
    {
        Config::set('logger.enabled', true);
        Config::set('logger.token', 'test-token');

        $this->queryLogger->log($this->createQueryEvent('SELECT 1', [], 1.0));
        $this->queryLogger->clear();

        // After clear, the same query should not be detected as duplicate
        $reflection = new \ReflectionClass(QueryLogger::class);
        $property = $reflection->getProperty('queryPatterns');
        $property->setAccessible(true);

        $this->assertEmpty($property->getValue($this->queryLogger));
    }

    public function test_normalizes_queries_for_pattern_matching()
    {
        Config::set('logger.enabled', true);
        Config::set('logger.token', 'test-token');

        // These should all be recognized as the same pattern
        $this->queryLogger->log($this->createQueryEvent("SELECT * FROM users WHERE id = 1", [], 1.0));
        $this->queryLogger->log($this->createQueryEvent("SELECT * FROM users WHERE id = 2", [], 1.0));
        $this->queryLogger->log($this->createQueryEvent("SELECT * FROM users WHERE id = 3", [], 1.0));

        $this->queryLogger->detectN1Queries();

        $queries = PayloadCollector::getQueries();
        foreach ($queries as $query) {
            $this->assertTrue($query['is_n1']);
        }
    }

    private function createQueryEvent(string $sql, array $bindings, float $time): QueryExecuted
    {
        $connection = Mockery::mock(\Illuminate\Database\Connection::class);
        $connection->shouldReceive('getName')->andReturn('mysql');

        return new QueryExecuted($sql, $bindings, $time, $connection);
    }
}
