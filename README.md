# zinxan/native-core

The shared NativeWatch client for error capture, privacy controls, breadcrumbs,
bounded offline delivery and release evidence.

For a NativePHP app, install the integration for its platform. Composer installs
this shared package automatically:

```sh
composer require zinxan/native-desktop
# Or, for iOS and Android:
composer require zinxan/native-mobile
```

Both integrations report to [NativeWatch](https://native.watch). Configure your
project's ingest key in the app, and obtain diagnostic consent as described in
the [privacy guide](https://native.watch/docs/privacy-and-consent).

- [Desktop installation](https://native.watch/docs/install-desktop)
- [Mobile installation and native plugin registration](https://native.watch/docs/install-mobile)
- [Offline delivery and retry behavior](https://native.watch/docs/offline-delivery)
- [Release and commit tracking](https://native.watch/docs/releases-and-commits)
- [iOS crash symbolication](https://native.watch/docs/ios-symbolication)

Requires PHP 8.4 or newer and Illuminate 11, 12 or 13. Licensed under MIT.

To report uncaught errors and unhandled promise rejections from a web view, add
`@nativewatchScripts` to its Blade layout. If your Content Security Policy uses
a nonce, pass the nonce generated for that response:

```blade
@nativewatchScripts(Vite::cspNonce())
```

The SDK escapes the nonce and posts browser reports to the app's local server.
Normal consent, redaction and delivery settings still apply.

Read the [SDK contribution guide](CONTRIBUTING.md) before opening a pull request.
