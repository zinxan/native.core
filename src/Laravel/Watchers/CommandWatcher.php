<?php

declare(strict_types=1);

namespace ZXN\NativeWatch\Core\Laravel\Watchers;

use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Str;
use ZXN\NativeWatch\Core\Activity\ActivityRecorder;

final class CommandWatcher implements Watcher
{
    /**
     * Commands that run for a long time or belong to NativeWatch itself.
     */
    private const array IGNORE = ['queue:work', 'queue:listen', 'schedule:work', 'schedule:run', 'schedule:finish', 'horizon*', 'native:*', 'nativewatch:*'];

    /**
     * @var array<string, float>
     */
    private array $startedAt = [];

    public function signal(): string
    {
        return 'commands';
    }

    /**
     * @param  array<string, mixed>  $options  "ignore": extra command patterns to skip.
     */
    public function register(Application $app, ActivityRecorder $recorder, array $options): void
    {
        $ignore = [...self::IGNORE, ...(is_array($options['ignore'] ?? null) ? $options['ignore'] : [])];
        $events = $app->make(Dispatcher::class);

        $events->listen(CommandStarting::class, function (CommandStarting $event): void {
            $this->startedAt[(string) $event->command] = microtime(true);
        });

        $events->listen(CommandFinished::class, function (CommandFinished $event) use ($recorder, $ignore): void {
            $command = (string) $event->command;
            $startedAt = $this->startedAt[$command] ?? null;
            unset($this->startedAt[$command]);

            if ($command === '' || Str::is($ignore, $command)) {
                return;
            }

            $recorder->record(
                $this->signal(),
                $command,
                $event->exitCode === 0 ? 'success' : 'failed',
                $startedAt === null ? null : (microtime(true) - $startedAt) * 1000,
                ['exit' => $event->exitCode],
            );
        });
    }
}
