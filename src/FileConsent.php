<?php

declare(strict_types=1);

namespace ZXN\NativeWatch\Core;

use JsonException;
use ZXN\NativeWatch\Core\Contracts\ReportingConsent;

final class FileConsent implements ReportingConsent
{
    private bool $blocked = false;

    public function __construct(private readonly string $path) {}

    public function generation(): ?string
    {
        $json = $this->blocked ? false : @file_get_contents($this->path, length: 512);

        try {
            $state = is_string($json) ? json_decode($json, true, 4, JSON_THROW_ON_ERROR) : null;
        } catch (JsonException) {
            return null;
        }

        $generation = is_array($state) ? ($state['generation'] ?? null) : null;

        return is_string($generation) && preg_match('/^[a-f0-9]{32}$/D', $generation) === 1 ? $generation : null;
    }

    public function setGranted(bool $granted): bool
    {
        $this->blocked = true;
        $directory = dirname($this->path);

        if (! is_dir($directory) && ! @mkdir($directory, 0700, true) && ! is_dir($directory)) {
            return false;
        }

        $temporary = $this->path.'.'.bin2hex(random_bytes(6)).'.tmp';
        $json = json_encode(['generation' => $granted ? bin2hex(random_bytes(16)) : null], JSON_THROW_ON_ERROR);
        $written = @file_put_contents($temporary, $json, LOCK_EX) !== false && @rename($temporary, $this->path);
        @unlink($temporary);
        $this->blocked = ! $written;

        return $written;
    }
}
