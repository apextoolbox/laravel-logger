<?php

namespace ApexToolbox\Logger;

class LogBuffer
{
    protected static array $entries = [];

    public static function add(array $log): void
    {
        self::$entries[] = $log;
    }

    public static function all(): array
    {
        return self::$entries;
    }

    public static function flush(): array
    {
        $entries = self::$entries;
        self::$entries = [];
        return $entries;
    }
}
