<?php

declare(strict_types=1);

namespace ZXN\NativeWatch\Core\Contracts;

interface ContextProvider
{
    /**
     * Get the platform that the app runs on: "desktop", "ios" or "android".
     */
    public function platform(): string;

    /**
     * Get the release of the app when the config does not set one.
     */
    public function release(): ?string;

    /**
     * Get the context that describes the device, the OS and the runtime.
     *
     * @return array<string, array<string, mixed>>
     */
    public function context(): array;
}
