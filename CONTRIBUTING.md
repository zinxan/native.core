# Contributing to the NativeWatch SDKs

Use PHP 8.4 or newer and Composer 2. Open an issue in the affected SDK repository
before a substantial feature or a change to the event format. Include the SDK,
Laravel and NativePHP versions, a minimal reproduction and redacted logs.

Shared capture, redaction and delivery belong in `native-core`. Desktop and
mobile integrations belong in their platform packages. Keep changes focused,
classes final, types explicit and files within 300 lines. Preserve the original
application error when reporting fails. Never include credentials or customer
crash reports in an issue, test fixture or pull request.

Validate a package checkout with:

```sh
composer validate --strict
composer install
```

The SDK regression tests run with the server's integration suite in the
maintainers' checkout. A standalone `composer install` does not run those tests.
Include a regression test or reproduction with a fix; maintainers run the full
suite before merging. Changes to native reporting also need a compiled Release
app, a real crash and relaunch, server-event assertions and screenshots. Identify
the platform and distinguish simulator evidence from a physical device.

In a pull request, explain the failing behavior, the smallest complete fix and
the commands you actually ran. Include compatibility changes and any remaining
limits. Report suspected vulnerabilities privately to a repository maintainer
instead of opening an issue containing exploit details or private data.
