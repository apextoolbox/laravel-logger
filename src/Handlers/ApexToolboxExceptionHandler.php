<?php

namespace ApexToolbox\Logger\Handlers;

use ApexToolbox\Logger\PayloadCollector;
use Throwable;

class ApexToolboxExceptionHandler
{
    /**
     * Capture an exception
     */
    public static function capture(Throwable $exception): void
    {
        PayloadCollector::setException($exception);
    }
}