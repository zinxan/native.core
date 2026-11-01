<?php

declare(strict_types=1);

namespace ZXN\NativeWatch\Core\Laravel;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use ZXN\NativeWatch\Core\BrowserStack;
use ZXN\NativeWatch\Core\Client;
use ZXN\NativeWatch\Core\Level;

/**
 * Receives the JavaScript errors that @nativewatchScripts reports from the web view.
 */
final readonly class BrowserErrorController
{
    public function __construct(private Client $client) {}

    public function __invoke(Request $request): Response
    {
        $error = $request->validate([
            'type' => ['nullable', 'string', 'max:255'],
            'message' => ['required', 'string', 'max:8192'],
            'stack' => ['nullable', 'string', 'max:16384'],
            'url' => ['nullable', 'string', 'max:2048'],
            'unhandled_rejection' => ['nullable', 'boolean'],
            'handled' => ['nullable', 'boolean'],
        ]);

        $url = (string) ($error['url'] ?? '');

        $this->client->captureEvent([
            'level' => Level::Error->value,
            'exception' => [
                'type' => $error['type'] ?? 'Error',
                'value' => $error['message'],
                'frames' => new BrowserStack(BrowserStack::originOf($url))->frames((string) ($error['stack'] ?? '')),
            ],
            'context' => [
                'browser' => ['url' => $url, 'user_agent' => $request->userAgent()],
                'crash' => [
                    'mechanism' => ($error['unhandled_rejection'] ?? false) ? 'webview.unhandledrejection' : 'webview',
                    'handled' => $error['handled'] ?? false,
                ],
            ],
        ]);

        return response()->noContent();
    }
}
