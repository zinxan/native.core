<?php

declare(strict_types=1);

use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\View;
use ZXN\NativeWatch\Core\Client;

final class NativeWatchExceptionWithContext extends RuntimeException
{
    /**
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return ['note_id' => 7, 'api_token' => 'secret'];
    }
}

it('adds the context of the exception and scrubs it', function (): void {
    fakeNativeWatch();

    report(new NativeWatchExceptionWithContext('The note could not be saved.'));

    expect(sentOccurrences()[0]['context']['exception'])->toBe(['note_id' => 7, 'api_token' => '[filtered]']);
});

it('reports an exception that the app only logs', function (): void {
    fakeNativeWatch();

    try {
        throw new RuntimeException('The sync server is down.');
    } catch (RuntimeException $exception) {
        Log::error('Sync failed', ['exception' => $exception]);
    }

    expect(sentOccurrences())->toHaveCount(1)
        ->and(sentOccurrences()[0]['exception']['value'])->toBe('The sync server is down.');
});

it('reports a logged exception once when the handler also reports it', function (): void {
    fakeNativeWatch();

    report(new RuntimeException('The sync server is down.'));

    expect(sentOccurrences())->toHaveCount(1);
});

it('can leave logged exceptions alone', function (): void {
    fakeNativeWatch(['nativewatch.logs.capture_exceptions' => false]);

    Log::error('Sync failed', ['exception' => new RuntimeException('The sync server is down.')]);

    expect(sentOccurrences())->toBe([]);
});

it('starts each job of a queue worker with an empty scope', function (): void {
    fakeNativeWatch();
    $client = app(Client::class);
    $client->scope->setUser(['id' => 1]);
    $client->addBreadcrumb('log', 'From the last job');

    event(new JobProcessing('database', Mockery::mock(Job::class, ['payload' => [], 'getJobId' => '1'])));

    expect($client->scope->toContext())->not->toHaveKey('user')
        ->and($client->scope->breadcrumbs->all())->toBe([]);
});

it('keeps the scope of the request for a job that runs in it', function (): void {
    fakeNativeWatch();
    $client = app(Client::class);
    $client->scope->setUser(['id' => 1]);

    event(new JobProcessing('sync', Mockery::mock(Job::class, ['payload' => [], 'getJobId' => '1'])));

    expect($client->scope->toContext())->toHaveKey('user');
});

it('reports errors of the web view', function (): void {
    fakeNativeWatch();

    $this->postJson('/_nativewatch/browser-errors', [
        'type' => 'TypeError',
        'message' => "Cannot read properties of undefined (reading 'title')",
        'stack' => "TypeError: Cannot read properties of undefined (reading 'title')\n    at renderNote (http://127.0.0.1/build/assets/app.js:12:5)\n    at https://cdn.example.com/lib.js:1:200",
        'url' => 'http://127.0.0.1/notes/7',
    ])->assertNoContent();

    $event = sentOccurrences()[0];

    expect($event['exception']['type'])->toBe('TypeError')
        ->and($event['exception']['frames'][0])->toMatchArray(['file' => '/build/assets/app.js', 'line' => 12, 'function' => 'renderNote', 'in_app' => true])
        ->and($event['exception']['frames'][1])->toMatchArray(['file' => 'https://cdn.example.com/lib.js', 'line' => 1, 'in_app' => false])
        ->and($event['context']['browser']['url'])->toBe('http://127.0.0.1/notes/7')
        ->and($event['context']['crash']['mechanism'])->toBe('webview');
});

it('reads the stack of Safari', function (): void {
    fakeNativeWatch();

    $this->postJson('/_nativewatch/browser-errors', [
        'type' => 'Error',
        'message' => 'Boom',
        'stack' => "renderNote@http://127.0.0.1/build/assets/app.js:12:5\n@http://127.0.0.1/build/assets/app.js:40:1",
        'url' => 'http://127.0.0.1/',
    ])->assertNoContent();

    expect(sentOccurrences()[0]['exception']['frames'][0])->toMatchArray(['file' => '/build/assets/app.js', 'line' => 12, 'function' => 'renderNote']);
});

it('preserves whether a manually reported browser error was handled', function (): void {
    fakeNativeWatch();

    $this->postJson('/_nativewatch/browser-errors', [
        'type' => 'Error',
        'message' => 'That file is not a supported media or 3D format.',
        'handled' => true,
    ])->assertNoContent();

    expect(sentOccurrences()[0]['context']['crash']['handled'])->toBeTrue();
});

it('rejects a browser error without a message', function (): void {
    fakeNativeWatch();

    $this->postJson('/_nativewatch/browser-errors', ['type' => 'Error'])->assertUnprocessable();
});

it('can leave errors of the web view alone', function (): void {
    fakeNativeWatch(['nativewatch.browser.enabled' => false]);

    $this->postJson('/_nativewatch/browser-errors', ['type' => 'Error', 'message' => 'Boom'])->assertNotFound();
});

it('renders the script that reports errors of the web view', function (): void {
    fakeNativeWatch();

    $html = Blade::render('@nativewatchScripts');

    expect($html)->toContain('<script')
        ->toContain('/_nativewatch/browser-errors')
        ->toContain('unhandledrejection');
});

it('reports an error of a Blade view by its template, not its compiled file', function (): void {
    fakeNativeWatch();
    $directory = temporaryOutbox();
    mkdir($directory.'/views', 0755, true);
    file_put_contents($directory.'/views/note.blade.php', "<h1>{{ \$note->title }}</h1>\n");
    View::addLocation($directory.'/views');

    try {
        view('note', ['note' => null])->render();
    } catch (Throwable $exception) {
        report($exception);
    }

    $event = sentOccurrences()[0];

    expect($event['exception']['value'])->toBe('Attempt to read property "title" on null')
        ->and($event['exception']['frames'][0]['file'])->toEndWith('views/note.blade.php')
        ->and($event['exception']['frames'][0]['code'])->toBeNull()
        ->and($event['context']['view'])->toEndWith('views/note.blade.php');
});

it('renders a supplied CSP nonce without allowing it to change script attributes', function (string $nonce): void {
    fakeNativeWatch();

    $html = Blade::render('@nativewatchScripts($nonce)', ['nonce' => $nonce]);

    expect($html)->toContain('<script nonce="'.e($nonce).'">');
})->with(['request-nonce', 'nonce" onload="alert(1)']);
