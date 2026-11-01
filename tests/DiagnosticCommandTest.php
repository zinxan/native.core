<?php

declare(strict_types=1);

use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use ZXN\NativeWatch\Core\Client;
use ZXN\NativeWatch\Core\Config;

beforeEach(function (): void {
    fakeNativeWatch();
    Http::swap(new Factory);
    Http::preventStrayRequests();
    app()->forgetInstance(Client::class);
});

it('fails when the server permanently rejects its test event', function (int $status): void {
    Http::fake(['zxguard.test/*' => Http::response(status: $status)]);

    $this->artisan('nativewatch:test')->expectsOutputToContain('rejected the test event')->assertFailed();

    expect(app(Client::class)->pending())->toBe(0);
    Http::assertSentCount(1);
})->with([401, 422]);

it('does not mistake older accepted events for delivery of its own event', function (): void {
    Http::fake(['zxguard.test/*' => Http::response(status: 202)]);
    $client = app(Client::class);
    for ($index = 0; $index < 10; $index++) {
        $client->captureMessage('Older event '.$index);
    }

    $this->artisan('nativewatch:test')->expectsOutputToContain('Delivery of the test event is not confirmed')->assertFailed();

    expect($client->pending())->toBe(1);
    Http::assertSentCount(10);
});

it('does not mistake an unreadable outbox file for a delivered test event', function (): void {
    file_put_contents(config('nativewatch.outbox.path').'/000-corrupt.json', '{broken');
    Http::fake(['zxguard.test/*' => Http::response(status: 503)]);

    $this->artisan('nativewatch:test')->expectsOutputToContain('Delivery of the test event is not confirmed')->assertFailed();

    expect(app(Client::class)->pending())->toBe(1);
});

it('recognizes immediate acceptance when the outbox cannot be written', function (): void {
    Http::fake(['zxguard.test/*' => Http::response(status: 202)]);
    $path = temporaryOutbox().'/blocked';
    file_put_contents($path, 'This is a file, not a directory.');
    config(['nativewatch.outbox.path' => $path]);
    app()->forgetInstance(Client::class);

    $this->artisan('nativewatch:test')->expectsOutputToContain('server accepted the test event')->assertSuccessful();

    Http::assertSentCount(1);
});

it('does not promise a queued event when capture is deliberately discarded', function (string $discard): void {
    if ($discard === 'sampling') {
        config(['nativewatch.sample_rate' => 0]);
        app()->forgetInstance(Config::class);
        app()->forgetInstance(Client::class);
    } else {
        app(Client::class)->beforeSend(fn (array $event): ?array => null);
    }

    $this->artisan('nativewatch:test')->expectsOutputToContain('test event was not captured for reporting')->assertFailed();

    expect(app(Client::class)->pending())->toBe(0);
    Http::assertNothingSent();
})->with(['sampling', 'beforeSend']);

it('explains denied consent without advising the developer to bypass it', function (): void {
    config(['nativewatch.require_consent' => true]);
    app()->forgetInstance(Client::class);

    $this->artisan('nativewatch:test')->expectsOutputToContain('Diagnostics consent has not been granted')->assertFailed();

    Http::assertNothingSent();
});

it('reports acceptance rather than claiming the dashboard has processed the event', function (): void {
    Http::fake(['zxguard.test/*' => Http::response(status: 202)]);
    $this->artisan('nativewatch:test')->expectsOutputToContain('server accepted the test event')->assertSuccessful();
});

it('fails rather than claiming success when reporting is disabled', function (): void {
    config(['nativewatch.enabled' => false]);
    app()->forgetInstance(Config::class);

    $this->artisan('nativewatch:test')->expectsOutputToContain('Check NATIVEWATCH_ENABLED')->assertFailed();
    $this->artisan('nativewatch:flush')->expectsOutputToContain('reporting is disabled')->assertFailed();
    Http::assertNothingSent();
});

it('keeps retryable test events queued and reports unconfirmed delivery', function (int $status): void {
    Http::fake(['zxguard.test/*' => Http::response(status: $status, headers: ['Retry-After' => '60'])]);

    $this->artisan('nativewatch:test')->expectsOutputToContain('Delivery of the test event is not confirmed')->assertFailed();

    expect(app(Client::class)->pending())->toBe(1);
})->with([429, 503]);

it('counts rejected and unreadable files separately from server acceptance', function (): void {
    Http::fake(['zxguard.test/*' => Http::sequence()->push(status: 202)->push(status: 422)]);
    $client = app(Client::class);
    $client->captureMessage('Accepted');
    $client->captureMessage('Rejected');
    file_put_contents(config('nativewatch.outbox.path').'/000-corrupt.json', '{broken');

    $this->artisan('nativewatch:flush')
        ->expectsOutputToContain('1 accepted, 1 rejected, 1 unreadable, 0 still waiting')->assertFailed();

    expect($client->pending())->toBe(0);
});
