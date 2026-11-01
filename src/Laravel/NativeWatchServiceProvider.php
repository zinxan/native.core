<?php

declare(strict_types=1);

namespace ZXN\NativeWatch\Core\Laravel;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Factory;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\View\Compilers\BladeCompiler;
use Throwable;
use ZXN\NativeWatch\Core\Client;
use ZXN\NativeWatch\Core\Config;
use ZXN\NativeWatch\Core\Contracts\ContextProvider;
use ZXN\NativeWatch\Core\Contracts\ReportingConsent;
use ZXN\NativeWatch\Core\EventFactory;
use ZXN\NativeWatch\Core\FileConsent;
use ZXN\NativeWatch\Core\Laravel\Commands\FlushCommand;
use ZXN\NativeWatch\Core\Laravel\Commands\ReleaseCommand;
use ZXN\NativeWatch\Core\Laravel\Commands\SymbolsCommand;
use ZXN\NativeWatch\Core\Laravel\Commands\TestCommand;
use ZXN\NativeWatch\Core\Laravel\Watchers\Watcher;
use ZXN\NativeWatch\Core\Level;
use ZXN\NativeWatch\Core\Outbox;
use ZXN\NativeWatch\Core\Scope;
use ZXN\NativeWatch\Core\Transport\HttpTransport;
use ZXN\NativeWatch\Core\Transport\RetryAfter;

abstract class NativeWatchServiceProvider extends ServiceProvider
{
    /**
     * Get the name of the SDK, as it shows on every event.
     */
    abstract protected function sdkName(): string;

    /**
     * Get the context provider of the platform.
     */
    abstract protected function contextProvider(Application $app): ContextProvider;

    /**
     * Register any application services.
     */
    final public function register(): void
    {
        $this->mergeConfigFrom(self::configPath(), 'nativewatch');

        $this->app->singleton(Config::class, fn (Application $app): Config => Config::fromArray(
            (array) $app->make('config')->get('nativewatch', []),
            $app->basePath(),
        ));

        $this->app->singleton(ContextProvider::class, fn (Application $app): ContextProvider => $this->contextProvider($app));

        $this->app->singleton(Client::class, function (Application $app): Client {
            $config = $app->make(Config::class);
            $repository = $app->make('config');
            $outboxPath = (string) ($repository->get('nativewatch.outbox.path') ?? $app->storagePath('nativewatch/outbox'));

            return new Client(
                $config,
                new EventFactory($config, $app->make(ContextProvider::class), $this->sdkName()),
                new HttpTransport($app->make(Factory::class), $config, new RetryAfter($outboxPath.'/.retry-after')),
                new Outbox(
                    $outboxPath,
                    (int) $repository->get('nativewatch.outbox.max_events', 100),
                ),
                new Scope($config->maxBreadcrumbs),
                consent: $this->reportingConsent($app),
            );
        });
    }

    /**
     * Bootstrap any application services.
     */
    final public function boot(): void
    {
        $this->publishes([self::configPath() => $this->app->configPath('nativewatch.php')], 'nativewatch-config');
        $this->loadViewsFrom(dirname(__DIR__, 2).'/resources/views', 'nativewatch');

        if ($this->app->runningInConsole()) {
            $this->commands([TestCommand::class, FlushCommand::class, ReleaseCommand::class, SymbolsCommand::class]);
        }

        if (! $this->app->make(Config::class)->isEnabled()) {
            return;
        }

        $this->reportExceptions();
        $this->recordLogs();
        $this->resetScopeForEachJob();
        $this->reportBrowserErrors();
        $this->registerWatchers();
        $this->flushAfterWork();
        $this->bootPlatform($this->app->make(Client::class));
    }

    /**
     * Hook the client into the lifecycle of the platform.
     */
    protected function bootPlatform(Client $client): void {}

    protected function reportingConsent(Application $app): ?ReportingConsent
    {
        return $app->make('config')->get('nativewatch.require_consent', false)
            ? new FileConsent((string) ($app->make('config')->get('nativewatch.consent.path') ?? $app->storagePath('nativewatch/consent.json')))
            : null;
    }

