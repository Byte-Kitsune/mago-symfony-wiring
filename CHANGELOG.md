# Changelog

## 1.2.0-RC3 — pending validation

- Fix false query-budget recursion and query counts caused by executing first-class callable targets during binding. Preserve binding-expression effects, actual recursive-call detection and incomplete higher-order bounds.
- Use engine 0.3.0 and its separate cache so the corrected evaluator is selected; synchronize the shared kernel across all three extensions.

- Add an operator-selected native trace file so recursion paths survive Mago worker stderr capture; preserve the shared engine adapter across all three extensions.

## 1.2.0-RC2 — pending validation

- Make Rust the default computational backend; require a compatible engine and remove automatic PHP grammar fallback.
- Install verified platform assets into one shared cache and provide offline/preinstall controls.
- Test Linux and both macOS architectures, default selection and complete reference-report parity.
- Add opt-in recursion traces and neutral delegation regressions; project-specific parity investigation remains pending.

## 1.2.0-RC1 — 2026-10-12

- Add an opt-in shared Rust computational backend selected by `MAGO_NATIVE_ENGINE`, retaining the version-matched PHP SDK adapter.
- Verify native strict mode and complete fixture-report parity in CI against the minimum and latest supported Mago versions.
- See `NATIVE_BACKEND.md` for migrated components, bounded transport, compatibility fallback and platform status.

## 1.1.2 — 2026-10-12

- Allow Mago >=1.50.0,<2.0.0 instead of restricting dependencies to the 1.50 minor series.
- Use a major-version pin in executable Mago configurations and test the supported API floor plus latest compatible 1.x in CI.

## 1.1.1

- Accept sanitized compiled-container references up to 64 MiB in the exporter and loader, matching the existing raw-view input limit for large Symfony applications.
- Include the exporter's trailing newline in its output-size budget and distinguish empty/unreadable references from oversized ones.
- Preserve schema validation, service/alias counts, constructor-argument limits and linked-path rejection.

## 1.1.0

- Add an opt-in independent configuration security companion with native PHP lint rule `byte-kitsune/symfony-wiring/no-hardcoded-secret`.
- Permit complete Symfony environment placeholders, including processor parameters and null fallbacks, while retaining findings for literal credentials and mixed/malformed placeholders.
- Expose bounded, non-executing PHP/YAML source inspection with exact byte positions and explicit incomplete results for unsupported constructs.
- Add conservative static companion-registration inspection for integrations and keep credential values out of diagnostic messages.
- Existing Symfony wiring registrations and container-reference behavior remain compatible.
