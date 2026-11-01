<?php

declare(strict_types=1);

namespace ZXN\NativeWatch\Core\Laravel\Watchers;

use Illuminate\Contracts\Foundation\Application;
use ZXN\NativeWatch\Core\Activity\ActivityRecorder;

interface Watcher
{
    /**
     * Get the signal the watcher records, such as "jobs". Its options live in "nativewatch.activity.<signal>".
     */
    public function signal(): string;

    /**
     * Listen to the app and record activities.
     *
     * @param  array<string, mixed>  $options  The options of the signal, such as a threshold.
     */
    public function register(Application $app, ActivityRecorder $recorder, array $options): void;
}
