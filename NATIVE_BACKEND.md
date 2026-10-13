# Native Rust backend

Rust is the default computational backend for all three extensions. PHP retains the version-matched Mago SDK adapter, policy/configuration loaders, binding proofs and reporting; Symfony container export still executes in PHP.

The engine is resolved from MAGO_NATIVE_ENGINE, a local native/bin or native/target/release build, then the shared user cache. When missing, the platform-specific release asset is installed once after SHA-256 verification. macOS arm64, macOS x86_64 and Linux x86_64 have CI build/test coverage. All extensions reuse the same engine version. Rust compilation is not needed for release binaries.

## Installation and offline use

The first analysis installs the engine. To preinstall explicitly:

```sh
php vendor/byte-kitsune/mago-architecture-graph/bin/install-native.php
```

Equivalent installers are provided by the other packages. Set XDG_CACHE_HOME to choose the shared cache. Set MAGO_NATIVE_DOWNLOAD=0 to disable downloads after preinstallation. An unavailable or incompatible engine produces an error; it never selects the PHP backend automatically.

For a source build:

```sh
cargo +1.99.0 build --release --locked --manifest-path native/Cargo.toml
export MAGO_NATIVE_ENGINE=/absolute/path/to/native/target/release/mago-extension-engine
mago analyze
```

Source builds use Rust 1.99.0 because the pinned Mago parser dependency declares that requirement. The host SDK continues to support Mago >=1.50,<2.0. Existing RC1 binaries must be replaced with engine 0.2.0.

## Parsing and diagnostics

The default parser performs no PHP grammar fallback. Unsupported syntax is reported as incomplete/error evidence rather than being treated as a successful empty analysis.

For diagnosis only, MAGO_EXTENSION_BACKEND=php selects the retained reference implementation. tests/native-parity.py compares complete reports under both backends. This reference path is not an automatic production fallback.

MAGO_NATIVE_TRACE=1 writes native recursion call paths to stderr without changing the normal report. Paths can contain project class names; leave tracing disabled unless diagnosing a finding.

Bounded transport, source caches and project sessions remain command-local. Analyzed source is immutable data and never selects or executes the engine binary.

## Production parity status

Neutral delegation regressions cover promoted and assigned service receivers, same-named endpoint methods, direct cycles and constructor bindings. The externally reported additional recursive-call and incomplete findings still need their anonymized source and evidence to reproduce; passing fixture parity does not establish parity for those project-specific cases.
