<?php

declare(strict_types=1);

use Illuminate\Process\Factory;
use Illuminate\Support\Facades\Http;
use ZXN\NativeWatch\Core\Releases\BuildReleaseManifest;
use ZXN\NativeWatch\Desktop\DesktopServiceProvider;

afterEach(function (): void {
    Illuminate\Support\Facades\File::deleteDirectory($this->repositoryPath);
});

beforeEach(function (): void {
    Http::preventStrayRequests();
    $this->repositoryPath = temporaryOutbox();
    $this->git = function (array $arguments): string {
        $result = app(Factory::class)->newPendingProcess()->path($this->repositoryPath)->run(['git', ...$arguments]);
        expect($result->successful(), $result->errorOutput())->toBeTrue();

        return mb_trim($result->output());
    };
    ($this->git)(['init', '-q']);
    ($this->git)(['config', 'user.name', 'NativeWatch Test']);
    ($this->git)(['config', 'user.email', 'test@example.test']);
    mkdir($this->repositoryPath.'/app');
    file_put_contents($this->repositoryPath.'/app/Sync.php', "<?php\n// unchanged\nreturn 1;\n");
    ($this->git)(['add', '.']);
    ($this->git)(['commit', '-qm', 'Initial source']);
    $this->previousCommit = ($this->git)(['rev-parse', 'HEAD']);
    file_put_contents($this->repositoryPath.'/app/Sync.php', "<?php\n// unchanged\nreturn 2;\n");
    file_put_contents($this->repositoryPath.'/app/Space ü.php', "<?php\nreturn 3;\n");
    ($this->git)(['add', '.']);
    ($this->git)(['commit', '-qm', 'Change sync']);
    $this->releaseCommit = ($this->git)(['rev-parse', 'HEAD']);
});

it('derives exact release line ranges from real git history without publishing source or authors', function (): void {
    $manifest = app(BuildReleaseManifest::class)->handle($this->repositoryPath, '1.0.0', $this->previousCommit, 'zinxan/native.watch');
    expect($manifest['commit'])->toBe($this->releaseCommit)
        ->and($manifest['commits'])->toBe([$this->releaseCommit])
        ->and($manifest['files'])->toContain(['path' => 'app/Sync.php', 'ranges' => [['start' => 3, 'end' => 3, 'commit' => $this->releaseCommit]]])
        ->and(json_encode($manifest))->not->toContain('test@example.test', 'return 2;', 'Change sync');
});

it('rejects dirty builds and unavailable release history', function (): void {
    file_put_contents($this->repositoryPath.'/app/Sync.php', 'dirty');
    expect(fn () => app(BuildReleaseManifest::class)->handle($this->repositoryPath, '1.0.0', $this->previousCommit, 'zinxan/native.watch'))->toThrow(RuntimeException::class, 'clean');
    ($this->git)(['checkout', '--', '.']);
    expect(fn () => app(BuildReleaseManifest::class)->handle($this->repositoryPath, '1.0.0', str_repeat('0', 40), 'zinxan/native.watch'))->toThrow(RuntimeException::class, 'Git');
});

it('publishes evidence with the CI token and prints the exact build ID', function (): void {
    config(['nativewatch.url' => 'http://zxguard.test']);
    Http::fake(['zxguard.test/api/v1/releases' => Http::response(['buildId' => '1.0.0@'.$this->releaseCommit], 201)]);
    putenv('NATIVEWATCH_RELEASE_TOKEN=nwr_ci-only-secret');
    $this->app->register(DesktopServiceProvider::class);
    try {
        $this->artisan('nativewatch:release', ['version' => '1.0.0', '--previous' => $this->previousCommit, '--repository' => 'zinxan/native.watch', '--path' => $this->repositoryPath])
            ->expectsOutputToContain('1.0.0@'.$this->releaseCommit)->assertSuccessful();
        Http::assertSent(fn (Illuminate\Http\Client\Request $request): bool => $request->hasHeader('Authorization', 'Bearer nwr_ci-only-secret') && ! $request->hasHeader('X-NativeWatch-Key') && $request['commit'] === $this->releaseCommit);
    } finally {
        putenv('NATIVEWATCH_RELEASE_TOKEN');
    }
});

it('ignores local blame ignore files when deriving immutable release evidence', function (): void {
    $ignored = $this->repositoryPath.'/.git/ignored-revisions';
    file_put_contents($ignored, $this->releaseCommit."\n");
    ($this->git)(['config', 'blame.ignoreRevsFile', $ignored]);
    $manifest = app(BuildReleaseManifest::class)->handle($this->repositoryPath, '1.0.0', $this->previousCommit, 'zinxan/native.watch');
    expect($manifest['files'])->toContain(['path' => 'app/Sync.php', 'ranges' => [['start' => 3, 'end' => 3, 'commit' => $this->releaseCommit]]]);
});

