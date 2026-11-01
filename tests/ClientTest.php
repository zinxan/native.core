<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use ZXN\NativeWatch\Core\Level;
use ZXN\NativeWatch\Core\Scrubber;

require_once __DIR__.'/Fixtures/client.php';

beforeEach(function (): void {
    Http::preventStrayRequests();
});

it('stores an exception in the outbox and sends it on flush', function (): void {
    Http::fake(['zxguard.test/*' => Http::response(['id' => 'x'], 202)]);
    $client = client();

    $id = $client->captureException(new RuntimeException('The sync server is unreachable.'));

    Http::assertNothingSent();
    expect($id)->toBeString()->and($client->pending())->toBe(1);

    expect($client->flush())->toBe(1)
        ->and($client->pending())->toBe(0);

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://zxguard.test/api/v1/occurrences'
        && $request->header('X-NativeWatch-Key') === ['nw_test']
        && $request['event_id'] === $id
        && $request['level'] === 'error'
        && $request['platform'] === 'desktop'
        && $request['release'] === '2.0.0'
        && $request['environment'] === 'testing'
        && $request['exception']['type'] === RuntimeException::class
        && $request['exception']['value'] === 'The sync server is unreachable.'
        && $request['context']['os']['name'] === 'macOS'
        && $request['sdk']['name'] === 'zinxan/native-test');
});

it('sends a message with breadcrumbs, user and tags', function (): void {
    Http::fake(['zxguard.test/*' => Http::response(status: 202)]);
    $client = client();

    $client->addBreadcrumb('navigation', 'Opened the settings window');
    $client->scope->setUser(['id' => 7]);
    $client->scope->setTag('window', 'settings');
    $client->captureMessage('Disk space is low', Level::Warning);
    $client->flush();

    Http::assertSent(fn (Request $request): bool => $request['message'] === 'Disk space is low'
        && $request['level'] === 'warning'
        && $request['breadcrumbs'][0]['message'] === 'Opened the settings window'
        && $request['context']['user'] === ['id' => 7]
        && $request['context']['tags'] === ['window' => 'settings']);
});

it('reports the same exception once per minute', function (): void {
    $client = client();
    $exception = new RuntimeException('Boom');

    expect($client->captureException($exception))->toBeString()
        ->and($client->captureException($exception))->toBeNull()
        ->and($client->pending())->toBe(1);
});

it('reports nothing without a key', function (): void {
    $client = client(['key' => '']);

    expect($client->captureException(new RuntimeException('Boom')))->toBeNull()
        ->and($client->pending())->toBe(0)
        ->and($client->flush())->toBe(0);
});

it('drops events that are not sampled', function (): void {
    expect(client(['sample_rate' => 0.0])->captureMessage('Hello'))->toBeNull();
});

it('lets the app change or drop events before they are sent', function (): void {
    Http::fake(['zxguard.test/*' => Http::response(status: 202)]);
    $client = client();

    $client->beforeSend(fn (array $event): ?array => $event['message'] === 'drop me' ? null : [...$event, 'release' => '9.9.9']);

    expect($client->captureMessage('drop me'))->toBeNull();

    $client->captureMessage('keep me');
    $client->flush();

    Http::assertSent(fn (Request $request): bool => $request['release'] === '9.9.9');
});

it('scrubs sensitive values before they leave the device', function (): void {
    Http::fake(['zxguard.test/*' => Http::response(status: 202)]);
    $client = client();

    $client->scope->setContext('request', ['email' => 'a@b.c', 'password' => 'hunter2', 'headers' => ['Authorization' => 'Bearer x']]);
    $client->captureMessage('Login failed');
    $client->flush();

    Http::assertSent(fn (Request $request): bool => $request['context']['request']['email'] === 'a@b.c'
        && $request['context']['request']['password'] === Scrubber::FILTERED
        && $request['context']['request']['headers']['Authorization'] === Scrubber::FILTERED);
});

it('keeps events in the outbox while the server cannot be reached', function (): void {
    Http::fake(['zxguard.test/*' => Http::response(status: 503)]);
    $client = client();

    $client->captureMessage('First');
    $client->captureMessage('Second');

    expect($client->flush())->toBe(0)
        ->and($client->pending())->toBe(2);

    Http::assertSentCount(1);
});

it('removes events that the server rejects', function (): void {
    Http::fake(['zxguard.test/*' => Http::response(['code' => 'event_quota_exceeded'], 429, ['Retry-After' => '86400'])]);
    $client = client();

    $client->captureMessage('Over quota');

    expect($client->flush())->toBe(1)
        ->and($client->pending())->toBe(0);
});

it('keeps an unclassified rate limit retryable even without a retry header', function (): void {
    Http::fake(['zxguard.test/*' => Http::response(status: 429)]);
    $client = client();
    $client->captureMessage('Temporarily throttled');

    expect($client->flush())->toBe(0)->and($client->pending())->toBe(1);
});

it('sends at once when there is no outbox', function (): void {
    Http::fake(['zxguard.test/*' => Http::response(status: 202)]);

    expect(client(withOutbox: false)->captureMessage('Direct'))->toBeString();

    Http::assertSentCount(1);
});

it('never lets an error of the sdk break the app', function (): void {
    $client = client();
    $client->beforeSend(fn (array $event): never => throw new LogicException('Bug in the callback'));

    expect($client->captureMessage('Hello'))->toBeNull();
});

it('completes an event that was built elsewhere', function (): void {
    Http::fake(['zxguard.test/*' => Http::response(status: 202)]);
    $client = client();
    $client->addBreadcrumb('navigation', 'This belongs to the current launch');

    $id = $client->captureEvent([
        'event_id' => '0b6f4f1e-8d37-4c43-a6a5-2f9c5b8d1a00',
        'timestamp' => '2026-10-24T21:13:08Z',
        'level' => 'fatal',
        'exception' => ['type' => 'NativeCrash', 'value' => 'SIGSEGV', 'frames' => []],
        'context' => ['crash' => ['mechanism' => 'android.native']],
        'platform' => 'ignored',
    ]);
    $client->flush();

    expect($id)->toBe('0b6f4f1e-8d37-4c43-a6a5-2f9c5b8d1a00');

    Http::assertSent(fn (Request $request): bool => $request['timestamp'] === '2026-10-24T21:13:08Z'
        && $request['level'] === 'fatal'
        && $request['platform'] === 'desktop'
        && $request['release'] === '2.0.0'
        && $request['context']['os']['name'] === 'macOS'
        && $request['context']['crash']['mechanism'] === 'android.native'
        && $request['breadcrumbs'] === []);
});

it('keeps long exception messages within the ingest contract', function (bool $native): void {
    Http::fake(['zxguard.test/*' => Http::response(status: 202)]);
    $client = client();
    $message = str_repeat('é', 8_193);

    if ($native) {
        $client->captureEvent(['exception' => ['type' => 'NativeCrash', 'value' => $message, 'frames' => []]]);
    } else {
        $client->captureException(new RuntimeException($message));
    }

    $client->flush();

    Http::assertSent(fn (Request $request): bool => mb_strlen($request['exception']['value']) === 8_192
        && validator($request->data(), (new App\Http\Requests\Api\V1\StoreOccurrenceRequest)->rules())->passes());
})->with([false, true]);
