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
        $app['config']->set('apextoolbox.enabled', true);
        $app['config']->set('apextoolbox.token', 'test-token');
        $app['config']->set('apextoolbox.path_filters.include', ['api/*']);
        $app['config']->set('apextoolbox.path_filters.exclude', ['api/health']);
        $app['config']->set('apextoolbox.headers.exclude', ['authorization']);
        $app['config']->set('apextoolbox.body.exclude', ['password']);
    }
}