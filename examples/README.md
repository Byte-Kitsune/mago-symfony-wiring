# Why Symfony wiring evidence matters

Imagine a controller that asks Symfony for `FormatterInterface` with `#[Target('textFormatter')]`. The PHP type alone cannot tell Mago which implementation the dev container injects. A shared service file names `SharedFormatter`, while the dev override selects `DevFormatter`. If the target name has no binding, silently choosing either class would hide a real wiring problem.

[The formatter example](formatter) provides both cases. `ReportController` has a proven dev binding; `MissingController` requests a name absent from the selected configuration and produces `unresolved-target`. The extension also adds the proven implementation as a reference in Mago's symbol graph without suppressing native unused-definition findings.

Run it from this repository after `composer install`:

```sh
cd examples/formatter
../../vendor/bin/mago analyze --reporting-format json --minimum-report-level note
```

Look for `byte-kitsune/symfony-wiring/unresolved-target` and `analysis-attestation`. The missing target is an intentional warning; whether warnings fail the command depends on your Mago fail level. The worker lists shared and dev files explicitly, in override order; this example does not boot Symfony.

For an ordinary Symfony app, use the [compiled dev-container reference workflow](../README.md#install-and-run) instead. It captures automatic interface aliases, named targets and PHP/YAML dev overrides without reproducing Symfony's loader in this extension. The reference is generated in a trusted setup step; Mago only reads its sanitized result.
