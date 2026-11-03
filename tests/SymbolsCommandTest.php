<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use ZXN\NativeWatch\Core\Releases\FindDebugSymbols;
use ZXN\NativeWatch\Desktop\DesktopServiceProvider;

beforeEach(function (): void {
    Http::preventStrayRequests();
    $this->app->register(DesktopServiceProvider::class);
    config(['nativewatch.url' => 'http://zxguard.test']);
    putenv('NATIVEWATCH_RELEASE_TOKEN=nwr_ci-symbol-secret');
    $this->dwarf = __DIR__.'/Fixtures/Symbols/CrashFixture.dwarf';
    $this->receipt = ['symbols' => [['imageUuid' => 'bf2dbb4a-b545-3baa-a8a7-8b40b5ea12c5', 'architecture' => ['value' => 'arm64'], 'sha256' => hash_file('sha256', $this->dwarf)]]];
});

afterEach(function (): void {
    putenv('NATIVEWATCH_RELEASE_TOKEN');
});

it('uploads a real compiled artifact with CI auth and verifies its checksum receipt', function (): void {
    Http::fake(['zxguard.test/api/v1/debug-symbols' => Http::response($this->receipt, 201)]);
    $this->artisan('nativewatch:symbols', ['path' => $this->dwarf, '--source-root' => '/nativewatch-fixtures'])
        ->expectsOutputToContain('bf2dbb4a-b545-3baa-a8a7-8b40b5ea12c5 (arm64)')->assertSuccessful();
    Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer nwr_ci-symbol-secret') && ! $request->hasHeader('X-NativeWatch-Key') && $request->isMultipart());
});

it('fails honestly on rejected uploads and unreachable servers', function (int $status): void {
    Http::fake(['*' => $status === 0 ? Http::failedConnection() : Http::response('secret-server-body', $status)]);
    $this->artisan('nativewatch:symbols', ['path' => $this->dwarf, '--source-root' => '/nativewatch-fixtures'])->assertFailed();
})->with([0, 401, 409, 413, 422, 429, 500]);

it('rejects an incorrect artifact receipt', function (): void {
    $this->receipt['symbols'][0]['sha256'] = str_repeat('0', 64);
    Http::fake(['*' => Http::response($this->receipt)]);
    $this->artisan('nativewatch:symbols', ['path' => $this->dwarf, '--source-root' => '/nativewatch-fixtures'])
        ->expectsOutputToContain('exact artifact checksum')->assertFailed();
});

it('requires explicit build source paths and CI credentials before sending bytes', function (): void {
    $this->artisan('nativewatch:symbols', ['path' => $this->dwarf])->assertFailed();
    putenv('NATIVEWATCH_RELEASE_TOKEN');
    $this->artisan('nativewatch:symbols', ['path' => $this->dwarf, '--source-root' => '/nativewatch-fixtures'])->assertFailed();
    Http::assertNothingSent();
});

it('finds regular DWARF files and refuses symbolic links inside a bundle', function (): void {
    $path = temporaryOutbox().'/Build.dSYM';
    mkdir($path.'/Contents/Resources/DWARF', 0755, true);
    try {
        copy($this->dwarf, $path.'/Contents/Resources/DWARF/App');
        expect(app(FindDebugSymbols::class)->handle($path))->toBe([$path.'/Contents/Resources/DWARF/App']);
        symlink($this->dwarf, $path.'/Contents/Resources/DWARF/Linked');
        expect(fn (): array => app(FindDebugSymbols::class)->handle($path))->toThrow(RuntimeException::class, 'regular files');
    } finally {
        Illuminate\Support\Facades\File::deleteDirectory(dirname($path));
    }
});
