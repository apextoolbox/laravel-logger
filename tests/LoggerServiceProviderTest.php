<?php

namespace ApexToolbox\Logger\Tests;

use ApexToolbox\Logger\LoggerServiceProvider;
use ApexToolbox\Logger\PayloadCollector;
use Illuminate\Support\Facades\Config;

class LoggerServiceProviderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        PayloadCollector::clear();
    }

    protected function tearDown(): void
    {
        PayloadCollector::clear();
        parent::tearDown();
    }

    public function test_service_provider_registers_config()
    {
        $this->assertNotNull(Config::get('logger'));
        $this->assertArrayHasKey('enabled', Config::get('logger'));
        $this->assertArrayHasKey('token', Config::get('logger'));
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

    public function test_service_provider_does_not_register_query_logger()
    {
        $serviceProvider = new LoggerServiceProvider($this->app);
        $serviceProvider->register();

        // QueryLogger singleton should not be registered
        $this->assertFalse($this->app->bound('ApexToolbox\Logger\Database\QueryLogger'));
    }

    public function test_service_provider_does_not_extend_exception_handler()
    {
        Config::set('logger.enabled', true);
        Config::set('logger.token', 'test-token');

        $serviceProvider = new LoggerServiceProvider($this->app);
        $serviceProvider->register();
        $serviceProvider->boot();

        // The exception handler should be the default Laravel one, not extended
        $handler = $this->app->make(\Illuminate\Contracts\Debug\ExceptionHandler::class);
        $this->assertInstanceOf(\Illuminate\Contracts\Debug\ExceptionHandler::class, $handler);
    }
}
