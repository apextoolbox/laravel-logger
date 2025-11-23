<?php

namespace ApexToolbox\Logger\Database;

use ApexToolbox\Logger\PayloadCollector;
use Illuminate\Database\Events\QueryExecuted;

class QueryLogger
{
    private array $queryPatterns = [];

    public function log(QueryExecuted $event): void
    {
        $caller = $this->findCaller();
        $pattern = $this->normalizeQuery($event->sql);
        $patternHash = md5($pattern);

        $this->queryPatterns[$patternHash] = ($this->queryPatterns[$patternHash] ?? 0) + 1;

        PayloadCollector::addQuery([
            'sql' => $event->sql,
            'bindings' => $this->formatBindings($event->bindings),
            'duration' => $event->time,
            'file_path' => $caller['file'],
            'line_number' => $caller['line'],
            'pattern_hash' => $patternHash,
            'occurred_at' => now()->toISOString(),
        ]);
    }

    public function detectN1Queries(): void
    {
        $queries = PayloadCollector::getQueries();
        if (empty($queries)) {
            return;
        }

        $patternCounts = [];
        foreach ($queries as $query) {
            $hash = $query['pattern_hash'] ?? null;
            if ($hash) {
                $patternCounts[$hash] = ($patternCounts[$hash] ?? 0) + 1;
            }
        }

        $n1Patterns = array_filter($patternCounts, fn($count) => $count >= 3);

        $updatedQueries = [];
        foreach ($queries as $query) {
            $hash = $query['pattern_hash'] ?? null;
            $query['is_n1'] = isset($n1Patterns[$hash]);
            $query['duplicate_count'] = $patternCounts[$hash] ?? 1;
            unset($query['pattern_hash']);
            $updatedQueries[] = $query;
        }

        $this->replaceQueries($updatedQueries);
    }

    public function clear(): void
    {
        $this->queryPatterns = [];
    }

    private function replaceQueries(array $queries): void
    {
        // Use reflection to replace the queries array
        $reflection = new \ReflectionClass(PayloadCollector::class);
        $property = $reflection->getProperty('queries');
        $property->setAccessible(true);
        $property->setValue(null, $queries);
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
