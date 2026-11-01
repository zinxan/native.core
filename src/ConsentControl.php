<?php

declare(strict_types=1);

namespace ZXN\NativeWatch\Core;

use ZXN\NativeWatch\Core\Activity\ActivityRecorder;
use ZXN\NativeWatch\Core\Contracts\ReportingConsent;
use ZXN\NativeWatch\Core\Transport\SendResult;
use ZXN\NativeWatch\Core\Transport\Transport;

final class ConsentControl
{
    private const string KEY = '_nativewatch_consent_generation';

    private ?string $previousGeneration = null;

    public function __construct(private readonly ?ReportingConsent $consent, private readonly Scope $scope, private readonly ActivityRecorder $activities) {}

    public function generation(): ?string
    {
        $generation = $this->consent === null ? 'unrestricted' : $this->consent->generation();

        if ($generation !== $this->previousGeneration) {
            $this->scope->clear();
            $this->activities->pull();
            $this->previousGeneration = $generation;
        }

        return $generation;
    }

    public function allowsReporting(): bool
    {
        return $this->generation() !== null;
    }

    public function isRequired(): bool
    {
        return $this->consent !== null;
    }

    public function setGranted(bool $granted, ?Outbox $outbox, Scope $scope, ActivityRecorder $activities): bool
    {
        $operation = fn (): bool => $this->update($granted, $outbox, $scope, $activities);

        return $outbox === null ? $operation() : ($outbox->synchronized($operation) ?? false);
    }

    /** @param array<string, mixed> $event
     * @return array<string, mixed>
     */
    public function stamp(array $event, ?string $generation): array
    {
        return $this->consent === null ? $event : [...$event, self::KEY => $generation];
    }

    /** @param array<string, mixed> $event */
    public function acceptsOrigin(array $event, ?string $generation): bool
    {
        return ! array_key_exists(self::KEY, $event) || $event[self::KEY] === $generation;
    }

    /** @param array<string, mixed> $event */
    public function capture(array $event, ?Outbox $outbox, Transport $transport): CaptureResult
    {
        $operation = function () use ($event, $outbox, $transport): CaptureResult {
            if (! $this->accepts($event)) {
                return CaptureResult::discarded();
            }

            $id = is_string($event['event_id'] ?? null) ? $event['event_id'] : null;

            if ($outbox?->push($event)) {
                return CaptureResult::accepted($id);
            }

            return match ($this->send($event, $transport)) {
                SendResult::Sent => CaptureResult::accepted($id),
                SendResult::Rejected => CaptureResult::discarded(),
                SendResult::Failed => CaptureResult::failed(),
            };
        };

        return $this->consent !== null && $outbox !== null ? ($outbox->synchronized($operation) ?? CaptureResult::failed()) : $operation();
    }

    /** @param array<string, mixed> $event */
    public function send(array $event, Transport $transport, ?Outbox $outbox = null): SendResult
    {
        $operation = function () use ($event, $transport): SendResult {
            if (! $this->accepts($event)) {
                return SendResult::Rejected;
            }

            unset($event[self::KEY]);

            return $transport->send($event);
        };

        return $this->consent !== null && $outbox !== null ? ($outbox->synchronized($operation) ?? SendResult::Failed) : $operation();
    }

    private function update(bool $granted, ?Outbox $outbox, Scope $scope, ActivityRecorder $activities): bool
    {
        if ($this->consent === null) {
            return false;
        }

        if ($granted && $this->allowsReporting()) {
            return true;
        }

        if (! $this->consent->setGranted(false)) {
            return false;
        }

        $scope->clear();
        $activities->pull();

        if ($outbox !== null && ! $outbox->clear()) {
            return false;
        }

        return ! $granted || $this->consent->setGranted(true);
    }

    /** @param array<string, mixed> $event */
    private function accepts(array $event): bool
    {
        return $this->consent === null || (($event[self::KEY] ?? null) !== null && $event[self::KEY] === $this->consent->generation());
    }
}
