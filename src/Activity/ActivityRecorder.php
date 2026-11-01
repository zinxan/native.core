<?php

declare(strict_types=1);

namespace ZXN\NativeWatch\Core\Activity;

use Closure;
use DateTimeImmutable;
use DateTimeInterface;

final class ActivityRecorder
{
    /**
     * @var list<array{signal: string, name: string, status: string|null, duration_ms: int|null, attributes: array<string, mixed>, occurred_at: string}>
     */
    private array $entries = [];

    private ?Closure $canRecord = null;

    public function __construct(private readonly int $limit = 200) {}

    /** @param Closure(): bool $condition */
    public function onlyWhen(Closure $condition): void
    {
        $this->canRecord = $condition;
    }

    /**
     * Record one thing the app did, such as a job or a sent mail.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function record(string $signal, string $name, ?string $status = null, ?float $durationMs = null, array $attributes = []): void
    {
        if (($this->canRecord !== null && ! ($this->canRecord)()) || count($this->entries) >= $this->limit) {
            return;
        }

        $this->entries[] = [
            'signal' => $signal,
            'name' => mb_substr($name, 0, 255),
            'status' => $status,
            'duration_ms' => $durationMs === null ? null : (int) round($durationMs),
            'attributes' => $attributes,
            'occurred_at' => new DateTimeImmutable()->format(DateTimeInterface::ATOM),
        ];
    }

    /**
     * Take the recorded entries and start over.
     *
     * @return list<array{signal: string, name: string, status: string|null, duration_ms: int|null, attributes: array<string, mixed>, occurred_at: string}>
     */
    public function pull(): array
    {
        $entries = $this->entries;
        $this->entries = [];

        return $entries;
    }
}
