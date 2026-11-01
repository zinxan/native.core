<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;

it('records outgoing http calls without their query string', function (): void {
    fakeNativeWatch();
    Http::fake(['api.sync.test/*' => Http::response(['ok' => true]), 'zxguard.test/*' => Http::response(status: 202)]);

    Http::get('https://api.sync.test/notes?token=secret');
    Http::post('https://api.sync.test/notes', ['title' => 'Hi']);

    $entries = sentActivities('outgoing-requests');

    expect($entries)->toHaveCount(2)
        ->and($entries[0])->name->toBe('GET')->status->toBe('200')
        ->and($entries[0]['attributes']['url'])->toBe('https://api.sync.test/notes')
        ->and($entries[1]['name'])->toBe('POST');
});

it('does not record the calls of the sdk to nativewatch', function (): void {
    fakeNativeWatch();

    sentActivities();

    expect(sentActivities('outgoing-requests'))->toBe([]);
});
