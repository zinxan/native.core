<?php

declare(strict_types=1);

namespace ZXN\NativeWatch\Core;

use ErrorException;
use Throwable;

enum Level: string
{
    case Fatal = 'fatal';
    case Error = 'error';
    case Warning = 'warning';
    case Info = 'info';
    case Debug = 'debug';

    /**
     * Get the level that matches the throwable.
     */
    public static function fromThrowable(Throwable $exception): self
    {
        if (! $exception instanceof ErrorException) {
            return self::Error;
        }

        return match ($exception->getSeverity()) {
            E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE => self::Fatal,
            E_WARNING, E_CORE_WARNING, E_COMPILE_WARNING, E_USER_WARNING => self::Warning,
            E_NOTICE, E_USER_NOTICE, E_DEPRECATED, E_USER_DEPRECATED => self::Info,
            default => self::Error,
        };
    }

    /**
     * Get the level that matches a PSR-3 log level.
     */
    public static function fromLogLevel(string $level): self
    {
        return match ($level) {
            'emergency', 'alert', 'critical' => self::Fatal,
            'error' => self::Error,
            'warning' => self::Warning,
            'debug' => self::Debug,
            default => self::Info,
        };
    }
}
