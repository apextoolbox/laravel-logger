<?php

namespace ApexToolbox\Logger\Handlers;

use ApexToolbox\Logger\PayloadCollector;
use Throwable;

class ApexToolboxExceptionHandler
{
    /**
     * Log an exception
     */
    public static function logException(Throwable $exception): void
    {
        PayloadCollector::setException($exception);
    }
}