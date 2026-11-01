<?php

declare(strict_types=1);

namespace ZXN\NativeWatch\Core\Laravel\Watchers;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Foundation\Http\Events\RequestHandled;
use ZXN\NativeWatch\Core\Activity\ActivityRecorder;

final class RequestWatcher implements Watcher
{
    public function signal(): string
    {
        return 'requests';
    }

    /**
     * @param  array<string, mixed>  $options  "ignore": path patterns to skip, such as "_native/*".
     */
    public function register(Application $app, ActivityRecorder $recorder, array $options): void
    {
        $ignore = is_array($options['ignore'] ?? null) ? $options['ignore'] : ['_native/*', 'up'];

        $app->make(Dispatcher::class)->listen(RequestHandled::class, function (RequestHandled $event) use ($recorder, $ignore): void {
            $request = $event->request;

            if ($request->is(...$ignore)) {
                return;
            }

            $startedAt = (float) $request->server('REQUEST_TIME_FLOAT', microtime(true));

            $recorder->record(
                $this->signal(),
                $request->method(),
                (string) $event->response->getStatusCode(),
                (microtime(true) - $startedAt) * 1000,
                ['path' => '/'.mb_ltrim($request->path(), '/'), 'route' => $request->route()?->getName()],
            );
        });
    }
}
