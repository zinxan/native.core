<?php

declare(strict_types=1);

use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Scheduling\Schedule;

it('records finished and failed scheduled tasks', function (): void {
    fakeNativeWatch();
    $schedule = resolve(Schedule::class);
    $prune = $schedule->command('notes:prune')->daily();
    $backup = $schedule->call(fn () => null)->hourly()->description('Back up notes');

    event(new ScheduledTaskFinished($prune, 1.25));
    event(new ScheduledTaskFailed($backup, new RuntimeException('Disk full')));

    $entries = sentActivities('scheduled-tasks');

    expect($entries)->toHaveCount(2)
        ->and($entries[0])->name->toBe('notes:prune')->status->toBe('success')->duration_ms->toBe(1250)
        ->and($entries[0]['attributes']['expression'])->toBe('0 0 * * *')
        ->and($entries[1])->name->toBe('Back up notes')->status->toBe('failed');
});
