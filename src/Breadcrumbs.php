<?php

declare(strict_types=1);

namespace ZXN\NativeWatch\Core;

use Closure;
use DateTimeImmutable;
use DateTimeInterface;

final class Breadcrumbs
{
    /**
     * @var list<array{timestamp: string, category: string, level: string, message: string, data: array<string, mixed>}>
     */
    private array $items = [];

    private ?Closure $canRecord = null;

    public function __construct(private readonly int $limit = 30) {}

    /** @param Closure(): bool $condition */
    public function onlyWhen(Closure $condition): void
    {
        $this->canRecord = $condition;
    }

    /**
     * Record something that happened before an error, dropping the oldest entry when full.
     *
     * @param  array<string, mixed>  $data
     */
    public function add(string $category, string $message, Level $level = Level::Info, array $data = []): void
    {
        if (($this->canRecord !== null && ! ($this->canRecord)()) || $this->limit < 1) {
            return;
        }

        $this->items[] = [
            'timestamp' => new DateTimeImmutable()->format(DateTimeInterface::ATOM),
            'category' => $category,
            'level' => $level->value,
            'message' => mb_substr($message, 0, 1024),
            'data' => $data,
        ];

        if (count($this->items) > $this->limit) {
            $this->items = array_slice($this->items, -$this->limit);
        }
    }

    /**
     * @return list<array{timestamp: string, category: string, level: string, message: string, data: array<string, mixed>}>
     */
    public function all(): array
    {
        return $this->items;
    }

    /**
     * Forget every breadcrumb.
     */
    public function clear(): void
    {
        $this->items = [];
    }
}
