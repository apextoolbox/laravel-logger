<?php

namespace ApexToolbox\Logger\Handlers;

use ApexToolbox\Logger\LogBuffer;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;
use Throwable;

class ApexToolboxLogHandler extends AbstractProcessingHandler
{
    public function __construct($level = Level::Debug, bool $bubble = true)
    {
        parent::__construct($level, $bubble);
    }

    protected function write(LogRecord $record): void
    {
        if (! Config::get('logger.token')) { return; }

        $data = $this->prepareLogData($record);

        LogBuffer::add($data);

        LogBuffer::add($data, LogBuffer::HTTP_CATEGORY);
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

    public static function flushBuffer(): void
    {
        if (empty(LogBuffer::get())) {
            return;
        }

        $token = Config::get('logger.token');

        if (! $token) {
            return;
        }

        $url = env('APEX_TOOLBOX_DEV_ENDPOINT')
            ? env('APEX_TOOLBOX_DEV_ENDPOINT')
            : 'https://apextoolbox.com/api/v1/logs';

        try {
            Http::withHeaders([
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json',
            ])
                ->timeout(2)
                ->post($url, [
                    'logs_trace_id' => Str::uuid7()->toString(),
                    'logs' => LogBuffer::flush()
                ]);
        } catch (Throwable $e) {
            // Silently fail...
        }
    }
}