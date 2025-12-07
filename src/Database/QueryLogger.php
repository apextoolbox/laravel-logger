<?php

namespace ApexToolbox\Logger\Database;

use ApexToolbox\Logger\PayloadCollector;
use Illuminate\Database\Events\QueryExecuted;

class QueryLogger
{
    private array $queries = [];
    private array $queryPatterns = [];
    private int $sequenceIndex = 0;

    public function log(QueryExecuted $event): void
    {
        $this->sequenceIndex++;

        $caller = $this->findCaller();
        $sql = $this->normalizeQuery($event->sql);
        $patternHash = md5($sql);

        $this->queryPatterns[$patternHash] = ($this->queryPatterns[$patternHash] ?? 0) + 1;

        $this->queries[] = [
            'sql' => $sql,
            'duration' => $event->time,
            'file_path' => $caller['file'],
            'line_number' => $caller['line'],
            'pattern_hash' => $patternHash,
            'sequence_index' => $this->sequenceIndex,
            'occurred_at' => now()->toISOString(),
        ];
    }

    public function detectN1Queries(): void
    {
        if (empty($this->queries)) {
            return;
        }

        $patternCounts = [];
        foreach ($this->queries as $query) {
            $hash = $query['pattern_hash'];
            $patternCounts[$hash] = ($patternCounts[$hash] ?? 0) + 1;
        }

        $n1Patterns = array_filter($patternCounts, fn($count) => $count >= 3);

        // Add one query per N+1 pattern with span evidence
        foreach ($n1Patterns as $hash => $count) {
            $firstQuery = $this->getFirstQueryByHash($hash);
            if (!$firstQuery) {
                continue;
            }

            $spanEvidence = $this->buildSpanEvidence($hash, $count);

            PayloadCollector::addQuery([
                'sql' => $firstQuery['sql'],
                'duration' => $firstQuery['duration'],
                'file_path' => $firstQuery['file_path'],
                'line_number' => $firstQuery['line_number'],
                'occurred_at' => $firstQuery['occurred_at'],
                'is_n1' => true,
                'duplicate_count' => $count,
                'span_evidence' => $spanEvidence,
            ]);
        }
    }

    private function getFirstQueryByHash(string $hash): ?array
    {
        foreach ($this->queries as $query) {
            if ($query['pattern_hash'] === $hash) {
                return $query;
            }
        }
        return null;
    }

    private function buildSpanEvidence(string $offenderHash, int $offenderCount): array
    {
        // Find all queries with the offender pattern
        $offenderIndices = [];
        $offenderDurations = [];
        $offenderSql = null;

        foreach ($this->queries as $query) {
            if ($query['pattern_hash'] === $offenderHash) {
                $offenderIndices[] = $query['sequence_index'];
                $offenderDurations[] = $query['duration'];
                $offenderSql = $query['sql'];
            }
        }

        $firstOffenderIndex = min($offenderIndices);
        $lastOffenderIndex = max($offenderIndices);
        $totalDuration = array_sum($offenderDurations);

        $spans = [];

        // Add context queries BEFORE the N+1 pattern (up to 2)
        $contextBefore = array_filter(
            $this->queries,
            fn($q) => $q['sequence_index'] < $firstOffenderIndex
        );
        usort($contextBefore, fn($a, $b) => $a['sequence_index'] <=> $b['sequence_index']);
        $contextBefore = array_slice($contextBefore, -2);

        foreach ($contextBefore as $q) {
            $spans[] = [
                'index' => $q['sequence_index'],
                'sql' => $q['sql'],
                'duration' => $q['duration'],
                'type' => 'previous',
            ];
        }

        // Add first offender
        $firstOffender = $this->getQueryByIndex($firstOffenderIndex);
        $spans[] = [
            'index' => $firstOffenderIndex,
            'sql' => $firstOffender['sql'],
            'duration' => $firstOffender['duration'],
            'type' => 'repeating',
        ];

        // Add collapsed middle (if more than 2 offenders)
        if ($offenderCount > 2) {
            $spans[] = [
                'type' => 'repeating_collapsed',
                'collapsed_count' => $offenderCount - 2,
            ];
        }

        // Add last offender (if different from first)
        if ($lastOffenderIndex !== $firstOffenderIndex) {
            $lastOffender = $this->getQueryByIndex($lastOffenderIndex);
            $spans[] = [
                'index' => $lastOffenderIndex,
                'sql' => $lastOffender['sql'],
                'duration' => $lastOffender['duration'],
                'type' => 'repeating',
            ];
        }

        // Add context queries AFTER the N+1 pattern (up to 2)
        $contextAfter = array_filter(
            $this->queries,
            fn($q) => $q['sequence_index'] > $lastOffenderIndex
        );
        usort($contextAfter, fn($a, $b) => $a['sequence_index'] <=> $b['sequence_index']);
        $contextAfter = array_slice($contextAfter, 0, 2);

        foreach ($contextAfter as $q) {
            $spans[] = [
                'index' => $q['sequence_index'],
                'sql' => $q['sql'],
                'duration' => $q['duration'],
                'type' => 'next',
            ];
        }

        return [
            'offender' => [
                'sql' => $offenderSql,
                'count' => $offenderCount,
                'total_duration' => round($totalDuration, 2),
            ],
            'spans' => $spans,
        ];
    }

    private function getQueryByIndex(int $index): ?array
    {
        foreach ($this->queries as $query) {
            if ($query['sequence_index'] === $index) {
                return $query;
            }
        }
        return null;
    }

    public function clear(): void
    {
        $this->queries = [];
        $this->queryPatterns = [];
        $this->sequenceIndex = 0;
    }

    private function normalizeQuery(string $sql): string
    {
        // Replace string values in single quotes (actual string values)
        // Note: Don't replace double-quoted identifiers ("table"."column") - those are PostgreSQL identifiers
        $normalized = preg_replace('/\'[^\']*\'/', '?', $sql);

        // Replace numeric values that appear as actual values (after operators)
        // This avoids replacing numbers in identifiers
        $normalized = preg_replace('/(?<=[=<>!\s,\(])(\s*)\d+(?:\.\d+)?(?=\s*[,\)\s]|$)/i', '$1?', $normalized);

        // Replace IN clauses with multiple values
        $normalized = preg_replace('/IN\s*\([^)]+\)/i', 'IN (?)', $normalized);

        return $normalized;
    }

    private function findCaller(): array
    {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 50);
        $basePath = base_path();

        foreach ($trace as $frame) {
            if (!isset($frame['file'])) {
                continue;
            }

            $file = $frame['file'];

            // Skip vendor files
            if (str_contains($file, '/vendor/')) {
                continue;
            }

            // Skip laravel-logger package files
            if (str_contains($file, 'laravel-logger/')) {
                continue;
            }

            // Skip Laravel framework internals
            if (str_contains($file, '/Illuminate/')) {
                continue;
            }

            return [
                'file' => str_replace($basePath . DIRECTORY_SEPARATOR, '', $file),
                'line' => $frame['line'] ?? 0,
            ];
        }

        return ['file' => null, 'line' => null];
    }
}
