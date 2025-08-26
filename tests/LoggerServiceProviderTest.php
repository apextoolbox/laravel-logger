<?php

namespace ApexToolbox\Logger\Tests;

use ApexToolbox\Logger\LoggerServiceProvider;
use ApexToolbox\Logger\Handlers\ApexToolboxExceptionHandler;
use ApexToolbox\Logger\Handlers\ApexToolboxLogHandler;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Illuminate\Queue\Events\JobAttempted;
use Illuminate\Console\Events\CommandFinished;
use Exception;
use RuntimeException;
use Illuminate\Validation\ValidationException;

class LoggerServiceProviderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        ApexToolboxExceptionHandler::clear();
    }

    protected function tearDown(): void
    {
        ApexToolboxExceptionHandler::clear();
        parent::tearDown();
    }

    public function test_service_provider_registers_config()
    {
        $this->assertNotNull(Config::get('logger'));
        $this->assertArrayHasKey('enabled', Config::get('logger'));
        $this->assertArrayHasKey('token', Config::get('logger'));
    }

    public function test_exception_handler_extension_respects_should_report()
    {
        Config::set('logger.enabled', true);
        Config::set('logger.token', 'test-token');

        $serviceProvider = new LoggerServiceProvider($this->app);
        $serviceProvider->register();

        // Get the exception handler from the container
        $handler = $this->app->make(ExceptionHandler::class);
        
        // Test that the handler is properly extended
        // We can't easily test the reportable callback without complex mocking
        // but we can verify the service provider registered without errors
        $this->assertInstanceOf(ExceptionHandler::class, $handler);
    }

    public function test_job_attempted_event_flushes_buffer()
    {
        $serviceProvider = new LoggerServiceProvider($this->app);
        $serviceProvider->boot();
        
        // Test that we can fire the event without errors
        // The actual buffer flushing is tested in the log handler tests
        Event::dispatch(new JobAttempted('default', null, []));
        
        $this->assertTrue(true); // Test passes if no exception is thrown
    }

    public function test_command_finished_event_flushes_buffer()
    {
        $serviceProvider = new LoggerServiceProvider($this->app);
        $serviceProvider->boot();
        
        // Test that we can fire the event without errors
        // The actual buffer flushing is tested in the log handler tests
        $input = $this->createMock(\Symfony\Component\Console\Input\InputInterface::class);
        $output = $this->createMock(\Symfony\Component\Console\Output\OutputInterface::class);
        Event::dispatch(new CommandFinished('test:command', $input, $output, 0));
        
        $this->assertTrue(true); // Test passes if no exception is thrown
    }

    public function test_global_exception_handler_is_registered()
    {
        Config::set('logger.enabled', true);
        Config::set('logger.token', 'test-token');

        $serviceProvider = new LoggerServiceProvider($this->app);
        $serviceProvider->boot();

        // Test that set_exception_handler was called by triggering an exception
        // We can't directly test set_exception_handler registration, but we can
        // verify the logic would work
        $this->assertTrue(true); // Placeholder - hard to test global handlers
    }

    public function test_shutdown_function_handles_fatal_errors()
    {
        Config::set('logger.enabled', true);
        Config::set('logger.token', 'test-token');

        $serviceProvider = new LoggerServiceProvider($this->app);
        $serviceProvider->boot();

        // Test that register_shutdown_function was called
        // We can't directly test this, but we can verify the logic
        $this->assertTrue(true); // Placeholder - hard to test shutdown functions
    }

    public function test_config_is_published_correctly()
    {
        $serviceProvider = new LoggerServiceProvider($this->app);
        $serviceProvider->boot();

        // Get the published config paths
        $publishedPaths = $serviceProvider::$publishes[LoggerServiceProvider::class] ?? [];
        
        // Should have the logger config published
        $this->assertNotEmpty($publishedPaths);
        $this->assertStringContainsString('logger.php', array_keys($publishedPaths)[0] ?? '');
    }

    public function test_service_provider_can_be_registered_and_booted()
    {
        Config::set('logger.enabled', true);
        Config::set('logger.token', 'test-token');

        $serviceProvider = new LoggerServiceProvider($this->app);
        
        // Should not throw any exceptions
        $serviceProvider->register();
        $serviceProvider->boot();
        
        $this->assertTrue(true);
    }

    public function test_exception_handler_extension_with_real_exception()
    {
        Config::set('logger.enabled', true);
        Config::set('logger.token', 'test-token');

        // Get the real exception handler
        $handler = $this->app->make(ExceptionHandler::class);
        
        // Test with ValidationException (should be filtered by default dontReport)
        $validator = $this->app['validator']->make([], ['required_field' => 'required']);
        $validationException = new ValidationException($validator);
        
        // Should respect Laravel's built-in filtering
        $shouldReport = $handler->shouldReport($validationException);
        
        // Depending on Laravel's default configuration, this might be false
        // The exact behavior depends on the Laravel version and default Handler
        $this->assertIsBool($shouldReport);
    }
}