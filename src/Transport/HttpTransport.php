<?php

declare(strict_types=1);

namespace ZXN\NativeWatch\Core\Transport;

use Illuminate\Http\Client\Factory;
use Throwable;
use ZXN\NativeWatch\Core\Config;

final readonly class HttpTransport implements Transport
{
    private const string QUOTA_EXCEEDED = 'event_quota_exceeded';

    public function __construct(
        private Factory $http,
        private Config $config,
        private RetryAfter $retryAfter = new RetryAfter,
    ) {}

    /**
     * Send one event to the ingest API.
     *
     * @param  array<string, mixed>  $event
     */
    public function send(array $event): SendResult
    {
        try {
            if ($this->retryAfter->isActive()) {
                return SendResult::Failed;
            }

            $response = $this->http
                ->withHeaders(['X-NativeWatch-Key' => $this->config->key])
                ->acceptJson()
                ->timeout($this->config->timeout)
                ->connectTimeout($this->config->timeout)
                ->post($this->config->endpoint($event['type'] ?? 'occurrences'), $event);
        } catch (Throwable) {
            return SendResult::Failed;
        }

        if ($response->status() === 429 && $response->json('code') !== self::QUOTA_EXCEEDED) {
            if ($response->header('Retry-After') !== '') {
                $this->retryAfter->remember($response->header('Retry-After'));
            }

            return SendResult::Failed;
        }

        return match (true) {
            $response->successful() => SendResult::Sent,
            $response->clientError() => SendResult::Rejected,
            default => SendResult::Failed,
        };
    }
}
