<?php

namespace ApexToolbox\Logger\Handlers;

use ApexToolbox\Logger\Services\ContextDetector;
use ApexToolbox\Logger\Services\SourceClassExtractor;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;

class ApexToolboxLogHandler extends AbstractProcessingHandler
{
    private static array $buffer = [];

    protected ContextDetector $contextDetector;
    protected SourceClassExtractor $sourceExtractor;

    public function __construct($level = Level::Debug, bool $bubble = true)
    {
        parent::__construct($level, $bubble);

        $this->contextDetector = new ContextDetector();
        $this->sourceExtractor = new SourceClassExtractor();
    }

    protected function write(LogRecord $record): void
    {
        if (! Config::get('logger.token')) { return; }

        self::$buffer[] = $this->prepareLogData($record);
    }

    protected function prepareLogData(LogRecord $record): array
    {
        $context = $this->contextDetector->detect();
        $sourceClass = $this->sourceExtractor->extract($record);

        return [
            'type' => $context,
            'level' => $record->level->getName(),
            'message' => $record->message,
            'context' => $record->context,
            'source_class' => $sourceClass,
            'timestamp' => $record->datetime->format('Y-m-d H:i:s'),
            'channel' => $record->channel,
        ];
    }

    public static function flushBuffer(): void
    {
        if (empty(self::$buffer)) { return; }

        try {
            self::send(self::$buffer);
        } catch (\Throwable $e) {
            // Silently fail...
        } finally {
            self::$buffer = [];
        }
    }

    public static function send(array $logs): void
    {
        try {
            $token = Config::get('logger.token');

            if (! $token) { return; }

            $url = env('APEX_TOOLBOX_DEV_ENDPOINT')
                ? env('APEX_TOOLBOX_DEV_ENDPOINT')
                : 'https://apextoolbox.com/api/v1/logs';

            Http::withHeaders([
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json',
            ])
                ->timeout(2)
                ->post($url, [ 'logs' => $logs ]);

        } catch (\Throwable $e) {
            // Silently fail - never break the application
        }
    }
}