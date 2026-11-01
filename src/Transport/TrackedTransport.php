<?php

declare(strict_types=1);

namespace ZXN\NativeWatch\Core\Transport;

final class TrackedTransport implements Transport
{
    /** @var array<string, SendResult> */
    private array $results = [];

    public function __construct(private readonly Transport $transport) {}

    /** @param array<string, mixed> $event */
    public function send(array $event): SendResult
    {
        $result = $this->transport->send($event);
        $id = $event['event_id'] ?? null;

        if (is_string($id)) {
            unset($this->results[$id]);
            $this->results[$id] = $result;
            $this->results = array_slice($this->results, -64, preserve_keys: true);
        }

        return $result;
    }

    public function resultFor(string $id): ?SendResult
    {
        return $this->results[$id] ?? null;
    }

    public function clear(): void
    {
        $this->results = [];
    }
}
