<?php

declare(strict_types=1);

namespace ZXN\NativeWatch\Core\Tests;

use Illuminate\Foundation\Application;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    /** @param Application $app */
    protected function defineEnvironment($app): void
    {
        $app['config']->set([
            'app.key' => 'base64:'.base64_encode(str_repeat('a', 32)),
            'cache.default' => 'array',
            'database.default' => 'testing',
            'database.connections.testing' => ['driver' => 'sqlite', 'database' => ':memory:'],
            'mail.default' => 'array',
            'queue.default' => 'sync',
            'session.driver' => 'array',
            'nativewatch.key' => null,
        ]);
    }
}
