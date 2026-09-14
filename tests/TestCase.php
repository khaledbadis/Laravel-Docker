<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    public function createApplication()
    {
        $app = parent::createApplication();

        if (! $app->environment('testing') || config('database.default') !== 'pgsql'
            || config('database.connections.pgsql.host') !== 'db_test'
            || config('database.connections.pgsql.database') !== 'todo_test'
            || config('database.connections.pgsql.username') !== 'todo_test'
            || config('database.connections.pgsql.url')) {
            throw new \RuntimeException('Tests must use the isolated db_test service. Clear cached configuration.');
        }

        return $app;
    }
}