it('uses repository-relative paths for an app inside a monorepo', function (): void {
    mkdir($this->repositoryPath.'/apps/mobile/app', 0755, true);
    file_put_contents($this->repositoryPath.'/apps/mobile/app/Sync.php', "<?php\nreturn 4;\n");
    ($this->git)(['add', '.']);
    ($this->git)(['commit', '-qm', 'Add a mobile app']);
    $manifest = app(BuildReleaseManifest::class)->handle($this->repositoryPath.'/apps/mobile', '1.0.0', $this->previousCommit, 'zinxan/native.watch');
    expect($manifest['source_root'])->toBe('apps/mobile')->and($manifest['files'])->toHaveCount(1)->and($manifest['files'][0]['path'])->toBe('apps/mobile/app/Sync.php');
});

it('keeps renames and reverts tied to the actual build line', function (): void {
    ($this->git)(['mv', 'app/Sync.php', 'app/Renamed Sync.php']);
    file_put_contents($this->repositoryPath.'/app/Renamed Sync.php', "<?php\n// unchanged\nreturn 1;\n");
    ($this->git)(['add', '.']);
    ($this->git)(['commit', '-qm', 'Revert sync in renamed file']);
    $commit = ($this->git)(['rev-parse', 'HEAD']);
    $manifest = app(BuildReleaseManifest::class)->handle($this->repositoryPath, '1.0.0', $this->previousCommit, 'zinxan/native.watch');
    expect($manifest['files'])->toContain(['path' => 'app/Renamed Sync.php', 'ranges' => [['start' => 3, 'end' => 3, 'commit' => $commit]]]);
});

it('prints the build ID without a publishing token or HTTP request', function (): void {
    $this->app->register(DesktopServiceProvider::class);
    $this->artisan('nativewatch:release', ['version' => '1.0.0', '--path' => $this->repositoryPath, '--id-only' => true])
        ->expectsOutput('1.0.0@'.$this->releaseCommit)->assertSuccessful();
    Http::assertNothingSent();
});

it('reports publication errors without echoing server response contents', function (int $status): void {
    config(['nativewatch.url' => 'http://zxguard.test']);
    Http::fake(['zxguard.test/api/v1/releases' => Http::response('secret-server-response', $status)]);
    putenv('NATIVEWATCH_RELEASE_TOKEN=nwr_ci-only-secret');
    $this->app->register(DesktopServiceProvider::class);
    try {
        Illuminate\Support\Facades\Artisan::call('nativewatch:release', ['version' => '1.0.0', '--previous' => $this->previousCommit, '--repository' => 'zinxan/native.watch', '--path' => $this->repositoryPath]);
        expect(Illuminate\Support\Facades\Artisan::output())->toContain('HTTP '.$status)->not->toContain('secret-server-response', 'nwr_ci-only-secret');
    } finally {
        putenv('NATIVEWATCH_RELEASE_TOKEN');
    }
})->with([401, 409, 422, 429, 500]);

it('rejects shallow CI checkouts instead of publishing incomplete history', function (): void {
    $clone = temporaryOutbox();
    try {
        $result = app(Factory::class)->newPendingProcess()->run(['git', 'clone', '--depth', '1', 'file://'.$this->repositoryPath, $clone]);
        expect($result->successful())->toBeTrue();
        expect(fn () => app(BuildReleaseManifest::class)->handle($clone, '1.0.0', $this->previousCommit, 'zinxan/native.watch'))->toThrow(RuntimeException::class, 'complete Git history');
    } finally {
        Illuminate\Support\Facades\File::deleteDirectory($clone);
    }
});

it('reports connection failures without reporting a successful publication', function (): void {
    config(['nativewatch.url' => 'http://zxguard.test']);
    Http::fake(['zxguard.test/api/v1/releases' => Http::failedConnection()]);
    putenv('NATIVEWATCH_RELEASE_TOKEN=nwr_ci-only-secret');
    $this->app->register(DesktopServiceProvider::class);
    try {
        $this->artisan('nativewatch:release', ['version' => '1.0.0', '--previous' => $this->previousCommit, '--repository' => 'zinxan/native.watch', '--path' => $this->repositoryPath])
            ->expectsOutputToContain('could not be reached')->assertFailed();
    } finally {
        putenv('NATIVEWATCH_RELEASE_TOKEN');
    }
});

it('requires the server to confirm the exact published build', function (): void {
    config(['nativewatch.url' => 'http://zxguard.test']);
    Http::fake(['zxguard.test/api/v1/releases' => Http::response(['buildId' => 'another-build'], 200)]);
    putenv('NATIVEWATCH_RELEASE_TOKEN=nwr_ci-only-secret');
    $this->app->register(DesktopServiceProvider::class);
    try {
        $this->artisan('nativewatch:release', ['version' => '1.0.0', '--previous' => $this->previousCommit, '--repository' => 'zinxan/native.watch', '--path' => $this->repositoryPath])
            ->expectsOutputToContain('did not confirm this exact build')->assertFailed();
    } finally {
        putenv('NATIVEWATCH_RELEASE_TOKEN');
    }
});
