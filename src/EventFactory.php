<?php

declare(strict_types=1);

namespace ZXN\NativeWatch\Core;

use DateTimeImmutable;
use DateTimeInterface;
use Throwable;
use ZXN\NativeWatch\Core\Contracts\ContextProvider;

final readonly class EventFactory
{
    public const string SDK_VERSION = '0.2.1';

    /**
     * The keys that an event built elsewhere may set itself.
     */
    private const array COMPLETABLE_KEYS = ['event_id', 'timestamp', 'level', 'message', 'exception', 'release', 'environment', 'fingerprint', 'breadcrumbs'];

    private StackFrames $stackFrames;

    public function __construct(
        private Config $config,
        private ContextProvider $contextProvider,
        private string $sdkName,
    ) {
        $this->stackFrames = new StackFrames($config->basePath, $config->sourceCodeLines);
    }

    /**
     * Build the event of a throwable, in the shape of the ingest API.
     *
     * @return array<string, mixed>
     */
    public function fromThrowable(Throwable $exception, Level $level, Scope $scope): array
    {
        $event = $this->base($level, $scope);

        $event['exception'] = [
            'type' => $exception::class,
            'value' => $exception->getMessage(),
            'frames' => $this->stackFrames->fromThrowable($exception),
        ];

        // Laravel adds " (View: <compiled path>)" to the message. The path changes with each
        // build, and on iOS with each install, so it moves to the context.
        if (preg_match('/^(?<message>.*) \(View: (?<view>[^()]+)\)$/s', $exception->getMessage(), $match) === 1) {
            $event['exception']['value'] = $match['message'];
            $event['context']['view'] = $this->stackFrames->templateOf($match['view']) ?? $match['view'];
        }

        if ($exception->getPrevious() instanceof Throwable) {
            $event['context']['previous'] = $this->previous($exception);
        }

        $context = $this->contextOf($exception);

        if ($context !== []) {
            $event['context']['exception'] = $context;
        }

        return $event;
    }

    /**
     * Build the event of a message, in the shape of the ingest API.
     *
     * @return array<string, mixed>
     */
    public function fromMessage(string $message, Level $level, Scope $scope): array
    {
        return [...$this->base($level, $scope), 'message' => $message];
    }

    /**
     * Complete an event that was built elsewhere, such as a native crash report.
     *
     * The scope is left out, because such an event happened at another time.
     * Native exception frames retain their image UUID, CPU architecture, binary
     * offset and address kind; server symbolication needs that exact evidence.
     *
     * @param  array<string, mixed>  $event
     * @return array<string, mixed>
     */
    public function complete(array $event): array
    {
        $base = $this->base(Level::Error, new Scope(0));
        $context = is_array($event['context'] ?? null) ? $event['context'] : [];

        return [
            ...$base,
            ...array_intersect_key($event, array_flip(self::COMPLETABLE_KEYS)),
            'context' => [...$base['context'], ...$context],
        ];
    }

    /**
     * Get the data of the exception's own context() method, as Laravel's logger reads it.
     *
     * @return array<array-key, mixed>
     */
    private function contextOf(Throwable $exception): array
    {
        if (! method_exists($exception, 'context')) {
            return [];
        }

        try {
            $context = $exception->context();
        } catch (Throwable) {
            return [];
        }

        return is_array($context) ? $context : [];
    }

    /**
     * @return array<string, mixed>
     */
    private function base(Level $level, Scope $scope): array
    {
        return [
            'event_id' => $this->uuid(),
            'timestamp' => new DateTimeImmutable()->format(DateTimeInterface::ATOM),
            'level' => $level->value,
            'platform' => $this->contextProvider->platform(),
            'release' => $this->config->release ?? $this->contextProvider->release(),
            'environment' => $this->config->environment,
            'context' => [
                ...$this->contextProvider->context(),
                ...$scope->toContext(),
            ],
            'breadcrumbs' => $scope->breadcrumbs->all(),
            'sdk' => ['name' => $this->sdkName, 'version' => self::SDK_VERSION],
        ];
    }

    /**
     * @return list<array{type: string, value: string}>
     */
    private function previous(Throwable $exception): array
    {
        $previous = [];

        for ($cause = $exception->getPrevious(), $depth = 0; $cause instanceof Throwable && $depth < 5; $cause = $cause->getPrevious(), $depth++) {
            $previous[] = ['type' => $cause::class, 'value' => $cause->getMessage()];
        }

        return $previous;
    }

    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', mb_str_split(bin2hex($bytes), 4));
    }
}
