<?php

declare(strict_types=1);

namespace ZXN\NativeWatch\Core;

use SplFileObject;
use Throwable;

final readonly class StackFrames
{
    private const int MAX_FRAMES = 100;

    private const int MAX_FRAMES_WITH_CODE = 10;

    private const int MAX_LINE_LENGTH = 500;

    public function __construct(
        private string $basePath = '',
        private int $codeLines = 5,
    ) {}

    /**
     * Get the frames of the throwable, starting with the line that threw.
     *
     * @return list<array{file: string, line: int, function: string|null, class: string|null, in_app: bool, code: array{start: int, lines: list<string>}|null}>
     */
    public function fromThrowable(Throwable $exception): array
    {
        $trace = $exception->getTrace();
        $frames = [$this->frame($exception->getFile(), $exception->getLine(), $trace[0] ?? [])];

        foreach ($trace as $index => $call) {
            if (! isset($call['file'], $call['line'])) {
                continue;
            }

            $frames[] = $this->frame($call['file'], $call['line'], $trace[$index + 1] ?? []);
        }

        return $this->withCode(array_slice($frames, 0, self::MAX_FRAMES));
    }

    /**
     * Get the template of a compiled Blade view. Laravel ends each compiled view with
     * "/**PATH <template> ENDPATH**\/". The compiled path holds a hash, and on iOS the
     * container of the app, which changes with each install, so it must never group an issue.
     */
    public function templateOf(string $file): ?string
    {
        if (! str_contains($file, '/framework/views/') || ! is_file($file) || ! is_readable($file)) {
            return null;
        }

        $handle = @fopen($file, 'rb');

        if ($handle === false) {
            return null;
        }

        fseek($handle, -min(1024, (int) filesize($file)), SEEK_END);
        $tail = (string) fread($handle, 1024);
        fclose($handle);

        return preg_match('~/\*\*PATH (.+?) ENDPATH\*\*/~', $tail, $match) === 1 ? $match[1] : null;
    }

    /**
     * @param  array{function?: string, class?: string}  $caller  The call that the line belongs to.
     * @return array{file: string, line: int, function: string|null, class: string|null, in_app: bool, path: string}
     */
    private function frame(string $file, int $line, array $caller): array
    {
        $file = $this->templateOf($file) ?? $file;
        $relative = $this->relative($file);

        return [
            'file' => $relative,
            'line' => $line,
            'function' => $caller['function'] ?? null,
            'class' => $caller['class'] ?? null,
            'in_app' => $relative !== $file && ! str_starts_with($relative, 'vendor/'),
            'path' => $file,
        ];
    }

    /**
     * Add the source code around the line to the throw site and to the frames of the app.
     *
     * @param  list<array{file: string, line: int, function: string|null, class: string|null, in_app: bool, path: string}>  $frames
     * @return list<array{file: string, line: int, function: string|null, class: string|null, in_app: bool, code: array{start: int, lines: list<string>}|null}>
     */
    private function withCode(array $frames): array
    {
        $budget = self::MAX_FRAMES_WITH_CODE;

        return array_map(function (array $frame) use (&$budget): array {
            $wantsCode = $budget > 0 && ($frame['in_app'] || $budget === self::MAX_FRAMES_WITH_CODE);
            $code = $wantsCode ? $this->code($frame['path'], $frame['line']) : null;

            if ($code !== null) {
                $budget--;
            }

            unset($frame['path']);

            return [...$frame, 'code' => $code];
        }, $frames);
    }

    /**
     * Read the lines around the given line of the file.
     *
     * @return array{start: int, lines: list<string>}|null
     */
    private function code(string $path, int $line): ?array
    {
        // The line number belongs to the compiled view, so lines of the template would mislead.
        if (str_ends_with($path, '.blade.php')) {
            return null;
        }

        if ($this->codeLines < 1 || $line < 1 || ! is_file($path) || ! is_readable($path)) {
            return null;
        }

        $start = max(1, $line - $this->codeLines);
        $lines = [];
        $file = new SplFileObject($path);
        $file->seek($start - 1);

        while (! $file->eof() && count($lines) <= $line - $start + $this->codeLines) {
            $current = $file->current();
            $lines[] = is_string($current) ? mb_substr(mb_rtrim($current, "\r\n"), 0, self::MAX_LINE_LENGTH) : '';
            $file->next();
        }

        return $lines === [] ? null : ['start' => $start, 'lines' => $lines];
    }

    private function relative(string $file): string
    {
        // On iOS, storage lives outside the base path, in a container whose name changes with
        // each install. Compiled views are in storage, so their path starts at "storage/".
        $storage = mb_strpos($file, '/storage/framework/');

        if ($storage !== false && ! str_starts_with($file, mb_rtrim($this->basePath, '/').'/')) {
            return mb_substr($file, $storage + 1);
        }

        $base = mb_rtrim($this->basePath, '/').'/';

        return $this->basePath !== '' && str_starts_with($file, $base)
            ? mb_substr($file, mb_strlen($base))
            : $file;
    }
}
