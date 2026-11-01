<?php

declare(strict_types=1);

namespace ZXN\NativeWatch\Core;

use Closure;
use Illuminate\Support\Arr;
use Throwable;
use ZXN\NativeWatch\Core\Activity\ActivityRecorder;
use ZXN\NativeWatch\Core\Contracts\ReportingConsent;
use ZXN\NativeWatch\Core\Transport\SendResult;
use ZXN\NativeWatch\Core\Transport\TrackedTransport;
use ZXN\NativeWatch\Core\Transport\Transport;

final class Client
{
    private const int DEDUPE_SECONDS = 60;

    /**
     * @var array<string, float>
     */
    private array $recentlyCaptured = [];

    /**
     * @var (Closure(array<string, mixed>): (array<string, mixed>|null))|null
     */
    private ?Closure $beforeSend = null;

    private readonly Scrubber $scrubber;

    private readonly ConsentControl $consent;

    private readonly TrackedTransport $transport;

    public function __construct(
        private readonly Config $config,
        private readonly EventFactory $events,
        Transport $transport,
        private readonly ?Outbox $outbox = null,
        public readonly Scope $scope = new Scope,
        public readonly ActivityRecorder $activities = new ActivityRecorder,
        ?ReportingConsent $consent = null,
    ) {
        $this->scrubber = new Scrubber($config->scrub);
        $this->transport = new TrackedTransport($transport);
        $this->consent = new ConsentControl($consent, $this->scope, $this->activities);
        $this->activities->onlyWhen(fn (): bool => $this->isEnabled());
        $this->scope->onlyWhen(fn (): bool => $this->isEnabled());
    }

    /**
     * Report a throwable. Returns the event id, or null when nothing was reported.
     */
    public function captureException(Throwable $exception, ?Level $level = null): ?string
    {
        return $this->safely(function () use ($exception, $level): ?string {
            if (! $this->isEnabled() || $this->isDuplicate($exception::class.$exception->getFile().$exception->getLine().$exception->getMessage())) {
                return null;
            }

            return $this->capture(fn (): array => $this->events->fromThrowable($exception, $level ?? Level::fromThrowable($exception), $this->scope))->id;
        });
    }

    /**
     * Report a message. Returns the event id, or null when nothing was reported.
     */
    public function captureMessage(string $message, Level $level = Level::Info): ?string
    {
        return $this->safely(fn (): ?string => $this->capture(fn (): array => $this->events->fromMessage($message, $level, $this->scope))->id);
    }

    /**
     * Report an event that was built elsewhere, such as a native crash report.
     * The SDK adds the platform, release, device context and SDK name.
     *
     * @param  array<string, mixed>  $event
     */
    public function captureEvent(array $event): ?string
    {
        return $this->captureEventResult($event)->id;
    }

    /**
     * Capture an external event and distinguish a deliberate discard from a retryable failure.
     *
     * @param  array<string, mixed>  $event
     */
    public function captureEventResult(array $event): CaptureResult
    {
        return $this->safely(fn (): CaptureResult => $this->capture(fn (): array => $this->events->complete($event), $event))
            ?? CaptureResult::failed();
    }

    /**
     * Record something that happened, so the next error shows how the user got there.
     *
     * @param  array<string, mixed>  $data
     */
    public function addBreadcrumb(string $category, string $message, Level $level = Level::Info, array $data = []): void
    {
        if ($this->isEnabled()) {
            $this->scope->breadcrumbs->add($category, $message, $level, $data);
        }
    }

    /**
     * Change or drop events before they leave the device. Return null from the callback to drop the event.
     *
     * @param  (Closure(array<string, mixed>): (array<string, mixed>|null))|null  $callback
     */
    public function beforeSend(?Closure $callback): void
    {
        $this->beforeSend = $callback;
    }

    /**
     * Move the recorded activities into the outbox as one batch.
     */
    public function storeActivities(): void
    {
        $enabled = $this->isEnabled();
        $generation = $this->consent->generation();
        $entries = $this->activities->pull();

        if ($entries === [] || ! $enabled) {
            return;
        }

        $this->safely(function () use ($entries, $generation): void {
            $batch = $this->prepare(['type' => 'activities', 'entries' => $entries], $generation);

            if ($batch === null) {
                return;
            }

            $this->consent->capture($batch, $this->outbox, $this->transport);
        });
    }

