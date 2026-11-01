<?php

declare(strict_types=1);

namespace ZXN\NativeWatch\Core\Releases;

use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\PendingRequest;
use RuntimeException;

final readonly class CiConnection
{
    public function __construct(private Factory $http) {}

    public function request(): PendingRequest
    {
        $token = getenv('NATIVEWATCH_RELEASE_TOKEN');
        if (! is_string($token) || $token === '') {
            throw new RuntimeException('Set NATIVEWATCH_RELEASE_TOKEN in CI. Never bundle the CI token in your app.');
        }
        $url = config('nativewatch.url');
        if (! is_string($url) || filter_var($url, FILTER_VALIDATE_URL) === false || ! in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true)) {
            throw new RuntimeException('Set NATIVEWATCH_URL to your NativeWatch installation.');
        }

        return $this->http->baseUrl(mb_rtrim($url, '/'))->withToken($token)->acceptJson()->withoutRedirecting();
    }
}
