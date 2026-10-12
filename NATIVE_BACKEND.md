# Shared native engine for the Mago extensions

The extension hosts continue to use Mago's version-matched PHP SDK. Their computational backend is a persistent Rust process. This preserves the Mago >=1.50,<2.0 integration without maintaining another implementation of the MANA protocol.

Implemented native paths:

- Architecture Graph: Mago parsing/name resolution, literal class-reference collection, syntax conversion, batched lazy BFS traversal with predecessor links, and iterative SCC cycle detection.
- Doctrine Query Budget: source parsing, the project method/function/trait index, receiver/constructor bindings, conservative control-flow and call evaluation, recursion/work limits, and saturated estimate arithmetic. Project sessions isolate binding variants. The PHP adapter caches reporting metadata by immutable analysis generation and binding configuration.
- Symfony Wiring: constructor/attribute extraction shared between wiring checks and semantic-reference generation. Concrete target lookups are batched through the SDK.

Configuration loading/validation, reporting, selected graph binding proofs and Symfony's actual container export remain PHP. This is a native computational backend, not a replacement of every PHP source file or a dynamic library loaded by Mago.

## Build and use

```sh
cargo build --release --locked
export MAGO_NATIVE_ENGINE=/absolute/path/to/target/release/mago-extension-engine
mago analyze
```

Use the adapters prepared for all three extension repositories. Their PHP SDK dependencies still allow `^1.50`. `MAGO_NATIVE_ENGINE` must point to a trusted executable. The analyzed source is passed as immutable data; the engine never opens source paths or executes PHP.

`MAGO_NATIVE_STRICT=1` disables the compatibility fallback for unsupported native syntax. Production fallback retains the existing PHP parser for grammar not mapped into the native interchange yet; query evaluation still runs in Rust. Parser counters distinguish native and fallback parses. Operator configuration/security inspection continues to use the established PHP implementation.

Transport/source caches are bounded. Native failures produce explicit errors; a broken transport is never treated as a successful empty report. No source is cached across engine processes or commands.

## Validation

Run Rust unit tests and Clippy, then the complete extension suites under native strict mode on Mago 1.50.0 and 1.56.3. `tests/parity.py` compares complete real-Mago fixture reports against the PHP backend. Query Budget includes the 20,000-source benchmark, the 25,000-source rejection and snapshot-file failure isolation.

The local build and tests currently target Linux x86_64/PHP 8.5. Cross-platform packaging and native-mode CI/release assets must be completed before a public release; no released extension has been silently switched to this backend.