    /**
     * Get the client.
     */
    protected function client(): Client
    {
        return $this->app->make(Client::class);
    }

    private static function configPath(): string
    {
        return dirname(__DIR__, 2).'/config/nativewatch.php';
    }

    /**
     * Hook into the exception handler, also when a wrapper such as Collision replaces it.
     */
    private function reportExceptions(): void
    {
        $hooked = false;

        $this->callAfterResolving(ExceptionHandler::class, function (ExceptionHandler $handler) use (&$hooked): void {
            if ($hooked || ! method_exists($handler, 'reportable')) {
                return;
            }

            $handler->reportable(function (Throwable $exception): void {
                $this->client()->captureException($exception);
            });

            $hooked = true;
        });
    }

    /**
     * Keep each log line as a breadcrumb, and report an exception that the app only logs.
     * The client drops the second report of an exception that the handler reported too.
     */
    private function recordLogs(): void
    {
        $config = $this->app->make('config');
        $breadcrumbs = (bool) $config->get('nativewatch.breadcrumbs.logs', true);
        $exceptions = (bool) $config->get('nativewatch.logs.capture_exceptions', true);

        Event::listen(MessageLogged::class, function (MessageLogged $log) use ($breadcrumbs, $exceptions): void {
            if ($exceptions && ($log->context['exception'] ?? null) instanceof Throwable) {
                $this->client()->captureException($log->context['exception']);
            }

            if ($breadcrumbs) {
                $this->client()->addBreadcrumb('log', $log->message, Level::fromLogLevel($log->level));
            }
        });
    }

    /**
     * A queue worker runs many jobs in one process. Start each with an empty scope, so the
     * user and breadcrumbs of one job never show on the error of the next. A job on the
     * sync connection runs inside the request, so it keeps the scope of the request.
     */
    private function resetScopeForEachJob(): void
    {
        Event::listen(JobProcessing::class, function (JobProcessing $event): void {
            if ($event->connectionName !== 'sync') {
                $this->client()->scope->clear();
            }
        });
    }

    /**
     * Receive the JavaScript errors of the web view. @nativewatchScripts reports them.
     */
    private function reportBrowserErrors(): void
    {
        $config = $this->app->make('config');

        if (! $config->get('nativewatch.browser.enabled', true)) {
            return;
        }

        $path = (string) $config->get('nativewatch.browser.path', '_nativewatch/browser-errors');

        Route::post($path, BrowserErrorController::class)
            ->middleware('throttle:60,1')
            ->name('nativewatch.browser-errors');

        $this->callAfterResolving('blade.compiler', function (BladeCompiler $blade): void {
            // Blade caches what a directive returns, so the view reads the config when it renders.
            $blade->directive('nativewatchScripts', fn (): string => "<?php echo view('nativewatch::scripts')->render(); ?>");
        });
    }

    /**
     * Register every watcher in the Watchers directory, plus the custom ones of the app.
     * Turn one off with "nativewatch.activity.<signal>.enabled" set to false.
     */
    private function registerWatchers(): void
    {
        $config = $this->app->make('config');
        $custom = $config->get('nativewatch.activity.watchers', []);
        $classes = array_map(
            fn (string $file): string => __NAMESPACE__.'\\Watchers\\'.basename($file, '.php'),
            glob(__DIR__.'/Watchers/*Watcher.php') ?: [],
        );

        foreach ([...$classes, ...(is_array($custom) ? $custom : [])] as $class) {
            if (! is_string($class) || $class === Watcher::class || ! is_a($class, Watcher::class, true)) {
                continue;
            }

            $watcher = $this->app->make($class);
            $options = $config->get('nativewatch.activity.'.$watcher->signal(), []);
            $options = is_array($options) ? $options : [];

            if ($options['enabled'] ?? true) {
                $watcher->register($this->app, $this->client()->activities, $options);
            }
        }
    }

    private function flushAfterWork(): void
    {
        $flush = function (): void {
            $this->client()->storeActivities();
            $this->client()->flush((int) $this->app->make('config')->get('nativewatch.outbox.flush_limit', 10));
        };

        $this->app->terminating($flush);

        Event::listen([JobProcessed::class, JobFailed::class], $flush);
    }
}
