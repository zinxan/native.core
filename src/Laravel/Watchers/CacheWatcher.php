<?php

declare(strict_types=1);

namespace ZXN\NativeWatch\Core\Laravel\Watchers;

use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Cache\Events\KeyForgotten;
use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use ZXN\NativeWatch\Core\Activity\ActivityRecorder;

final class CacheWatcher implements Watcher
{
    public function signal(): string
    {
        return 'cache';
    }

    /**
     * Cache calls are frequent, so only a share of them is recorded.
     *
     * @param  array<string, mixed>  $options  "sample_rate": the share of cache calls to record, from 0.0 to 1.0 (default 0.1).
     */
    public function register(Application $app, ActivityRecorder $recorder, array $options): void
    {
        $rate = is_numeric($options['sample_rate'] ?? null) ? (float) $options['sample_rate'] : 0.1;
        $events = $app->make(Dispatcher::class);

        $record = function (string $operation, string $result, string $key, ?string $store) use ($recorder, $rate): void {
            if ($rate < 1.0 && random_int(0, PHP_INT_MAX) / PHP_INT_MAX >= $rate) {
                return;
            }

            if (str_starts_with($key, 'illuminate:') || str_starts_with($key, 'laravel')) {
                return;
            }

            $recorder->record($this->signal(), $operation, $result, null, ['key' => mb_substr($key, 0, 255), 'store' => $store]);
        };

        $events->listen(CacheHit::class, fn (CacheHit $event) => $record('read', 'hit', $event->key, $event->storeName));
        $events->listen(CacheMissed::class, fn (CacheMissed $event) => $record('read', 'miss', $event->key, $event->storeName));
        $events->listen(KeyWritten::class, fn (KeyWritten $event) => $record('write', 'written', $event->key, $event->storeName));
        $events->listen(KeyForgotten::class, fn (KeyForgotten $event) => $record('forget', 'forgotten', $event->key, $event->storeName));
    }
}
