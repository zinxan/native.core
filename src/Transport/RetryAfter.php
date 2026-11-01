<?php

declare(strict_types=1);

namespace ZXN\NativeWatch\Core\Transport;

final class RetryAfter
{
    private int $until = 0;

    public function __construct(private readonly ?string $path = null) {}

    public function isActive(): bool
    {
        $stored = $this->path === null ? false : @file_get_contents($this->path);

        return max($this->until, (int) $stored) > time();
    }

    public function remember(string $header): void
    {
        $header = mb_trim($header);
        $until = ctype_digit($header) ? time() + (int) $header : strtotime($header);

        if ($until === false) {
            return;
        }

        $this->until = $until;

        if ($this->path !== null) {
            @file_put_contents($this->path, (string) $until, LOCK_EX);
        }
    }
}
