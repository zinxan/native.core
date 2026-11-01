<?php

declare(strict_types=1);

use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

/**
 * Fire the events that "php artisan" fires. Artisan::call() in tests does not fire them.
 */
function runCommand(string $command, int $exitCode): void
{
    event(new CommandStarting($command, new ArrayInput([]), new NullOutput));
    event(new CommandFinished($command, new ArrayInput([]), new NullOutput, $exitCode));
}

it('records commands with their exit code', function (): void {
    fakeNativeWatch();

    runCommand('notes:import', 0);
    runCommand('notes:broken', 3);

    $entries = sentActivities('commands');

    expect(array_column($entries, 'name'))->toBe(['notes:import', 'notes:broken'])
        ->and($entries[0]['status'])->toBe('success')
        ->and($entries[0]['duration_ms'])->toBeInt()
        ->and($entries[1])->status->toBe('failed')
        ->and($entries[1]['attributes']['exit'])->toBe(3);
});

it('skips long running and nativewatch commands', function (): void {
    fakeNativeWatch(['nativewatch.activity.commands.ignore' => ['notes:*']]);

    runCommand('queue:work', 0);
    runCommand('nativewatch:flush', 0);
    runCommand('notes:import', 0);

    expect(sentActivities('commands'))->toBe([]);
});
