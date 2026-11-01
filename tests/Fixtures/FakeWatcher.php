<?php

declare(strict_types=1);

namespace ZXN\NativeWatch\Core\Tests\Fixtures;

use Illuminate\Contracts\Foundation\Application;
use ZXN\NativeWatch\Core\Activity\ActivityRecorder;
use ZXN\NativeWatch\Core\Laravel\Watchers\Watcher;

final class FakeWatcher implements Watcher
{
    public static ?ActivityRecorder $recorder = null;

    public function signal(): string
    {
        return 'fake';
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public function register(Application $app, ActivityRecorder $recorder, array $options): void
    {
        self::$recorder = $recorder;
    }
}
