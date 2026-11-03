# Compiled Swift symbol fixture

`CrashFixture.dwarf` is real optimized Mach-O debug information built from
`CrashFixture.swift`, using Xcode 27.1. It is not a fabricated symbol response.
The tests run the installed LLVM symbolizer and official Swift demangler against
its bytes on macOS and Linux CI.

Recorded identity: `bf2dbb4a-b545-3baa-a8a7-8b40b5ea12c5`, arm64,
preferred image base `0x100000000`. Offset `0x714` resolves to
`nativeWatchFixtureFailure`, source line 4. A return address of `0x718` resolves
to the same instruction after the architecture adjustment.

To rebuild from the repository root on an Apple Silicon Mac:

```sh
xcrun swiftc -c -g -O -module-name NativeWatchSymbolFixture \
  -debug-prefix-map "$PWD=/nativewatch-fixtures" \
  tests/Fixtures/Symbols/CrashFixture.swift -o /tmp/nativewatch-symbol-fixture.o
xcrun swiftc -g /tmp/nativewatch-symbol-fixture.o -o /tmp/nativewatch-symbol-fixture
xcrun dsymutil /tmp/nativewatch-symbol-fixture -o /tmp/nativewatch-symbol-fixture.dSYM
cp /tmp/nativewatch-symbol-fixture.dSYM/Contents/Resources/DWARF/nativewatch-symbol-fixture \
  tests/Fixtures/Symbols/CrashFixture.dwarf
xcrun dwarfdump --uuid tests/Fixtures/Symbols/CrashFixture.dwarf
```

A rebuild can change UUIDs and offsets; update the corresponding assertions
from actual tool output. Prefix remapping keeps developer home paths out of
this fixture. This fixture proves symbol resolution, not crash capture.
