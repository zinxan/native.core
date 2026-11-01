<?php

declare(strict_types=1);

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Facades\Mail;

final class NativeWatchWelcomeMail extends Mailable
{
    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Welcome');
    }

    public function content(): Content
    {
        return new Content(htmlString: '<p>Hi</p>');
    }
}

it('records sent mail without the addresses', function (): void {
    fakeNativeWatch(['mail.default' => 'array']);

    Mail::to('taylor@example.com')->send(new NativeWatchWelcomeMail);

    $entries = sentActivities('mail');

    expect($entries)->toHaveCount(1)
        ->and($entries[0])->name->toBe(NativeWatchWelcomeMail::class)->status->toBe('sent')
        ->and($entries[0]['attributes'])->toBe(['mailer' => 'array', 'recipients' => 1])
        ->and(json_encode($entries))->not->toContain('taylor@example.com');
});
