<?php

namespace ApexToolbox\Logger\Tests;

use ApexToolbox\Logger\Handlers\ExceptionHandler;
use ApexToolbox\Logger\PayloadCollector;
use Exception;
use RuntimeException;

class ExceptionHandlerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        PayloadCollector::clear();
    }

    public function test_capture_stores_exception_in_payload_collector(): void
    {
        $this->app['config']->set('apextoolbox.enabled', true);
        $this->app['config']->set('apextoolbox.token', 'test-token');

        $exception = new RuntimeException('Something went wrong', 500);
        ExceptionHandler::capture($exception);

        $payload = $this->getPayload();

        $this->assertArrayHasKey('exception', $payload);
        $this->assertEquals('RuntimeException', $payload['exception']['class']);
        $this->assertEquals('Something went wrong', $payload['exception']['message']);
        $this->assertEquals('500', $payload['exception']['code']);
    }

    public function test_exception_has_required_fields(): void
    {
        $this->app['config']->set('apextoolbox.enabled', true);
        $this->app['config']->set('apextoolbox.token', 'test-token');

        $exception = new Exception('Test error');
        ExceptionHandler::capture($exception);

        $payload = $this->getPayload();
        $exceptionData = $payload['exception'];

        $this->assertArrayHasKey('hash', $exceptionData);
        $this->assertArrayHasKey('class', $exceptionData);
        $this->assertArrayHasKey('message', $exceptionData);
        $this->assertArrayHasKey('code', $exceptionData);
        $this->assertArrayHasKey('file_path', $exceptionData);
        $this->assertArrayHasKey('line_number', $exceptionData);
        $this->assertArrayHasKey('source_context', $exceptionData);
        $this->assertArrayHasKey('stack_trace', $exceptionData);
        $this->assertArrayHasKey('context', $exceptionData);
    }

    public function test_hash_is_consistent_for_same_exception_location(): void
    {
        $this->app['config']->set('apextoolbox.enabled', true);
        $this->app['config']->set('apextoolbox.token', 'test-token');

        $exception1 = $this->createExceptionAtLine();

        PayloadCollector::clear();
        ExceptionHandler::capture($exception1);
        $hash1 = $this->getPayload()['exception']['hash'];

        PayloadCollector::clear();
        $exception2 = $this->createExceptionAtLine();
        ExceptionHandler::capture($exception2);
        $hash2 = $this->getPayload()['exception']['hash'];

        $this->assertEquals($hash1, $hash2);
    }

    public function test_hash_differs_for_different_exception_classes(): void
    {
        $this->app['config']->set('apextoolbox.enabled', true);
        $this->app['config']->set('apextoolbox.token', 'test-token');

        ExceptionHandler::capture(new RuntimeException('error'));
        $hash1 = $this->getPayload()['exception']['hash'];

        PayloadCollector::clear();
        ExceptionHandler::capture(new Exception('error'));
        $hash2 = $this->getPayload()['exception']['hash'];

        $this->assertNotEquals($hash1, $hash2);
    }

    public function test_file_paths_are_relative(): void
    {
        $this->app['config']->set('apextoolbox.enabled', true);
        $this->app['config']->set('apextoolbox.token', 'test-token');

        $exception = new Exception('Test');
        ExceptionHandler::capture($exception);

        $payload = $this->getPayload();

        $this->assertStringNotContainsString(base_path(), $payload['exception']['file_path']);
    }

    public function test_stack_trace_has_relative_paths(): void
    {
        $this->app['config']->set('apextoolbox.enabled', true);
        $this->app['config']->set('apextoolbox.token', 'test-token');

        $exception = new Exception('Test');
        ExceptionHandler::capture($exception);

        $payload = $this->getPayload();

        $this->assertIsString($payload['exception']['stack_trace']);
        $this->assertStringNotContainsString(base_path() . '/', $payload['exception']['stack_trace']);
    }

    public function test_source_context_contains_surrounding_lines(): void
    {
        $this->app['config']->set('apextoolbox.enabled', true);
        $this->app['config']->set('apextoolbox.token', 'test-token');

        $exception = new Exception('Test');
        ExceptionHandler::capture($exception);

        $payload = $this->getPayload();
        $context = $payload['exception']['source_context'];

        $this->assertIsArray($context);
        $this->assertArrayHasKey('code', $context);
        $this->assertArrayHasKey('error_line', $context);
        $this->assertArrayHasKey('start_line', $context);
        $this->assertEquals($exception->getLine(), $context['error_line']);
        $this->assertIsString($context['code']);
        $this->assertStringContainsString('new Exception', $context['code']);
    }

    public function test_exception_code_is_cast_to_string(): void
    {
        $this->app['config']->set('apextoolbox.enabled', true);
        $this->app['config']->set('apextoolbox.token', 'test-token');

        $exception = new Exception('Test', 42);
        ExceptionHandler::capture($exception);

        $payload = $this->getPayload();

        $this->assertIsString($payload['exception']['code']);
        $this->assertEquals('42', $payload['exception']['code']);
    }

    public function test_only_first_exception_is_captured(): void
    {
        $this->app['config']->set('apextoolbox.enabled', true);
        $this->app['config']->set('apextoolbox.token', 'test-token');

        ExceptionHandler::capture(new Exception('First'));
        ExceptionHandler::capture(new Exception('Second'));

        $payload = $this->getPayload();

        $this->assertEquals('First', $payload['exception']['message']);
    }

    public function test_capture_ignores_when_disabled(): void
    {
        $this->app['config']->set('apextoolbox.enabled', false);
        $this->app['config']->set('apextoolbox.token', 'test-token');

        ExceptionHandler::capture(new Exception('Test'));

        $payload = $this->getPayload();

        $this->assertArrayNotHasKey('exception', $payload);
    }

    public function test_clear_resets_exception(): void
    {
        $this->app['config']->set('apextoolbox.enabled', true);
        $this->app['config']->set('apextoolbox.token', 'test-token');

        ExceptionHandler::capture(new Exception('Test'));
        PayloadCollector::clear();

        $payload = $this->getPayload();

        $this->assertArrayNotHasKey('exception', $payload);
    }

    public function test_context_includes_environment(): void
    {
        $this->app['config']->set('apextoolbox.enabled', true);
        $this->app['config']->set('apextoolbox.token', 'test-token');
        $this->app['config']->set('app.env', 'production');

        ExceptionHandler::capture(new Exception('Test'));

        $payload = $this->getPayload();

        $this->assertEquals('production', $payload['exception']['context']['environment']);
    }

    private function getPayload(): array
    {
        $reflection = new \ReflectionClass(PayloadCollector::class);

        $payload = ['trace_id' => 'test'];

        $request = $reflection->getProperty('incomingRequest');
        if ($request->getValue()) {
            $payload['request'] = $request->getValue();
        }

        $logs = $reflection->getProperty('logs');
        if (!empty($logs->getValue())) {
            $payload['logs'] = $logs->getValue();
        }

        $outgoing = $reflection->getProperty('outgoingRequests');
        if (!empty($outgoing->getValue())) {
            $payload['outgoing_requests'] = $outgoing->getValue();
        }

        $exception = $reflection->getProperty('exception');
        if ($exception->getValue() !== null) {
            $payload['exception'] = $exception->getValue();
        }

        return $payload;
    }

    private function createExceptionAtLine(): Exception
    {
        return new Exception('Test error at specific line');
    }
}
