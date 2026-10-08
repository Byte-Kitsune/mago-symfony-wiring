# Changelog

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
