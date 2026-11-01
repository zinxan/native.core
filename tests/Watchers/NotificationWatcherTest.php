<?php

declare(strict_types=1);

use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Notification as Notifications;

final class NativeWatchSyncFinished extends Notification
{
    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, string>
     */
    public function toArray(object $notifiable): array
    {
        return ['message' => 'Sync finished'];
    }
}

it('records sent and failed notifications with their channel', function (): void {
    fakeNativeWatch();
    $user = App\Models\User::factory()->make();

    Illuminate\Support\Facades\Schema::create('notifications', function (Illuminate\Database\Schema\Blueprint $table): void {
        $table->uuid('id')->primary();
        $table->string('type');
        $table->morphs('notifiable');
        $table->text('data');
        $table->timestamp('read_at')->nullable();
        $table->timestamps();
    });
    $user->id = 1;

    Notifications::sendNow($user, new NativeWatchSyncFinished);
    event(new NotificationFailed($user, new NativeWatchSyncFinished, 'mail'));

    $entries = sentActivities('notifications');

    expect($entries)->toHaveCount(2)
        ->and($entries[0])->name->toBe(NativeWatchSyncFinished::class)->status->toBe('sent')
        ->and($entries[0]['attributes'])->toBe(['channel' => 'database'])
        ->and($entries[1])->status->toBe('failed');
});