    /**
     * Send the events that wait in the outbox. Returns the number of events that left it.
     */
    public function flush(int $limit = 10): int
    {
        return $this->flushReport($limit)->removed;
    }

    public function flushReport(int $limit = 10): FlushResult
    {
        if (! $this->outbox instanceof Outbox || ! $this->isEnabled()) {
            return new FlushResult(remaining: $this->pending());
        }

        return $this->safely(fn (): FlushResult => $this->outbox->flushReport(
            fn (array $event): SendResult => $this->consent->send($event, $this->transport, $this->outbox),
            $limit,
        )) ?? new FlushResult(remaining: $this->pending());
    }

    public function deliveryResult(string $id): ?SendResult
    {
        return $this->safely(fn (): ?SendResult => $this->isEnabled() ? $this->transport->resultFor($id) : null);
    }

    /**
     * Get the number of events that wait in the outbox.
     */
    public function pending(): int
    {
        return $this->outbox?->count() ?? 0;
    }

    /**
     * Determine if the client reports events.
     */
    public function isEnabled(): bool
    {
        return $this->config->isEnabled() && $this->consent->allowsReporting();
    }

    public function setConsent(bool $granted): bool
    {
        $this->recentlyCaptured = [];
        $this->transport->clear();

        return $this->safely(fn (): bool => $this->consent->setGranted($granted, $this->outbox, $this->scope, $this->activities)) ?? false;
    }

    public function requiresConsent(): bool
    {
        return $this->consent->isRequired();
    }

    public function hasConsent(): bool
    {
        return $this->requiresConsent() && $this->consent->allowsReporting();
    }

    /**
     * @param  Closure(): array<string, mixed>  $build
     * @param  array<string, mixed>  $origin
     */
    private function capture(Closure $build, array $origin = []): CaptureResult
    {
        $generation = $this->consent->generation();

        if (! $this->config->isEnabled() || $generation === null || ! $this->consent->acceptsOrigin($origin, $generation) || ! $this->isSampled()) {
            return CaptureResult::discarded();
        }

        $event = $this->prepare($build(), $generation);

        if ($event === null) {
            return CaptureResult::discarded();
        }

        return $this->consent->capture($event, $this->outbox, $this->transport);
    }

    /**
     * @param  array<string, mixed>  $event
     * @return array<string, mixed>|null
     */
    private function prepare(array $event, ?string $generation): ?array
    {
        $event = $this->beforeSend instanceof Closure ? ($this->beforeSend)($event) : $event;

        if ($event === null) {
            return null;
        }

        foreach (['message', 'exception.value'] as $key) {
            $value = Arr::get($event, $key);

            if (is_string($value)) {
                Arr::set($event, $key, mb_substr($value, 0, 8_192));
            }
        }

        return $this->consent->stamp($this->scrubber->scrub($event), $generation);
    }

    private function isSampled(): bool
    {
        return $this->config->sampleRate >= 1.0
            || random_int(0, PHP_INT_MAX) / PHP_INT_MAX < $this->config->sampleRate;
    }

    /**
     * Determine if an equal event was captured in the last minute, and remember this one.
     */
    private function isDuplicate(string $signature): bool
    {
        $now = microtime(true);
        $key = hash('xxh128', $signature);

        $this->recentlyCaptured = array_filter(
            $this->recentlyCaptured,
            fn (float $capturedAt): bool => $now - $capturedAt < self::DEDUPE_SECONDS,
        );

        if (isset($this->recentlyCaptured[$key])) {
            return true;
        }

        $this->recentlyCaptured[$key] = $now;

        return false;
    }

    /**
     * Run the callback, and never let an error of the SDK break the app.
     *
     * @template TResult
     *
     * @param  Closure(): TResult  $callback
     * @return TResult|null
     */
    private function safely(Closure $callback): mixed
    {
        try {
            return $callback();
        } catch (Throwable) {
            return null;
        }
    }
}
