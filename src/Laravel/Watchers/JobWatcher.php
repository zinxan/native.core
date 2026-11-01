<?php

declare(strict_types=1);

namespace ZXN\NativeWatch\Core\Laravel\Watchers;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use ZXN\NativeWatch\Core\Activity\ActivityRecorder;

final class JobWatcher implements Watcher
{
    /**
     * @var array<string, float>
     */
    private array $startedAt = [];

    public function signal(): string
    {
        return 'jobs';
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public function register(Application $app, ActivityRecorder $recorder, array $options): void
    {
        $events = $app->make(Dispatcher::class);

        $events->listen(JobProcessing::class, function (JobProcessing $event): void {
            $this->startedAt[$this->key($event->job)] = microtime(true);
        });

        $events->listen(JobProcessed::class, fn (JobProcessed $event) => $this->record($recorder, $event->job, 'processed'));
        $events->listen(JobFailed::class, fn (JobFailed $event) => $this->record($recorder, $event->job, 'failed'));
    }

    private function record(ActivityRecorder $recorder, Job $job, string $status): void
    {
        $key = $this->key($job);
        $startedAt = $this->startedAt[$key] ?? null;
        unset($this->startedAt[$key]);

        $recorder->record(
            $this->signal(),
            $job->resolveName(),
            $status,
            $startedAt === null ? null : (microtime(true) - $startedAt) * 1000,
            ['queue' => $job->getQueue(), 'attempt' => $job->attempts(), 'connection' => $job->getConnectionName()],
        );
    }

    private function key(Job $job): string
    {
        return (string) ($job->getJobId() ?: spl_object_id($job));
    }
}
