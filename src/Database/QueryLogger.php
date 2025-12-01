<?php

namespace ApexToolbox\Logger\Database;

use ApexToolbox\Logger\PayloadCollector;
use Illuminate\Database\Events\QueryExecuted;

class QueryLogger
{
    private array $queries = [];
    private array $queryPatterns = [];

    public function log(QueryExecuted $event): void
    {
        $caller = $this->findCaller();
        $pattern = $this->normalizeQuery($event->sql);
        $patternHash = md5($pattern);

        $this->queryPatterns[$patternHash] = ($this->queryPatterns[$patternHash] ?? 0) + 1;

        $this->queries[] = [
            'sql' => $event->sql,
            'bindings' => $this->formatBindings($event->bindings),
            'duration' => $event->time,
            'file_path' => $caller['file'],
            'line_number' => $caller['line'],
            'pattern_hash' => $patternHash,
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

        // Only add N+1 queries to PayloadCollector
        foreach ($this->queries as $query) {
            $hash = $query['pattern_hash'];
            if (!isset($n1Patterns[$hash])) {
                continue;
            }

            unset($query['pattern_hash']);
            $query['is_n1'] = true;
            $query['duplicate_count'] = $patternCounts[$hash];

            PayloadCollector::addQuery($query);
        }
    }

    public function clear(): void
    {
        $this->queries = [];
        $this->queryPatterns = [];
    }

    private function normalizeQuery(string $sql): string
    {
        // Replace numeric values
        $normalized = preg_replace('/\b\d+\b/', '?', $sql);
        // Replace string values in quotes
        $normalized = preg_replace('/\'[^\']*\'/', '?', $normalized);
        $normalized = preg_replace('/"[^"]*"/', '?', $normalized);
        // Replace IN clauses with multiple values
        $normalized = preg_replace('/IN\s*\([^)]+\)/i', 'IN (?)', $normalized);

        return $normalized;
    }

    private function formatBindings(array $bindings): array
    {
        return array_map(function ($binding) {
            if ($binding instanceof \DateTimeInterface) {
                return $binding->format('Y-m-d H:i:s');
            }
            if (is_object($binding)) {
                return get_class($binding);
            }
            return $binding;
        }, $bindings);
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
