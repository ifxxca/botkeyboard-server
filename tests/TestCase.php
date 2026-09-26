<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (config('database.default') !== 'mysql' ||
            ! str_ends_with((string) config('database.connections.mysql.database'), '_test')) {
            throw new \RuntimeException('Tests require an isolated MySQL database ending in _test.');
        }
    }
}
