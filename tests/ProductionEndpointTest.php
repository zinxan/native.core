<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;

require_once __DIR__.'/Fixtures/client.php';

it('sends a new installation to the NativeWatch production endpoint by default', function (): void {
    Http::preventStrayRequests();
    Http::fake(['https://native.watch/api/v1/occurrences' => Http::response(['status' => 'accepted'], 202)]);
    $defaults = require __DIR__.'/../config/nativewatch.php';
    $client = client(['url' => $defaults['url']]);

    $client->captureException(new RuntimeException('Production endpoint verification'));
    $client->flush();

    Http::assertSent(fn (Illuminate\Http\Client\Request $request): bool => $request->url() === 'https://native.watch/api/v1/occurrences');
});
