<?php

namespace ApexToolbox\Logger\Database;

use ApexToolbox\Logger\PayloadCollector;
use Illuminate\Database\Events\QueryExecuted;

class QueryLogger
{
    private array $queries = [];
    private int $sequenceIndex = 0;

    public function log(QueryExecuted $event): void
    {
        $this->sequenceIndex++;

        $caller = $this->findCaller();
        $normalizedSql = $this->normalizeQuery($event->sql);

        $this->queries[] = [
            'sql' => $event->sql,
            'normalized_sql' => $normalizedSql,
            'pattern_hash' => md5($normalizedSql),
            'duration' => $event->time,
            'file_path' => $caller['file'],
            'line_number' => $caller['line'],
            'sequence_index' => $this->sequenceIndex,
            'occurred_at' => now()->toISOString(),
        ];
    }

    /**
     * Send all collected queries to the backend for analysis
     */
    public function flush(): void
    {
        if (empty($this->queries)) {
            return;
        }

        foreach ($this->queries as $query) {
            PayloadCollector::addQuery($query);
        }
    }

    public function getQueries(): array
    {
        return $this->queries;
    }

    public function clear(): void
    {
        $this->queries = [];
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
