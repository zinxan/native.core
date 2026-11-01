<?php

declare(strict_types=1);

namespace ZXN\NativeWatch\Core\Laravel\Watchers;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Events\ConnectionFailed;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Http\Client\Request;
use ZXN\NativeWatch\Core\Activity\ActivityRecorder;
use ZXN\NativeWatch\Core\Config;

final class OutgoingRequestWatcher implements Watcher
{
    public function signal(): string
    {
        return 'outgoing-requests';
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public function register(Application $app, ActivityRecorder $recorder, array $options): void
    {
        $ownHost = parse_url($app->make(Config::class)->url, PHP_URL_HOST);
        $events = $app->make(Dispatcher::class);

        $events->listen(ResponseReceived::class, function (ResponseReceived $event) use ($recorder, $ownHost): void {
            if ($this->isOwn($event->request, $ownHost)) {
                return;
            }

            $seconds = $event->response->transferStats?->getTransferTime();

            $this->record($recorder, $event->request, (string) $event->response->status(), $seconds === null ? null : $seconds * 1000);
        });

        $events->listen(ConnectionFailed::class, function (ConnectionFailed $event) use ($recorder, $ownHost): void {
            if (! $this->isOwn($event->request, $ownHost)) {
                $this->record($recorder, $event->request, 'failed');
            }
        });
    }

    private function record(ActivityRecorder $recorder, Request $request, string $status, ?float $durationMs = null): void
    {
        $recorder->record($this->signal(), $request->method(), $status, $durationMs, ['url' => $this->withoutQuery($request->url())]);
    }

    /**
     * Skip the requests that the SDK sends to NativeWatch, or every flush records new activity.
     */
    private function isOwn(Request $request, mixed $ownHost): bool
    {
        return is_string($ownHost) && parse_url($request->url(), PHP_URL_HOST) === $ownHost;
    }

    /**
     * Drop the query string, because it often holds tokens.
     */
    private function withoutQuery(string $url): string
    {
        return explode('?', $url, 2)[0];
    }
}
