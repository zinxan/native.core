<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use ZXN\NativeWatch\Core\FileConsent;
use ZXN\NativeWatch\Core\FlushResult;
use ZXN\NativeWatch\Core\Transport\SendResult;

require_once __DIR__.'/Fixtures/client.php';

beforeEach(function (): void {
    Http::preventStrayRequests();
});

it('tracks a particular event from a retryable failure to server acceptance', function (): void {
    Http::fake(['zxguard.test/*' => Http::sequence()->push(status: 503)->push(status: 202)]);
    $client = client();
    $id = $client->captureMessage('Retry this event');
    expect($id)->toBeString()->and($client->deliveryResult($id))->toBeNull();

    $failed = $client->flushReport();
    expect($failed->failed)->toBe(1)->and($failed->remaining)->toBe(1)->and($failed->accepted)->toBe(0)
        ->and($client->deliveryResult($id))->toBe(SendResult::Failed);

    $accepted = $client->flushReport();
    expect($accepted->accepted)->toBe(1)->and($accepted->removed)->toBe(1)->and($accepted->remaining)->toBe(0)
        ->and($client->deliveryResult($id))->toBe(SendResult::Sent)
        ->and(FlushResult::fromArray($accepted->toArray()))->toEqual($accepted);
});

it('bounds in-memory delivery receipts and clears them on consent changes', function (): void {
    Http::fake(['zxguard.test/*' => Http::response(status: 202)]);
    $client = client(withOutbox: false, consent: new FileConsent(temporaryOutbox().'/consent.json'));
    $client->setConsent(true);
    $first = $client->captureMessage('First receipt');
    for ($index = 0; $index < 64; $index++) {
        $last = $client->captureMessage('Later receipt '.$index);
    }

    expect($client->deliveryResult($first))->toBeNull()->and($client->deliveryResult($last))->toBe(SendResult::Sent);
    $client->setConsent(false);
    $client->setConsent(true);
    expect($client->deliveryResult($last))->toBeNull();
});

it('does not claim acceptance when a network connection fails', function (): void {
    Http::fake(['zxguard.test/*' => Http::failedConnection()]);
    $client = client();
    $id = $client->captureMessage('Offline');
    $result = $client->flushReport();

    expect($result->failed)->toBe(1)->and($result->accepted)->toBe(0)->and($result->remaining)->toBe(1)
        ->and($client->deliveryResult($id))->toBe(SendResult::Failed);
});
