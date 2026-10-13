# Native Rust backend

Rust is the default computational backend for all three extensions. PHP retains the version-matched Mago SDK adapter, policy/configuration loaders, binding proofs and reporting; Symfony container export still executes in PHP.

The engine is resolved from MAGO_NATIVE_ENGINE, a local native/bin or native/target/release build, then the shared user cache. When missing, the platform-specific release asset is installed once after SHA-256 verification. macOS arm64, macOS x86_64, Linux x86_64 and Linux aarch64/arm64 have CI build/test coverage. Linux aarch64 and arm64 host names both select the `mago-extension-engine-linux-arm64` asset. Linux arm64 builds and complete fixture tests run natively on Ubuntu 22.04 ARM runners against the minimum and latest supported Mago. GNU Linux assets retain their existing names and glibc requirements; the GNU arm64 binary is built on glibc 2.35 (Ubuntu 22.04). Additional `linux-x86_64-musl` and `linux-arm64-musl` assets are statically linked, with no dynamic loader or shared-library dependency. They are built and executed natively on each Linux architecture with minimum/latest compatible Mago, plus PHP adapter/runtime tests in Alpine containers. All extensions reuse the same engine version. Rust compilation is not needed for release binaries.

## Linux libc selection

The installer reads the running PHP process's loaded libraries from `/proc/self/maps` to distinguish glibc from musl without running shell commands. Alpine/musl selects the static `-musl` asset. If libc cannot be identified (for example, restricted procfs or static PHP), the portable static musl asset is selected. GNU and musl engines use separate cache directories; a previously cached GNU executable is not reused on musl. The engine protocol version remains 0.3.0.

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

Source builds use Rust 1.99.0 because the pinned Mago parser dependency declares that requirement. The host SDK continues to support Mago >=1.50,<2.0. Existing RC1/RC2 binaries must be replaced with engine 0.3.0.

## Parsing and diagnostics

The default parser performs no PHP grammar fallback. Unsupported syntax is reported as incomplete/error evidence rather than being treated as a successful empty analysis.

For diagnosis only, MAGO_EXTENSION_BACKEND=php selects the retained reference implementation. tests/native-parity.py compares complete reports under both backends. This reference path is not an automatic production fallback.

MAGO_NATIVE_TRACE=1 writes native recursion call paths to stderr without changing the normal report. Mago captures worker stderr internally; it is not normally shown after a successful worker request. To retain these paths, also set MAGO_NATIVE_TRACE_FILE to an absolute writable regular-file path:

```sh
MAGO_NATIVE_TRACE=1 MAGO_NATIVE_TRACE_FILE=/tmp/mago-native-recursion.jsonl mago analyze
```

The file is append-only and contains `native-recursion-trace: ` followed by a JSON array of fully qualified `class::method` identifiers. It captures Rust stderr (including native errors) and does not alter the binary SDK protocol. The final repeated identifier closes the detected cycle; repeated method names in different classes do not form a cycle. Paths can contain project class names; leave tracing disabled unless diagnosing a finding.

Bounded transport, source caches and project sessions remain command-local. Analyzed source is immutable data and never selects or executes the engine binary.

## Production parity status

Neutral regressions cover typed delegation, first-class callable creation, deferred arrow-function bodies, binding-expression effects and real direct cycles. The reported first-class callable pattern is reproduced and corrected. Full production parity and the three other incomplete differences still require a rerun; fixture parity alone does not establish production parity.

## First-class callables

`$this->mapNode(...)`, `Type::mapNode(...)` and `mapNode(...)` create callables; the analyzer does not execute their target bodies at creation. Receiver and dynamic-name expressions are still evaluated, including their query side effects. Direct recursive calls remain detectable.

Higher-order execution through `array_map` currently has an unknown query bound and remains `query-budget-incomplete`. This is not a proof of infinite tree recursion. First-class callable and arrow-function creation alone must not produce a `recursive-call` issue. The PHP reference evaluator follows the same distinction.
