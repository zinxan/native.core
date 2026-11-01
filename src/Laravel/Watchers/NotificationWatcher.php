<?php

declare(strict_types=1);

namespace ZXN\NativeWatch\Core\Laravel\Watchers;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Notifications\Events\NotificationSent;
use ZXN\NativeWatch\Core\Activity\ActivityRecorder;

final class NotificationWatcher implements Watcher
{
    public function signal(): string
    {
        return 'notifications';
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public function register(Application $app, ActivityRecorder $recorder, array $options): void
    {
        $events = $app->make(Dispatcher::class);

        $events->listen(NotificationSent::class, function (NotificationSent $event) use ($recorder): void {
            $recorder->record($this->signal(), $event->notification::class, 'sent', null, ['channel' => $event->channel]);
        });

        $events->listen(NotificationFailed::class, function (NotificationFailed $event) use ($recorder): void {
            $recorder->record($this->signal(), $event->notification::class, 'failed', null, ['channel' => $event->channel]);
        });
    }
}
