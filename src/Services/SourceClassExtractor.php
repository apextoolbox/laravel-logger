<?php

namespace ApexToolbox\Logger\Services;

use Monolog\LogRecord;

class SourceClassExtractor
{
    /**
     * Classes to ignore when extracting source class from backtrace
     */
    protected array $ignoreClasses = [
        // Laravel framework classes
        'Illuminate\\Log\\',
        'Illuminate\\Foundation\\',
        'Monolog\\',
        
        // Our own logging classes
        'ApexToolbox\\Logger\\',
        
        // Common logging utilities
        'Psr\\Log\\',
    ];

    public function extract(LogRecord $record): ?string
    {
        // First, check if source class is provided in the log context
        $sourceClass = $this->extractFromContext($record->extra);

        if ($sourceClass) {
            return $sourceClass;
        }

        // If not found in context, extract from backtrace
        return $this->extractFromBacktrace();
    }

    protected function extractFromContext(array $context): ?string
    {
        // Check common context keys where class information might be stored
        $contextKeys = ['class', 'source_class', 'service', 'job', 'command'];
        
        foreach ($contextKeys as $key) {
            if (isset($context[$key]) && is_string($context[$key])) {
                $className = $context[$key];
                if ($this->isValidClassName($className)) {
                    return $className;
                }
            }
        }

        return null;
    }

    protected function extractFromBacktrace(): ?string
    {
        $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 20);

        foreach ($backtrace as $trace) {
            if (!isset($trace['class'])) {
                continue;
            }

            $className = $trace['class'];

            // Skip internal logging classes
            if ($this->shouldIgnoreClass($className)) {
                continue;
            }

            // Found a valid source class
            if ($this->isValidClassName($className)) {
                return $className;
            }
        }

        return null;
    }

    protected function shouldIgnoreClass(string $className): bool
    {
        foreach ($this->ignoreClasses as $ignorePattern) {
            if (strpos($className, $ignorePattern) === 0) {
                return true;
            }
        }

        return false;
    }

    protected function isValidClassName(string $className): bool
    {
        // Basic validation that it looks like a class name
        if (empty($className)) {
            return false;
        }

        // Should contain namespace separators or be a simple class name
        if (!preg_match('/^[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff\\\\]*$/', $className)) {
            return false;
        }

        // Should not be one of the ignored patterns
        return !$this->shouldIgnoreClass($className);
    }
}