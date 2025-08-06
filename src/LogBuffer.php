<?php

namespace ApexToolbox\Logger;

namespace ApexToolbox\Logger;

class LogBuffer
{
    protected static array $entries = [];

    public const DEFAULT_CATEGORY = 'default';

    public const HTTP_CATEGORY = 'http';

    /**
     * Add a log entry under a specific category.
     */
    public static function add(array $log, string $category = self::DEFAULT_CATEGORY): void
    {
        if (!isset(self::$entries[$category])) {
            self::$entries[$category] = [];
        }

        self::$entries[$category][] = $log;
    }

    /**
     * Get all logs for all categories.
     */
    public static function all(): array
    {
        return self::$entries;
    }

    /**
     * Get logs for a specific category.
     */
    public static function get(string $category = self::DEFAULT_CATEGORY): array
    {
        return self::$entries[$category] ?? [];
    }

    /**
     * Flush logs for a specific category.
     */
    public static function flush(string $category = self::DEFAULT_CATEGORY): array
    {
        $entries = self::$entries[$category] ?? [];
        unset(self::$entries[$category]);
        return $entries;
    }
}
