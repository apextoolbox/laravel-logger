<?php

namespace ApexToolbox\Logger\Handlers;

use ApexToolbox\Logger\PayloadCollector;
use Illuminate\Support\Facades\Config;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;

class ApexToolboxLogHandler extends AbstractProcessingHandler
{
    public function __construct($level = Level::Debug, bool $bubble = true)
    {
        parent::__construct($level, $bubble);
    }

    protected function write(LogRecord $record): void
    {
        if (!Config::get('logger.token')) {
            return;
        }

        $data = $this->prepareLogData($record);

        PayloadCollector::addLog($data);
    }

    protected function prepareLogData(LogRecord $record): array
    {
        return [
            'level' => $record->level->getName(),
            'message' => $record->message,
            'context' => $record->context,
            'timestamp' => $record->datetime->format('Y-m-d H:i:s'),
            'channel' => $record->channel,
            'source_class' => $record->extra['class'] ?? null,
            'function' => $record->extra['function'] ?? null,
            'callType' => $record->extra['callType'] ?? null,
        ];
    }
}