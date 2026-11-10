<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    public function createApplication()
    {
        $app = parent::createApplication();
        $connection = $app['config']->get('database.default');
        $database = $app['config']->get('database.connections.'.$connection.'.database');
        if ($connection !== 'pgsql' || ! is_string($database) || ! str_ends_with($database, '_test')) {
            throw new \LogicException('Tests require an isolated PostgreSQL database ending in _test. Refusing database mutations.');
        }

        return $app;
    }
}
