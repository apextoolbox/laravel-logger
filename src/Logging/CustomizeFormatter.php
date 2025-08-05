<?php

namespace ApexToolbox\Logger\Logging;

use Monolog\Level;
use Monolog\Processor\IntrospectionProcessor;

class CustomizeFormatter
{
    public function __invoke($logger): void
    {
        foreach ($logger->getHandlers() as $handler) {
            $handler->pushProcessor(new IntrospectionProcessor(Level::Debug, ['Illuminate\\']));
        }
    }
}