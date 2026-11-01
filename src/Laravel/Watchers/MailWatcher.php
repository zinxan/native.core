<?php

declare(strict_types=1);

namespace ZXN\NativeWatch\Core\Laravel\Watchers;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Mail\Events\MessageSent;
use ZXN\NativeWatch\Core\Activity\ActivityRecorder;

final class MailWatcher implements Watcher
{
    public function signal(): string
    {
        return 'mail';
    }

    /**
     * Records the mailable and the mailer, never the addresses: they are personal data.
     *
     * @param  array<string, mixed>  $options
     */
    public function register(Application $app, ActivityRecorder $recorder, array $options): void
    {
        $app->make(Dispatcher::class)->listen(MessageSent::class, function (MessageSent $event) use ($app, $recorder): void {
            $mailable = $event->data['__laravel_mailable'] ?? null;

            $recorder->record(
                $this->signal(),
                is_string($mailable) ? $mailable : ($event->message->getSubject() ?? 'Mail'),
                'sent',
                null,
                [
                    'mailer' => $event->data['mailer'] ?? $app->make('config')->get('mail.default'),
                    'recipients' => count($event->message->getTo()),
                ],
            );
        });
    }
}
