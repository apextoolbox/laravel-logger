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

    public function test_logs_query_with_all_metadata(): void
    {
        Config::set('logger.enabled', true);
        Config::set('logger.token', 'test-token');

        $this->queryLogger->log($this->createQueryEvent('SELECT * FROM users WHERE id = 1', [], 1.5));

        $queries = $this->queryLogger->getQueries();

        $this->assertCount(1, $queries);
        $this->assertEquals('SELECT * FROM users WHERE id = 1', $queries[0]['sql']);
        $this->assertEquals('SELECT * FROM users WHERE id = ?', $queries[0]['normalized_sql']);
        $this->assertNotEmpty($queries[0]['pattern_hash']);
        $this->assertEquals(1.5, $queries[0]['duration']);
        $this->assertEquals(1, $queries[0]['sequence_index']);
        $this->assertArrayHasKey('file_path', $queries[0]);
        $this->assertArrayHasKey('line_number', $queries[0]);
        $this->assertArrayHasKey('occurred_at', $queries[0]);
    }

    public function test_flush_sends_all_queries_to_payload_collector(): void
    {
        Config::set('logger.enabled', true);
        Config::set('logger.token', 'test-token');

        $this->queryLogger->log($this->createQueryEvent('SELECT * FROM users', [], 1.0));
        $this->queryLogger->log($this->createQueryEvent('SELECT * FROM posts', [], 2.0));
        $this->queryLogger->log($this->createQueryEvent('SELECT * FROM comments', [], 0.5));

        $this->queryLogger->flush();

        $queries = PayloadCollector::getQueries();
        $this->assertCount(3, $queries);
    }

    public function test_sequence_index_increments_correctly(): void
    {
        Config::set('logger.enabled', true);
        Config::set('logger.token', 'test-token');

        $this->queryLogger->log($this->createQueryEvent('SELECT 1', [], 1.0));
        $this->queryLogger->log($this->createQueryEvent('SELECT 2', [], 1.0));
        $this->queryLogger->log($this->createQueryEvent('SELECT 3', [], 1.0));

        $queries = $this->queryLogger->getQueries();

        $this->assertEquals(1, $queries[0]['sequence_index']);
        $this->assertEquals(2, $queries[1]['sequence_index']);
        $this->assertEquals(3, $queries[2]['sequence_index']);
    }

    public function test_normalizes_numeric_values(): void
    {
        Config::set('logger.enabled', true);
        Config::set('logger.token', 'test-token');

        $this->queryLogger->log($this->createQueryEvent('SELECT * FROM users WHERE id = 123', [], 1.0));

        $queries = $this->queryLogger->getQueries();
        $this->assertEquals('SELECT * FROM users WHERE id = ?', $queries[0]['normalized_sql']);
    }

    public function test_normalizes_string_values(): void
    {
        Config::set('logger.enabled', true);
        Config::set('logger.token', 'test-token');

        $this->queryLogger->log($this->createQueryEvent("SELECT * FROM users WHERE name = 'John'", [], 1.0));

        $queries = $this->queryLogger->getQueries();
        $this->assertEquals('SELECT * FROM users WHERE name = ?', $queries[0]['normalized_sql']);
    }

    public function test_normalizes_in_clauses(): void
    {
        Config::set('logger.enabled', true);
        Config::set('logger.token', 'test-token');

        $this->queryLogger->log($this->createQueryEvent('SELECT * FROM users WHERE id IN (1, 2, 3, 4)', [], 1.0));

        $queries = $this->queryLogger->getQueries();
        $this->assertEquals('SELECT * FROM users WHERE id IN (?)', $queries[0]['normalized_sql']);
    }

    public function test_same_pattern_has_same_hash(): void
    {
        Config::set('logger.enabled', true);
        Config::set('logger.token', 'test-token');

        $this->queryLogger->log($this->createQueryEvent('SELECT * FROM users WHERE id = 1', [], 1.0));
        $this->queryLogger->log($this->createQueryEvent('SELECT * FROM users WHERE id = 2', [], 1.0));
        $this->queryLogger->log($this->createQueryEvent('SELECT * FROM users WHERE id = 999', [], 1.0));

        $queries = $this->queryLogger->getQueries();

        // All should have the same pattern_hash since they normalize to the same query
        $this->assertEquals($queries[0]['pattern_hash'], $queries[1]['pattern_hash']);
        $this->assertEquals($queries[1]['pattern_hash'], $queries[2]['pattern_hash']);
    }

    public function test_different_patterns_have_different_hashes(): void
    {
        Config::set('logger.enabled', true);
        Config::set('logger.token', 'test-token');

        $this->queryLogger->log($this->createQueryEvent('SELECT * FROM users WHERE id = 1', [], 1.0));
        $this->queryLogger->log($this->createQueryEvent('SELECT * FROM posts WHERE id = 1', [], 1.0));

        $queries = $this->queryLogger->getQueries();

        $this->assertNotEquals($queries[0]['pattern_hash'], $queries[1]['pattern_hash']);
    }

    public function test_clear_resets_queries_and_sequence(): void
    {
        Config::set('logger.enabled', true);
        Config::set('logger.token', 'test-token');

        $this->queryLogger->log($this->createQueryEvent('SELECT 1', [], 1.0));
        $this->queryLogger->log($this->createQueryEvent('SELECT 2', [], 1.0));

        $this->queryLogger->clear();

        $this->assertEmpty($this->queryLogger->getQueries());

        // After clear, sequence should reset
        $this->queryLogger->log($this->createQueryEvent('SELECT 3', [], 1.0));
        $queries = $this->queryLogger->getQueries();

        $this->assertEquals(1, $queries[0]['sequence_index']);
    }

    public function test_flush_with_empty_queries_does_nothing(): void
    {
        Config::set('logger.enabled', true);
        Config::set('logger.token', 'test-token');

        $this->queryLogger->flush();

        $queries = PayloadCollector::getQueries();
        $this->assertEmpty($queries);
    }

    public function test_preserves_original_sql_with_values(): void
    {
        Config::set('logger.enabled', true);
        Config::set('logger.token', 'test-token');

        $originalSql = "SELECT * FROM users WHERE name = 'John' AND age = 25";
        $this->queryLogger->log($this->createQueryEvent($originalSql, [], 1.0));

        $queries = $this->queryLogger->getQueries();

        // Original SQL should be preserved
        $this->assertEquals($originalSql, $queries[0]['sql']);
        // Normalized SQL should have placeholders
        $this->assertEquals('SELECT * FROM users WHERE name = ? AND age = ?', $queries[0]['normalized_sql']);
    }

    private function createQueryEvent(string $sql, array $bindings, float $time): QueryExecuted
    {
        $connection = Mockery::mock(\Illuminate\Database\Connection::class);
        $connection->shouldReceive('getName')->andReturn('mysql');

        return new QueryExecuted($sql, $bindings, $time, $connection);
    }
}
