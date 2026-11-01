<?php

declare(strict_types=1);

namespace ZXN\NativeWatch\Core;

final readonly class Scrubber
{
    public const string FILTERED = '[filtered]';

    /**
     * @param  list<string>  $keys  Words that mark a key as sensitive.
     */
    public function __construct(private array $keys) {}

    /**
     * Replace the values of sensitive keys, at any depth.
     *
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    public function scrub(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_string($key) && $this->isSensitive($key)) {
                $data[$key] = self::FILTERED;
            } elseif (is_array($value)) {
                $data[$key] = $this->scrub($value);
            }
        }

        return $data;
    }

    private function isSensitive(string $key): bool
    {
        $key = mb_strtolower($key);

        foreach ($this->keys as $word) {
            if (str_contains($key, mb_strtolower($word))) {
                return true;
            }
        }

        return false;
    }
}
