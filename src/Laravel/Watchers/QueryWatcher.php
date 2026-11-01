<?php

declare(strict_types=1);

namespace ZXN\NativeWatch\Core\Laravel\Watchers;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Events\QueryExecuted;
use ZXN\NativeWatch\Core\Activity\ActivityRecorder;

final class QueryWatcher implements Watcher
{
    public function signal(): string
    {
        return 'queries';
    }

    /**
     * @param  array<string, mixed>  $options  "threshold_ms": only queries slower than this are recorded (default 100).
     */
    public function register(Application $app, ActivityRecorder $recorder, array $options): void
    {
        $threshold = is_numeric($options['threshold_ms'] ?? null) ? (float) $options['threshold_ms'] : 100.0;

        $app->make(Dispatcher::class)->listen(QueryExecuted::class, function (QueryExecuted $event) use ($recorder, $threshold): void {
            if ($event->time < $threshold) {
                return;
            }

            $recorder->record(
                $this->signal(),
                mb_substr($event->sql, 0, 255),
                'slow',
                $event->time,
                ['connection' => $event->connectionName],
            );
        });
    }
}
