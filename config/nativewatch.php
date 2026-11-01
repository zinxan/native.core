<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Ingest Key
    |--------------------------------------------------------------------------
    |
    | The key of the NativeWatch project that receives the errors of this app.
    | Errors are not reported while the key is empty.
    |
    */

    'key' => env('NATIVEWATCH_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Server URL
    |--------------------------------------------------------------------------
    */

    'url' => env('NATIVEWATCH_URL', 'https://native.watch'),

    /*
    |--------------------------------------------------------------------------
    | Enabled
    |--------------------------------------------------------------------------
    */

    'enabled' => (bool) env('NATIVEWATCH_ENABLED', true),

    'require_consent' => (bool) env('NATIVEWATCH_REQUIRE_CONSENT', false),

    'consent' => ['path' => null],

    /*
    |--------------------------------------------------------------------------
    | Release And Environment
    |--------------------------------------------------------------------------
    |
    | When the release is empty, the SDK uses the version of the NativePHP app.
    |
    */

    'release' => env('NATIVEWATCH_RELEASE'),

    'environment' => env('NATIVEWATCH_ENVIRONMENT', env('APP_ENV', 'production')),

    /*
    |--------------------------------------------------------------------------
    | Sampling
    |--------------------------------------------------------------------------
    |
    | The share of events that is sent, from 0.0 to 1.0.
    |
    */

    'sample_rate' => (float) env('NATIVEWATCH_SAMPLE_RATE', 1.0),

    /*
    |--------------------------------------------------------------------------
    | Breadcrumbs
    |--------------------------------------------------------------------------
    */

    'max_breadcrumbs' => 30,

    'breadcrumbs' => [
        'logs' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Logged exceptions
    |--------------------------------------------------------------------------
    |
    | Report an exception that the app catches and only logs, such as
    | Log::error('Sync failed', ['exception' => $e]). An exception that
    | is also reported is sent once.
    |
    */

    'logs' => [
        'capture_exceptions' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Errors of the web view
    |--------------------------------------------------------------------------
    |
    | Add @nativewatchScripts to the <head> of your layout. It reports the
    | JavaScript errors and unhandled promise rejections of the window to
    | the app, which sends them with the key that never leaves PHP.
    |
    */

    'browser' => [
        'enabled' => true,
        'path' => '_nativewatch/browser-errors',
    ],

    /*
    |--------------------------------------------------------------------------
    | Privacy
    |--------------------------------------------------------------------------
    |
    | Values of keys that contain one of these words are replaced before an
    | event leaves the device. Personal data such as device names and IDs is
    | only sent when "send_default_pii" is true.
    |
    */

    'scrub' => ['password', 'secret', 'token', 'authorization', 'cookie', 'api_key', 'credit_card'],

    'send_default_pii' => false,

    /*
    |--------------------------------------------------------------------------
    | Source Code
    |--------------------------------------------------------------------------
    |
    | The number of lines around the error line that are sent for the frames
    | of your app, so NativeWatch can show the code. Set to 0 to send no code.
    |
    */

    'source_code_lines' => 5,

    /*
    |--------------------------------------------------------------------------
    | Activity
    |--------------------------------------------------------------------------
    |
    | Watchers record what the app does, such as jobs, queries and mail, and
    | send it to the activity pages of NativeWatch. Each entry counts as an event.
    | Turn a signal off with, for example, 'jobs' => ['enabled' => false].
    | Add your own watchers (classes that implement Watcher) to "watchers".
    |
    */

    'activity' => [
        'watchers' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Delivery
    |--------------------------------------------------------------------------
    |
    | Events are written to the outbox first and sent after the response, so
    | reporting never slows the app down and survives being offline.
    |
    */

    'timeout' => 3.0,

    'outbox' => [
        'path' => null,
        'max_events' => 100,
        'flush_limit' => 10,
    ],

];
