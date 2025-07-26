<?php

namespace ApexToolbox\Logger\Tests;

use Orchestra\Testbench\TestCase as BaseTestCase;
use ApexToolbox\Logger\LoggerServiceProvider;

abstract class TestCase extends BaseTestCase
{
    protected function getPackageProviders($app)
    {
        return [
            LoggerServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app)
    {
        $app['config']->set('logger.enabled', true);
        $app['config']->set('logger.token', 'test-token');
        $app['config']->set('logger.path_filters.include', ['api/*']);
        $app['config']->set('logger.path_filters.exclude', ['api/health']);
        $app['config']->set('logger.headers.exclude', ['authorization']);
        $app['config']->set('logger.body.exclude', ['password']);
        $app['config']->set('logger.body.max_size', 1024);
    }
}