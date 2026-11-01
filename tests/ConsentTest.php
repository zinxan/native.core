<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use ZXN\NativeWatch\Core\FileConsent;

require_once __DIR__.'/Fixtures/client.php';

beforeEach(function (): void {
    Http::preventStrayRequests();
    Http::fake(['zxguard.test/*' => Http::response(status: 202)]);
});

it('starts denied and persists explicit choices across instances', function (): void {
    $path = temporaryOutbox().'/consent.json';
    $consent = new FileConsent($path);

    expect($consent->generation())->toBeNull()
        ->and($consent->setGranted(true))->toBeTrue();
    $generation = $consent->generation();
    expect($generation)->toBeString()
        ->and(new FileConsent($path)->generation())->toBe($generation)
        ->and($consent->setGranted(false))->toBeTrue()
        ->and(new FileConsent($path)->generation())->toBeNull();
});

it('fails closed for malformed or unwritable consent storage', function (): void {
    $path = temporaryOutbox().'/consent.json';
    file_put_contents($path, '{broken');
    expect(new FileConsent($path)->generation())->toBeNull();
    mkdir($path.'.directory');
    expect(new FileConsent($path.'.directory')->setGranted(true))->toBeFalse();
});

it('does not retain events breadcrumbs or activities before opt in', function (): void {
    $consent = new FileConsent(temporaryOutbox().'/consent.json');
    $client = client(consent: $consent);
    $client->captureException(new RuntimeException('Must not be collected'));
    $client->addBreadcrumb('private', 'Must not be retained');
    $client->activities->record('requests', 'private path');

    expect($client->pending())->toBe(0)
        ->and($client->scope->breadcrumbs->all())->toBe([])
        ->and($client->activities->pull())->toBe([])
        ->and($client->flush())->toBe(0);
    Http::assertNothingSent();
});

it('grants live and removes queued reports and user context on withdrawal', function (): void {
    $consent = new FileConsent(temporaryOutbox().'/consent.json');
    $client = client(consent: $consent);
    expect($client->setConsent(true))->toBeTrue();
    $client->scope->setUser(['id' => 'test-user']);
    $client->captureException(new RuntimeException('Queued while offline'));
    $client->activities->record('requests', 'old request');
    expect($client->pending())->toBe(1)
        ->and($client->setConsent(false))->toBeTrue()
        ->and($client->pending())->toBe(0)
        ->and($client->scope->toContext())->toBe([])
        ->and($client->setConsent(true))->toBeTrue()
        ->and($client->flush())->toBe(0);
    Http::assertNothingSent();
    $client->captureException(new RuntimeException('New consent interval'));
    expect($client->flush())->toBe(1);
    Http::assertSentCount(1);
});

it('rejects an old consent generation even when another process missed cleanup', function (): void {
    $path = temporaryOutbox().'/consent.json';
    $consent = new FileConsent($path);
    $client = client(consent: $consent);
    $client->setConsent(true);
    $client->captureMessage('Old generation');
    $otherProcess = new FileConsent($path);
    $otherProcess->setGranted(false);
    $otherProcess->setGranted(true);
    $client->flush();
    expect($client->pending())->toBe(0);
    Http::assertNothingSent();
});

it('checks persisted consent before each queued send', function (): void {
    $consent = new FileConsent(temporaryOutbox().'/consent.json');
    $client = client(consent: $consent);
    $client->setConsent(true);
    $client->captureMessage('First');
    $client->captureMessage('Second');
    Http::fake(function () use ($consent): GuzzleHttp\Promise\PromiseInterface {
        $consent->setGranted(false);

        return Http::response(status: 202);
    });
    $client->flush();
    Http::assertSentCount(1);
});

it('does not recreate queued data after a callback withdraws consent', function (bool $activity): void {
    $client = client(consent: new FileConsent(temporaryOutbox().'/consent.json'));
    $client->setConsent(true);
    $client->beforeSend(function (array $event) use ($client): array {
        $client->setConsent(false);

        return $event;
    });
    if ($activity) {
        $client->activities->record('requests', 'Must be removed');
        $client->storeActivities();
    } else {
        $client->captureMessage('Must be removed');
    }
    expect($client->pending())->toBe(0);
    Http::assertNothingSent();
})->with([false, true]);

it('does not reauthorize a native report from an older consent interval', function (): void {
    $consent = new FileConsent(temporaryOutbox().'/consent.json');
    $client = client(consent: $consent);
    $client->setConsent(true);
    $generation = $consent->generation();
    $client->setConsent(false);
    $client->setConsent(true);
    $result = $client->captureEventResult(['message' => 'Old native report', '_nativewatch_consent_generation' => $generation]);
    expect($result->id)->toBeNull()->and($result->retryable)->toBeFalse()->and($client->pending())->toBe(0);
    Http::assertNothingSent();
});
