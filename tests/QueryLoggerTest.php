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

    public function test_detects_n1_queries_with_span_evidence(): void
    {
        Config::set('logger.enabled', true);
        Config::set('logger.token', 'test-token');

        // Simulate a sequence of queries:
        // 1. SELECT * FROM users WHERE id = 1 (context before)
        // 2. SELECT * FROM posts WHERE user_id = 1 (N+1 start)
        // 3. SELECT * FROM posts WHERE user_id = 2 (N+1)
        // 4. SELECT * FROM posts WHERE user_id = 3 (N+1)
        // 5. SELECT * FROM posts WHERE user_id = 4 (N+1 end)
        // 6. SELECT COUNT(*) FROM comments (context after)

        $this->queryLogger->log($this->createQueryEvent('SELECT * FROM users WHERE id = 1', [], 0.5));
        $this->queryLogger->log($this->createQueryEvent('SELECT * FROM posts WHERE user_id = 1', [], 1.0));
        $this->queryLogger->log($this->createQueryEvent('SELECT * FROM posts WHERE user_id = 2', [], 1.1));
        $this->queryLogger->log($this->createQueryEvent('SELECT * FROM posts WHERE user_id = 3', [], 1.2));
        $this->queryLogger->log($this->createQueryEvent('SELECT * FROM posts WHERE user_id = 4', [], 0.9));
        $this->queryLogger->log($this->createQueryEvent('SELECT COUNT(*) FROM comments', [], 0.3));

        $this->queryLogger->detectN1Queries();

        $queries = PayloadCollector::getQueries();

        $this->assertCount(1, $queries, 'Should have exactly one N+1 pattern');

        $n1Query = $queries[0];
        $this->assertTrue($n1Query['is_n1']);
        $this->assertEquals(4, $n1Query['duplicate_count']);
        $this->assertArrayHasKey('span_evidence', $n1Query);

        $spanEvidence = $n1Query['span_evidence'];

        // Check offender info
        $this->assertEquals('SELECT * FROM posts WHERE user_id = ?', $spanEvidence['offender']['sql']);
        $this->assertEquals(4, $spanEvidence['offender']['count']);
        $this->assertEquals(4.2, $spanEvidence['offender']['total_duration']);

        // Check spans structure
        $spans = $spanEvidence['spans'];
        $this->assertGreaterThanOrEqual(4, count($spans));

        // First span should be context (previous)
        $this->assertEquals('previous', $spans[0]['type']);
        $this->assertEquals('SELECT * FROM users WHERE id = ?', $spans[0]['sql']);

        // Should have repeating spans
        $repeatingSpans = array_filter($spans, fn($s) => $s['type'] === 'repeating');
        $this->assertCount(2, $repeatingSpans, 'Should have first and last repeating spans');

        // Should have collapsed indicator
        $collapsedSpans = array_filter($spans, fn($s) => $s['type'] === 'repeating_collapsed');
        $this->assertCount(1, $collapsedSpans);
        $collapsedSpan = array_values($collapsedSpans)[0];
        $this->assertEquals(2, $collapsedSpan['collapsed_count']);

        // Last span should be context (next)
        $lastSpan = end($spans);
        $this->assertEquals('next', $lastSpan['type']);
        $this->assertEquals('SELECT COUNT(*) FROM comments', $lastSpan['sql']);
    }

    public function test_detect_n1_queries_sends_one_query_per_pattern()
    {
        Config::set('logger.enabled', true);
        Config::set('logger.token', 'test-token');

        // Add same query pattern 5 times (N+1 threshold is 3)
        for ($i = 1; $i <= 5; $i++) {
            $event = $this->createQueryEvent("SELECT * FROM posts WHERE user_id = $i", [], 1.0);
            $this->queryLogger->log($event);
        }

        $this->queryLogger->detectN1Queries();

        $queries = PayloadCollector::getQueries();

        // Should only send ONE query per N+1 pattern (not all 5)
        $this->assertCount(1, $queries);
        $this->assertTrue($queries[0]['is_n1']);
        $this->assertEquals(5, $queries[0]['duplicate_count']);
        $this->assertArrayHasKey('span_evidence', $queries[0]);
    }

    public function test_detect_n1_queries_does_not_send_unique_queries()
    {
        Config::set('logger.enabled', true);
        Config::set('logger.token', 'test-token');

        $this->queryLogger->log($this->createQueryEvent('SELECT * FROM users', [], 1.0));
        $this->queryLogger->log($this->createQueryEvent('SELECT * FROM posts', [], 1.0));
        $this->queryLogger->log($this->createQueryEvent('SELECT * FROM comments', [], 1.0));

        $this->queryLogger->detectN1Queries();

        // Unique queries should not be sent to PayloadCollector
        $queries = PayloadCollector::getQueries();
        $this->assertEmpty($queries);
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
        $this->assertCount(1, $queries);
        $this->assertTrue($queries[0]['is_n1']);
        $this->assertEquals('SELECT * FROM users WHERE id = ?', $queries[0]['sql']);
    }

    public function test_handles_multiple_n1_patterns()
    {
        Config::set('logger.enabled', true);
        Config::set('logger.token', 'test-token');

        // Pattern 1: posts
        $this->queryLogger->log($this->createQueryEvent('SELECT * FROM posts WHERE user_id = 1', [], 1.0));
        $this->queryLogger->log($this->createQueryEvent('SELECT * FROM posts WHERE user_id = 2', [], 1.0));
        $this->queryLogger->log($this->createQueryEvent('SELECT * FROM posts WHERE user_id = 3', [], 1.0));

        // Pattern 2: comments
        $this->queryLogger->log($this->createQueryEvent('SELECT * FROM comments WHERE post_id = 1', [], 0.5));
        $this->queryLogger->log($this->createQueryEvent('SELECT * FROM comments WHERE post_id = 2', [], 0.5));
        $this->queryLogger->log($this->createQueryEvent('SELECT * FROM comments WHERE post_id = 3', [], 0.5));

        $this->queryLogger->detectN1Queries();

        $queries = PayloadCollector::getQueries();
        $this->assertCount(2, $queries, 'Should detect two N+1 patterns');

        // Both should have span_evidence
        foreach ($queries as $query) {
            $this->assertArrayHasKey('span_evidence', $query);
        }
    }

    public function test_span_evidence_with_no_context_before(): void
    {
        Config::set('logger.enabled', true);
        Config::set('logger.token', 'test-token');

        // Start directly with N+1 queries (no context before)
        $this->queryLogger->log($this->createQueryEvent('SELECT * FROM posts WHERE user_id = 1', [], 1.0));
        $this->queryLogger->log($this->createQueryEvent('SELECT * FROM posts WHERE user_id = 2', [], 1.0));
        $this->queryLogger->log($this->createQueryEvent('SELECT * FROM posts WHERE user_id = 3', [], 1.0));
        $this->queryLogger->log($this->createQueryEvent('SELECT COUNT(*) FROM users', [], 0.5));

        $this->queryLogger->detectN1Queries();

        $queries = PayloadCollector::getQueries();
        $spanEvidence = $queries[0]['span_evidence'];

        // Should not have 'previous' type spans
        $previousSpans = array_filter($spanEvidence['spans'], fn($s) => $s['type'] === 'previous');
        $this->assertCount(0, $previousSpans);

        // Should have 'next' type span
        $nextSpans = array_filter($spanEvidence['spans'], fn($s) => $s['type'] === 'next');
        $this->assertCount(1, $nextSpans);
    }

    public function test_span_evidence_with_no_context_after(): void
    {
        Config::set('logger.enabled', true);
        Config::set('logger.token', 'test-token');

        // Context before, then N+1 queries, but no context after
        $this->queryLogger->log($this->createQueryEvent('SELECT * FROM users', [], 0.5));
        $this->queryLogger->log($this->createQueryEvent('SELECT * FROM posts WHERE user_id = 1', [], 1.0));
        $this->queryLogger->log($this->createQueryEvent('SELECT * FROM posts WHERE user_id = 2', [], 1.0));
        $this->queryLogger->log($this->createQueryEvent('SELECT * FROM posts WHERE user_id = 3', [], 1.0));

        $this->queryLogger->detectN1Queries();

        $queries = PayloadCollector::getQueries();
        $spanEvidence = $queries[0]['span_evidence'];

        // Should have 'previous' type span
        $previousSpans = array_filter($spanEvidence['spans'], fn($s) => $s['type'] === 'previous');
        $this->assertCount(1, $previousSpans);

        // Should not have 'next' type spans
        $nextSpans = array_filter($spanEvidence['spans'], fn($s) => $s['type'] === 'next');
        $this->assertCount(0, $nextSpans);
    }

    public function test_clear_resets_sequence_index(): void
    {
        Config::set('logger.enabled', true);
        Config::set('logger.token', 'test-token');

        $this->queryLogger->log($this->createQueryEvent('SELECT * FROM users WHERE id = 1', [], 0.5));
        $this->queryLogger->clear();

        $this->queryLogger->log($this->createQueryEvent('SELECT * FROM posts WHERE id = 1', [], 0.5));
        $this->queryLogger->log($this->createQueryEvent('SELECT * FROM posts WHERE id = 2', [], 0.5));
        $this->queryLogger->log($this->createQueryEvent('SELECT * FROM posts WHERE id = 3', [], 0.5));

        $this->queryLogger->detectN1Queries();

        $queries = PayloadCollector::getQueries();
        $spanEvidence = $queries[0]['span_evidence'];

        // The users query should not appear in context since we cleared
        $previousSpans = array_filter($spanEvidence['spans'], fn($s) => $s['type'] === 'previous');
        $this->assertCount(0, $previousSpans);

        // First repeating span should have index 1 (reset after clear)
        $repeatingSpans = array_values(array_filter($spanEvidence['spans'], fn($s) => $s['type'] === 'repeating'));
        $this->assertEquals(1, $repeatingSpans[0]['index']);
    }

    private function createQueryEvent(string $sql, array $bindings, float $time): QueryExecuted
    {
        $connection = Mockery::mock(\Illuminate\Database\Connection::class);
        $connection->shouldReceive('getName')->andReturn('mysql');

        return new QueryExecuted($sql, $bindings, $time, $connection);
    }
}
