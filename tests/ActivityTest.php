<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use ZXN\NativeWatch\Core\Activity\ActivityRecorder;
use ZXN\NativeWatch\Core\Client;
use ZXN\NativeWatch\Core\Scrubber;
use ZXN\NativeWatch\Core\Tests\Fixtures\FakeWatcher;
use ZXN\NativeWatch\Desktop\DesktopServiceProvider;

require_once __DIR__.'/Fixtures/FakeWatcher.php';

beforeEach(function (): void {
    Http::preventStrayRequests();
    Http::fake(['zxguard.test/*' => Http::response(status: 202)]);
    FakeWatcher::$recorder = null;

    config([
        'nativewatch.key' => 'nw_activity',
        'nativewatch.url' => 'https://zxguard.test',
        'nativewatch.outbox.path' => temporaryOutbox(),
    ]);
});

it('sends the activities of a request as one batch after the response', function (): void {
    config(['nativewatch.activity.watchers' => [FakeWatcher::class]]);
    $this->app->register(DesktopServiceProvider::class);

    FakeWatcher::$recorder?->record('jobs', 'App\\Jobs\\SyncNotes', 'processed', 84.4, ['queue' => 'default']);
    FakeWatcher::$recorder?->record('jobs', 'App\\Jobs\\Backup', 'failed', 12.0);

    Http::assertNothingSent();

    $this->app->terminate();

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://zxguard.test/api/v1/activities'
        && count($request['entries']) === 2
        && $request['entries'][0]['duration_ms'] === 84
        && $request['entries'][0]['attributes'] === ['queue' => 'default']
        && $request['entries'][1]['status'] === 'failed');
});

it('does not register disabled watchers', function (): void {
    config(['nativewatch.activity.watchers' => [FakeWatcher::class], 'nativewatch.activity.fake.enabled' => false]);
    $this->app->register(DesktopServiceProvider::class);

    expect(FakeWatcher::$recorder)->toBeNull();
});

it('stops recording at the limit', function (): void {
    $recorder = new ActivityRecorder(limit: 2);

    foreach (range(1, 5) as $number) {
        $recorder->record('queries', "select {$number}");
    }

    expect($recorder->pull())->toHaveCount(2)
        ->and($recorder->pull())->toBe([]);
});

it('scrubs activities after the app changes them and before they reach disk', function (): void {
    $this->app->register(DesktopServiceProvider::class);
    $client = resolve(Client::class);
    $client->beforeSend(fn (array $batch): array => [...$batch, 'token' => 'added-by-the-app']);
    $client->activities->record('jobs', 'SyncNotes', attributes: ['request' => ['password' => 'private-password']]);

    $client->storeActivities();

    $files = glob(config('nativewatch.outbox.path').'/*.json');
    expect($files)->toHaveCount(1)
        ->and(file_get_contents($files[0]))->not->toContain('private-password', 'added-by-the-app');
    Http::assertNothingSent();

    $client->flush();

    Http::assertSent(fn (Request $request): bool => $request['entries'][0]['attributes']['request']['password'] === Scrubber::FILTERED
        && $request['token'] === Scrubber::FILTERED);
});

it('lets the app drop activities before they are stored or sent', function (): void {
    $this->app->register(DesktopServiceProvider::class);
    $client = resolve(Client::class);
    $client->beforeSend(fn (array $batch): null => null);
    $client->activities->record('jobs', 'SyncNotes');

    $this->app->terminate();

    expect($client->pending())->toBe(0);
    Http::assertNothingSent();
});
