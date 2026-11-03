<?php

declare(strict_types=1);

use ZXN\NativeWatch\Core\Tests\TestCase;

pest()->extend(TestCase::class)->in(__DIR__);

function temporaryOutbox(): string
{
    $path = sys_get_temp_dir().'/nativewatch-tests/'.bin2hex(random_bytes(6));
    mkdir($path, 0755, true);

    return $path;
}

/**
 * Get the activity entries that the SDK sent, after the app finished its work.
 *
 * @return list<array<string, mixed>>
 */
function sentActivities(?string $signal = null): array
{
    app()->terminate();

    $entries = [];

    foreach (Illuminate\Support\Facades\Http::recorded() as [$request]) {
        if (str_ends_with($request->url(), '/api/v1/activities')) {
            array_push($entries, ...$request['entries']);
        }
    }

    return array_values(array_filter($entries, fn (array $entry): bool => $signal === null || $entry['signal'] === $signal));
}

/**
 * Get the events that the SDK sent, after the app finished its work.
 *
 * @return list<array<string, mixed>>
 */
function sentOccurrences(): array
{
    app()->terminate();

    $events = [];

    foreach (Illuminate\Support\Facades\Http::recorded() as [$request]) {
        if (str_ends_with($request->url(), '/api/v1/occurrences')) {
            $events[] = $request->data();
        }
    }

    return $events;
}

/**
 * Register the desktop SDK with a fake NativeWatch server, for watcher tests.
 *
 * @param  array<string, mixed>  $config
 */
function fakeNativeWatch(array $config = []): void
{
    Illuminate\Support\Facades\Http::fake(['zxguard.test/*' => Illuminate\Support\Facades\Http::response(status: 202)]);

    config([
        'nativewatch.key' => 'nw_test',
        'nativewatch.url' => 'https://zxguard.test',
        'nativewatch.outbox.path' => temporaryOutbox(),
        'nativewatch.consent.path' => temporaryOutbox().'/consent.json',
        ...$config,
    ]);

    app()->register(ZXN\NativeWatch\Desktop\DesktopServiceProvider::class);
}
