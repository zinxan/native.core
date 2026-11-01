<?php

declare(strict_types=1);

namespace ZXN\NativeWatch\Core\Laravel\Watchers;

use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use ZXN\NativeWatch\Core\Activity\ActivityRecorder;

final class ScheduledTaskWatcher implements Watcher
{
    public function signal(): string
    {
        return 'scheduled-tasks';
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public function register(Application $app, ActivityRecorder $recorder, array $options): void
    {
        $events = $app->make(Dispatcher::class);

        $events->listen(ScheduledTaskFinished::class, fn (ScheduledTaskFinished $event) => $this->record($recorder, $event->task, 'success', $event->runtime * 1000));
        $events->listen(ScheduledTaskFailed::class, fn (ScheduledTaskFailed $event) => $this->record($recorder, $event->task, 'failed'));
        $events->listen(ScheduledTaskSkipped::class, fn (ScheduledTaskSkipped $event) => $this->record($recorder, $event->task, 'skipped'));
    }

    private function record(ActivityRecorder $recorder, Event $task, string $status, ?float $durationMs = null): void
    {
        $recorder->record($this->signal(), $this->name($task), $status, $durationMs, ['expression' => $task->expression]);
    }

    private function name(Event $task): string
    {
        if (is_string($task->description) && $task->description !== '') {
            return $task->description;
        }

        $command = (string) $task->command;

        return (string) preg_replace("/^.*?'artisan'\\s+/", '', $command) ?: 'Closure';
    }
}
