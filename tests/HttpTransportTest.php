<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use ZXN\NativeWatch\Core\Config;
use ZXN\NativeWatch\Core\Outbox;
use ZXN\NativeWatch\Core\Transport\HttpTransport;
use ZXN\NativeWatch\Core\Transport\RetryAfter;
use ZXN\NativeWatch\Core\Transport\SendResult;

beforeEach(function (): void {
    Http::preventStrayRequests();
});

it('retains throttled events and respects retry after across processes', function (string $header): void {
    Http::fake(['zxguard.test/*' => Http::sequence()->push(status: 429, headers: ['Retry-After' => $header])->push(status: 202)]);
    $path = temporaryOutbox();
    $outbox = new Outbox($path);
    $config = new Config('nw_test', 'https://zxguard.test');
    $makeTransport = fn (): HttpTransport => new HttpTransport(Http::getFacadeRoot(), $config, new RetryAfter($path.'/.retry-after'));
    $outbox->push(['message' => 'Retry me']);

    expect($outbox->flush(fn (array $event): SendResult => $makeTransport()->send($event)))->toBe(0)
        ->and($outbox->count())->toBe(1)
        ->and($outbox->flush(fn (array $event): SendResult => $makeTransport()->send($event)))->toBe(0);
    Http::assertSentCount(1);

    file_put_contents($path.'/.retry-after', (string) (time() - 1));

    expect($outbox->flush(fn (array $event): SendResult => $makeTransport()->send($event)))->toBe(1)
        ->and($outbox->count())->toBe(0);
    Http::assertSentCount(2);
})->with(['seconds' => '60', 'http date' => fn (): string => gmdate(DATE_RFC7231, time() + 60)]);
