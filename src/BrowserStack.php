<?php

declare(strict_types=1);

namespace ZXN\NativeWatch\Core;

final readonly class BrowserStack
{
    private const int MAX_FRAMES = 50;

    /**
     * Chrome and Android: "    at renderNote (http://127.0.0.1/app.js:12:5)" or "    at http://127.0.0.1/app.js:12:5".
     */
    private const string V8 = '/^\s*at (?:(?<function>.+?) \()?(?<file>(?:https?|file|php|blob):.+?):(?<line>\d+)(?::\d+)?\)?$/';

    /**
     * Safari (WebKit on iOS and macOS): "renderNote@http://127.0.0.1/app.js:12:5".
     */
    private const string WEBKIT = '/^(?<function>[^@]*)@(?<file>.+?):(?<line>\d+)(?::\d+)?$/';

    /**
     * @param  string  $origin  The scheme and host of the page. Frames of this origin belong to the app.
     */
    public function __construct(private string $origin) {}

    /**
     * Get the scheme, host and port of a URL.
     */
    public static function originOf(string $url): string
    {
        $parts = parse_url($url);

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return '';
        }

        return $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
    }

    /**
     * Turn the stack of a JavaScript error into frames, the throw site first.
     *
     * @return list<array{file: string, line: int, function: string|null, class: null, in_app: bool}>
     */
    public function frames(string $stack): array
    {
        $frames = [];

        foreach (preg_split('/\R/', $stack) ?: [] as $line) {
            if (count($frames) === self::MAX_FRAMES) {
                break;
            }

            if (preg_match(self::V8, $line, $match) === 1 || preg_match(self::WEBKIT, mb_trim($line), $match) === 1) {
                $frames[] = $this->frame($match['file'], (int) $match['line'], $match['function']);
            }
        }

        return $frames;
    }

    /**
     * @return array{file: string, line: int, function: string|null, class: null, in_app: bool}
     */
    private function frame(string $url, int $line, string $function): array
    {
        $inApp = $this->origin !== '' && str_starts_with($url, $this->origin.'/');

        return [
            'file' => $inApp ? mb_substr($url, mb_strlen($this->origin)) : $url,
            'line' => $line,
            'function' => $function === '' ? null : mb_substr($function, 0, 255),
            'class' => null,
            'in_app' => $inApp,
        ];
    }
}
