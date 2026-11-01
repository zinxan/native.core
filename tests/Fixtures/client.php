<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use ZXN\NativeWatch\Core\Client;
use ZXN\NativeWatch\Core\Config;
use ZXN\NativeWatch\Core\Contracts\ContextProvider;
use ZXN\NativeWatch\Core\Contracts\ReportingConsent;
use ZXN\NativeWatch\Core\EventFactory;
use ZXN\NativeWatch\Core\Outbox;
use ZXN\NativeWatch\Core\Transport\HttpTransport;

/**
 * @param  array<string, mixed>  $config
 */
function client(array $config = [], bool $withOutbox = true, ?ReportingConsent $consent = null): Client
{
    $config = Config::fromArray([
        'key' => 'nw_test',
        'url' => 'https://zxguard.test',
        'environment' => 'testing',
        ...$config,
    ], basePath: '/app');

    $context = new class implements ContextProvider
    {
        public function platform(): string
        {
            return 'desktop';
        }

        public function release(): ?string
        {
            return '2.0.0';
        }

        public function context(): array
        {
            return ['os' => ['name' => 'macOS']];
        }
    };

    return new Client(
        $config,
        new EventFactory($config, $context, 'zinxan/native-test'),
        new HttpTransport(Http::getFacadeRoot(), $config),
        $withOutbox ? new Outbox(temporaryOutbox()) : null,
        consent: $consent,
    );
}
