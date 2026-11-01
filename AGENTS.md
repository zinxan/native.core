# zinxan/native-core

`native-core` is the client that `native-desktop` and `native-mobile` share. It builds events, keeps breadcrumbs, scrubs secrets, stores events in an outbox on disk, and sends them to NativeWatch. The platform packages only add context and hook into their runtime. Each feature that both platforms need belongs here.

An SDK runs inside someone else's app. It must never break that app, never slow it down, and never send what the user did not agree to send.

---

## 1. Public API

These are public. Treat each change as a breaking change for every app that installed the SDK:

- `Client`: `captureException()`, `captureMessage()`, `captureEvent()`, `captureEventResult()`, `addBreadcrumb()`, `beforeSend()`, `flush()`, `pending()`, `isEnabled()` and the `scope` property. `captureEvent()` completes an event built elsewhere, such as a native crash report, and leaves out the scope. `captureEventResult()` also tells a native collector whether capture failed and should be retried; deliberate discards must never be retried.
- The facade `ZXN\NativeWatch\Core\Laravel\NativeWatch`.
- The keys of `config/nativewatch.php`.
- `Contracts\ContextProvider` and `Level`.
- The artisan commands `nativewatch:test` and `nativewatch:flush`.
- `flushReport()` returns accepted/rejected/unreadable/failed/remaining counts.
  `deliveryResult($eventId)` returns the most recent transport result for that
  event in this process (at most 64 receipts). `flush()` still counts removals,
  which include permanent rejections and unreadable files, not just acceptance.
- `setConsent()`, `requiresConsent()` and `hasConsent()` control persisted consent.

---

## 2. Rules

- Wrap each public method of `Client` that does work in `Client::safely()`. An error of the SDK returns `null`. It never reaches the app.
- Store an event in the `Outbox` when you capture it. Send it after the response: `NativeWatchServiceProvider` flushes on `terminating` and after each queued job. Never send during a request, unless the outbox cannot be written.
- Keep `SendResult` honest: `Rejected` for a permanent `4xx` (the event leaves the outbox), `Failed` for a network error, a `5xx`, or a throttled `429` with `Retry-After` (the event stays). Persist the retry deadline beside the outbox so another process respects it too.
- Scrub each event with `Scrubber` before it leaves the device. Send personal data, such as a device name, only when `send_default_pii` is `true`.
- Hook into the exception handler through `reportable()`, once. Collision and other wrappers replace the handler in the console. `NativeWatchServiceProvider::reportExceptions()` covers them, so keep its `method_exists()` check and its `$hooked` flag.
- Support PHP `^8.4` and Laravel 11, 12 and 13. Do not use an API that only Laravel 13 has, such as the console command attributes.
- Add no dependency beyond `illuminate/*`.
- Report what Laravel's handler never sees: an exception that the app only logs (`Log::error(..., ['exception' => $e])`, deduplicated with `report()`), and the JavaScript errors of the web view through `@nativewatchScripts` and `BrowserErrorController`. Post browser errors to a relative URL: NativePHP mobile serves pages from `php://127.0.0.1`, so an absolute `http://` URL is cross-origin and the web view blocks it.
- Start each job of a queue worker with an empty scope. A `sync` job runs inside the request and keeps its scope.
- Keep paths stable between builds and installs. Map a compiled Blade view to its template (`StackFrames::templateOf()`), move the "(View: …)" suffix of a message to the context, and start a path in storage at `storage/`: on iOS, storage sits outside the base path in a container that changes with each install.
- Send source code only for the throw site and the frames of the app, at most `StackFrames::MAX_FRAMES_WITH_CODE` frames, and only `source_code_lines` lines around the line. The server reads `frames.*.code` as `{start, lines}`.

---

## 3. Changes that touch more than this package

- To add a config key, change `config/nativewatch.php`, `Config::fromArray()`, and a test in `tests/ClientTest.php`.
- To change the event shape, read section 3 of the root `AGENTS.md`. The server validates each field.
- To change a hook of the runtime, change `NativeWatchServiceProvider` here. Override `bootPlatform()` in a platform package only for a hook that one platform has.
- Increase `EventFactory::SDK_VERSION` when you release.

---

## 4. Tests

- Put the tests in `packages/native-core/tests/`. Run them with `php artisan test --compact --testsuite=Packages` from the root.
- Build a client with the `client()` helper in `tests/Fixtures/client.php`. Give each test its own outbox with `temporaryOutbox()`.
- Call `Http::preventStrayRequests()` and fake the server with `Http::fake()`.
