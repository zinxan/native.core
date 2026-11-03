# Contributing

Use PHP 8.4 or newer and Composer 2. Open an issue before a substantial feature
or event-format change. Include SDK, Laravel and NativePHP versions, a minimal
reproduction and redacted logs. Never publish credentials or customer reports.
Report vulnerabilities privately to a repository maintainer.

Shared capture, redaction and delivery belong in `native-core`. Platform hooks
belong in `native-desktop` or `native-mobile`. Keep changes focused, classes
final, types explicit and files within 300 lines. Reporting must never replace
the original application error.

## Run the checks

```sh
COMPOSER_ROOT_VERSION=0.2.x-dev composer install
composer test
```

The root version identifies the development checkout as part of the 0.2 line.
Composer will resolve a lock file locally; libraries do not commit it. Tests use
Orchestra Testbench and fake HTTP, without the private server, production keys
or a running NativePHP app. `composer test` runs Pint, PHPStan, 100% PHP type coverage and Pest. GitHub
also validates Composer metadata, audits dependencies and runs Pest on PHP 8.4
and 8.5 with Laravel 11, 12 and 13.

For one Laravel version, select its matching Testbench before installing:

```sh
composer require --dev 'orchestra/testbench:^9.0' --no-update
COMPOSER_ROOT_VERSION=0.2.x-dev composer update
composer test
```

Testbench 9 tests Laravel 11; Testbench 10 tests Laravel 12; Testbench 11 tests
Laravel 13. Do not commit a local matrix selection to `composer.json`.

Native reporting changes also require a compiled Release app, a real crash and
relaunch, receiver assertions and screenshots. Identify the platform and
whether evidence comes from a simulator or physical device. PHP CI does not
compile Swift or Kotlin or establish native crash capture.

## Submit a pull request

Explain the failing behavior, the smallest complete fix and the commands you
actually ran. Include a regression test, compatibility changes and remaining
limits. Use a branch and open a pull request against `main`. Merging requires
one approving review from another maintainer, resolved conversations and all
required CI checks passing against the latest `main`. New commits dismiss old
approvals. Force pushes and deletion of `main` are blocked.

Workflow actions are pinned to full commit hashes and updated by Dependabot.
Keep workflow permissions read-only and never expose secrets to forked code or
use `pull_request_target` to execute a contributor's checkout. Published `v*`
tags cannot be rewritten or deleted. Fix a release with a new version.
