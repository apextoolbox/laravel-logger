<?php

use ApexToolbox\Logger\Handlers\ApexToolboxExceptionHandler;

if (!function_exists('logException')) {
    function logException(\Throwable $exception): void
    {
        ApexToolboxExceptionHandler::logException($exception);
    }
}
