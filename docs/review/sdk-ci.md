# Standalone SDK CI evidence

Verified on 3 November 2026 with PHP 8.4.27 and Laravel 13.

- `composer validate --strict`: passed.
- `composer audit`: no advisories or abandoned dependencies.
- `composer test`: Pint and PHPStan level 7 passed, 100% PHP type coverage,
  110 Pest tests and 542 assertions passed.
- `git diff --check`: passed.

The workflow repeats Pest across PHP 8.4/8.5 and Laravel 11/12/13. Its Quality
job validates Composer, audits dependencies and runs lint, static analysis and
type coverage. Every job runs for every pull request, without path filters.
Both Actions are pinned to full commit hashes; checkout credentials are not
persisted, the token is read-only and the workflow uses no repository secrets.

The public suite runs against Orchestra Testbench rather than the private
NativeWatch server. Server acceptance and end-to-end native crash behavior
still require their respective integration checks. This CI does not compile
or execute Swift, Kotlin, an iOS simulator or an Android device.

The first standalone run exposed 15 failures caused by private-server fixture,
model, schema and path dependencies. Those are replaced by a package-local
compiled Swift fixture, a notifiable Laravel model and package-relative paths.
The long-message test asserts the 8,192-character limit and valid UTF-8;
validation against the server's complete FormRequest stays in its integration
suite. Existing capture, consent, retry, watcher, release and symbol-upload
regressions run without a production endpoint.

PHPStan also found redundant type guards around scalar console inputs whose
Laravel command signatures already enforce their type. Only those guards were
removed; optional source-root and CI credential validation remain in place.
