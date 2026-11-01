<?php

declare(strict_types=1);

namespace ZXN\NativeWatch\Core;

final readonly class CaptureResult
{
    private function __construct(
        public ?string $id,
        public bool $retryable,
    ) {}

    public static function accepted(?string $id): self
    {
        return new self($id, false);
    }

    public static function discarded(): self
    {
        return new self(null, false);
    }

    public static function failed(): self
    {
        return new self(null, true);
    }
}
