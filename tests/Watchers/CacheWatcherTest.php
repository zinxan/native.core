<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;

it('records cache reads, writes and forgets', function (): void {
    fakeNativeWatch(['nativewatch.activity.cache.sample_rate' => 1.0, 'cache.default' => 'array']);

    Cache::get('settings.theme');
    Cache::put('settings.theme', 'dark');
    Cache::get('settings.theme');
    Cache::forget('settings.theme');

    $entries = sentActivities('cache');

    expect(array_map(fn (array $entry): string => $entry['name'].':'.$entry['status'], $entries))
        ->toBe(['read:miss', 'write:written', 'read:hit', 'forget:forgotten'])
        ->and($entries[0]['attributes']['key'])->toBe('settings.theme');
});

it('samples cache calls', function (): void {
    fakeNativeWatch(['nativewatch.activity.cache.sample_rate' => 0.0, 'cache.default' => 'array']);

    Cache::get('settings.theme');

    expect(sentActivities('cache'))->toBe([]);
});
