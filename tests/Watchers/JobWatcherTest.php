<?php

declare(strict_types=1);

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Bus;

final class NativeWatchTestJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public bool $fail = false) {}

    public function handle(): void
    {
        if ($this->fail) {
            throw new RuntimeException('Backup failed.');
        }
    }
}

it('records processed and failed jobs', function (): void {
    fakeNativeWatch(['queue.default' => 'sync']);

    Bus::dispatch(new NativeWatchTestJob);

    try {
        Bus::dispatch(new NativeWatchTestJob(fail: true));
    } catch (RuntimeException) {
    }

    $entries = sentActivities('jobs');

    expect($entries)->toHaveCount(2)
        ->and($entries[0])->name->toBe(NativeWatchTestJob::class)->status->toBe('processed')
        ->and($entries[0]['attributes']['attempt'])->toBe(1)
        ->and($entries[0]['duration_ms'])->toBeInt()
        ->and($entries[1]['status'])->toBe('failed');
});

it('can be turned off', function (): void {
    fakeNativeWatch(['queue.default' => 'sync', 'nativewatch.activity.jobs.enabled' => false]);

    Bus::dispatch(new NativeWatchTestJob);

    expect(sentActivities('jobs'))->toBe([]);
});

it('records jobs whose queue gives them a number as their id', function (): void {
    fakeNativeWatch();

    $job = Mockery::mock(Illuminate\Contracts\Queue\Job::class);
    $job->allows([
        'getJobId' => 42,
        'resolveName' => NativeWatchTestJob::class,
        'getQueue' => 'default',
        'attempts' => 1,
        'getConnectionName' => 'database',
        'payload' => [],
    ]);

    event(new Illuminate\Queue\Events\JobProcessing('database', $job));
    event(new Illuminate\Queue\Events\JobProcessed('database', $job));

    expect(sentActivities('jobs'))->toHaveCount(1)
        ->and(sentActivities('jobs')[0]['duration_ms'])->toBeInt();
});
