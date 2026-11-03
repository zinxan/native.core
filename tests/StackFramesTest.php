<?php

declare(strict_types=1);

use ZXN\NativeWatch\Core\StackFrames;

it('starts with the line that threw and marks frames of the app', function (): void {
    $exception = new RuntimeException('Boom');
    $basePath = dirname(__DIR__);

    $frames = new StackFrames($basePath)->fromThrowable($exception);

    expect($frames[0])
        ->file->toBe('tests/StackFramesTest.php')
        ->line->toBe(__LINE__ - 7)
        ->in_app->toBeTrue();

    $vendorFrames = array_filter($frames, fn (array $frame): bool => str_starts_with($frame['file'], 'vendor/'));

    expect($vendorFrames)->not->toBeEmpty()
        ->each(fn ($frame) => $frame->in_app->toBeFalse());
});

it('does not mark frames outside the base path as in app', function (): void {
    $frames = new StackFrames('/somewhere/else')->fromThrowable(new RuntimeException('Boom'));

    expect($frames[0]['in_app'])->toBeFalse()
        ->and($frames[0]['file'])->toBe(__FILE__);
});

it('sends the source code around the line of the throw site and of app frames', function (): void {
    $line = __LINE__ + 1;
    $exception = new RuntimeException('Boom');

    $frames = new StackFrames(dirname(__DIR__), codeLines: 2)->fromThrowable($exception);
    $code = $frames[0]['code'];

    expect($code)->not->toBeNull()
        ->and($code['start'])->toBe($line - 2)
        ->and($code['lines'])->toHaveCount(5)
        ->and($code['lines'][2])->toContain("new RuntimeException('Boom')");

    $vendorFrames = array_filter($frames, fn (array $frame): bool => ! $frame['in_app'] && $frame !== $frames[0]);

    expect(array_column($vendorFrames, 'code'))->each->toBeNull();
});

it('sends no source code when it is turned off', function (): void {
    $frames = new StackFrames(dirname(__DIR__), codeLines: 0)->fromThrowable(new RuntimeException('Boom'));

    expect(array_column($frames, 'code'))->each->toBeNull();
});

it('trims the changing iOS container from paths in storage', function (): void {
    $frames = new StackFrames('/Containers/A1/Documents/app');
    $exception = new RuntimeException('Boom');
    $property = new ReflectionProperty(Exception::class, 'file');
    $property->setValue($exception, '/Containers/A1/Library/Application Support/storage/framework/views/abc.php');

    expect($frames->fromThrowable($exception)[0]['file'])->toBe('storage/framework/views/abc.php');
});
