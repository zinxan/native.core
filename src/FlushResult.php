<?php

declare(strict_types=1);

namespace ZXN\NativeWatch\Core;

final readonly class FlushResult
{
    public function __construct(
        public int $accepted = 0,
        public int $rejected = 0,
        public int $unreadable = 0,
        public int $failed = 0,
        public int $remaining = 0,
        public int $removed = 0,
    ) {}

    /** @param array{accepted: int, rejected: int, unreadable: int, failed: int, remaining: int, removed: int} $data */
    public static function fromArray(array $data): self
    {
        return new self(...$data);
    }

    /** @return array{accepted: int, rejected: int, unreadable: int, failed: int, remaining: int, removed: int} */
    public function toArray(): array
    {
        return [
            'accepted' => $this->accepted,
            'rejected' => $this->rejected,
            'unreadable' => $this->unreadable,
            'failed' => $this->failed,
            'remaining' => $this->remaining,
            'removed' => $this->removed,
        ];
    }
}
