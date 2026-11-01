<?php

declare(strict_types=1);

namespace ZXN\NativeWatch\Core;

use Closure;

final class Scope
{
    public readonly Breadcrumbs $breadcrumbs;

    private ?Closure $canRecord = null;

    /**
     * @var array<string, mixed>|null
     */
    private ?array $user = null;

    /**
     * @var array<string, string>
     */
    private array $tags = [];

    /**
     * @var array<string, array<string, mixed>>
     */
    private array $contexts = [];

    public function __construct(int $maxBreadcrumbs = 30)
    {
        $this->breadcrumbs = new Breadcrumbs($maxBreadcrumbs);
    }

    /** @param Closure(): bool $condition */
    public function onlyWhen(Closure $condition): void
    {
        $this->canRecord = $condition;
        $this->breadcrumbs->onlyWhen($condition);
    }

    /**
     * Identify the user of the app.
     *
     * @param  array<string, mixed>|null  $user
     */
    public function setUser(?array $user): void
    {
        if ($this->canRecord === null || ($this->canRecord)()) {
            $this->user = $user;
        }
    }

    /**
     * Add a tag that is searchable on every following event.
     */
    public function setTag(string $key, string $value): void
    {
        if ($this->canRecord === null || ($this->canRecord)()) {
            $this->tags[$key] = $value;
        }
    }

    /**
     * Attach a named group of data to every following event.
     *
     * @param  array<string, mixed>  $context
     */
    public function setContext(string $name, array $context): void
    {
        if ($this->canRecord === null || ($this->canRecord)()) {
            $this->contexts[$name] = $context;
        }
    }

    /**
     * Get the context that the scope adds to an event.
     *
     * @return array<string, mixed>
     */
    public function toContext(): array
    {
        return array_filter([
            ...$this->contexts,
            'user' => $this->user,
            'tags' => $this->tags,
        ], fn (mixed $value): bool => $value !== null && $value !== []);
    }

    /**
     * Forget everything that belongs to one request.
     */
    public function clear(): void
    {
        $this->breadcrumbs->clear();
        $this->user = null;
        $this->tags = [];
        $this->contexts = [];
    }
}
