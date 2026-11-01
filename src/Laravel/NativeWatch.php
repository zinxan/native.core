<?php

declare(strict_types=1);

namespace ZXN\NativeWatch\Core\Laravel;

use Illuminate\Support\Facades\Facade;
use ZXN\NativeWatch\Core\Client;

/**
 * @method static string|null captureException(\Throwable $exception, \ZXN\NativeWatch\Core\Level|null $level = null)
 * @method static string|null captureEvent(array<string, mixed> $event)
 * @method static string|null captureMessage(string $message, \ZXN\NativeWatch\Core\Level $level = \ZXN\NativeWatch\Core\Level::Info)
 * @method static void addBreadcrumb(string $category, string $message, \ZXN\NativeWatch\Core\Level $level = \ZXN\NativeWatch\Core\Level::Info, array<string, mixed> $data = [])
 * @method static void beforeSend(\Closure|null $callback)
 * @method static int flush(int $limit = 10)
 * @method static \ZXN\NativeWatch\Core\FlushResult flushReport(int $limit = 10)
 * @method static \ZXN\NativeWatch\Core\Transport\SendResult|null deliveryResult(string $id)
 * @method static int pending()
 * @method static bool isEnabled()
 * @method static bool setConsent(bool $granted)
 * @method static bool requiresConsent()
 * @method static bool hasConsent()
 *
 * @see Client
 */
final class NativeWatch extends Facade
{
    /**
     * Get the registered name of the component.
     */
    protected static function getFacadeAccessor(): string
    {
        return Client::class;
    }
}
