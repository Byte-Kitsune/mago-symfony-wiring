# Mago Symfony Wiring

[![Tests](https://github.com/Byte-Kitsune/mago-symfony-wiring/actions/workflows/check.yml/badge.svg?branch=main)](https://github.com/Byte-Kitsune/mago-symfony-wiring/actions/workflows/check.yml)
[![Security Check](https://github.com/Byte-Kitsune/mago-symfony-wiring/actions/workflows/security.yml/badge.svg?branch=main)](https://github.com/Byte-Kitsune/mago-symfony-wiring/actions/workflows/security.yml)

Dev-container evidence for Symfony service wiring in [Mago](https://mago.carthage.software/1.50.0/en/). This **Analyzer** plugin checks constructor `#[Target]` bindings and adds proven autowiring references to Mago's symbol graph. The analyzer reads a sanitized reference; it never boots Symfony or executes application PHP.

Start with the [small runnable example](examples/README.md) to see why a dev service override and a named `#[Target]` need explicit wiring evidence.

## Install and run

Requires PHP 8.2+ and Mago 1.50. Pin the release in your project:

```sh
composer require --dev carthage-software/mago:1.50.0 byte-kitsune/mago-symfony-wiring:1.1.1
```

Add an extension host to `mago.toml`:

```toml
[extension-hosts.php]
command = ["php", "-d", "display_errors=stderr", "-d", "memory_limit=1G", ".mago/extensions.php"]
```

Keep PHP diagnostics on stderr: stdout carries the binary extension protocol. The worker memory allowance above also accommodates large compiled-container maps.

Generate a sanitized reference from the **dev** container after Symfony has compiled the current source and configuration. Run these commands only in a trusted application checkout. Do not use `--show-hidden` with `--types`: Symfony filters that view differently.

```sh
mkdir -p .mago
php bin/console debug:container --env=dev --types --format=json --no-interaction > /tmp/mago-types.json
php bin/console debug:container --env=dev --format=json --no-interaction > /tmp/mago-services.json
php vendor/byte-kitsune/mago-symfony-wiring/bin/create-container-reference.php \
  --types=/tmp/mago-types.json --services=/tmp/mago-services.json \
  > .mago/container-reference.dev.json
```

The `--types` view supplies automatic interface and named aliases; the ordinary view supplies concrete classes behind service IDs that the types view can omit. The exporter retains only IDs, classes, alias targets and positional constructor service references; scalar argument values are discarded. Classless definitions, including abstract templates that Symfony reports with `class: ""`, retain their IDs but have no class or constructor bindings. Symfony may encode constructor arguments by name (for example `$converter`) or as sparse numeric positions. The exporter loads `vendor/autoload.php` from the current working directory to reflect named service arguments onto their actual constructor positions. If you run it outside the application root or install the extension in a separate tools tree, pass `--autoload=/path/to/app/vendor/autoload.php` explicitly. An unknown class or parameter with a service reference fails explicitly instead of guessing an order. This is a **trusted setup** command because Composer autoload files can execute PHP. Protect the raw debug output, which can contain application arguments, and regenerate the reference after source or service configuration changes. Its hash identifies the two input views; it does not prove a Git revision. `config/reference.php` is Symfony's IDE type schema for PHP configuration, **not** the effective service map ([Symfony configuration docs](https://symfony.com/doc/current/configuration.html)).

Create `.mago/extensions.php`:

```php
<?php

use ByteKitsune\MagoSymfonyWiring\SymfonyWiringExtension;
use Mago\Sdk\Worker;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

(new Worker(SymfonyWiringExtension::fromContainerReference(
    $root,
    '.mago/container-reference.dev.json',
)))->run();
```

The exporter accepts up to 64 MiB per raw debug view, and the sanitized reference must be at most 64 MiB, including its trailing newline. The loader accepts the same limit; the 100,000 service/alias and 128 constructor-argument bounds still apply.

Run `vendor/bin/mago analyze`. A missing, linked, oversized, malformed or non-dev reference fails the worker. A target absent from the compiled reference remains unresolved; the plugin does not invent a class. In CI, export from the exact checkout in a trusted setup stage and pass the immutable reference to analysis. Do not boot an untrusted PR checkout inside the analysis worker.

## Optional source-only configuration

If booting Symfony is unavailable, `SymfonyWiringExtension::create($root, $files)` still parses an explicit ordered list of shared and dev YAML/PHP service files. It is a narrower mode: list files in merge order, shared first and dev overrides next. Paths are relative to the project root. `config/services/dev/*.yaml` files can be listed individually; test, prod and staging paths are rejected.

Each selected YAML or PHP file needs a `services` node. The parser supports explicit service classes, aliases, arguments and `autowire`; `_defaults` also accepts boolean `autoconfigure`. YAML `when@dev.services` overrides the file's shared entries. PHP files must return a literal `App::config(['services' => ...])` expression; supported literals include strings, booleans, `ClassName::class`, arrays and `service('id')`. Dynamic expressions are incomplete.

A simple autowired namespace `resource` directory can back an explicit alias to a class ID. The loader verifies that class's source file, applies literal path and brace-list exclusions, rejects abstract or attributed exclusions, and hashes the file. It resolves only classes reached by explicit aliases; it does not enumerate every resource service or infer Symfony's automatic interface aliases. Unsupported resource globs and service imports keep the map incomplete. Use the compiled reference above for those ordinary Symfony cases. The [Symfony autowiring guide](https://symfony.com/doc/current/service_container/autowiring.html) explains when Symfony creates an automatic alias.

For example, this YAML binds a named target and a default interface:

```yaml
services:
  app.formatter: { class: App\Text\Formatter }
  App\Text\FormatterInterface: '@app.formatter'
  App\Text\FormatterInterface $textFormatter: '@app.formatter'
```

```php
use App\Text\FormatterInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;

final class ReportController
{
    public function __construct(
        #[Target('textFormatter')] private FormatterInterface $formatter,
    ) {}
}
```

`#[Target]` without a proven matching dev binding produces the `unresolved-target` warning. In source-only mode, a missing, linked, oversized or unsupported service file makes the map incomplete; the plugin does not guess bindings from partial configuration.

Normal Mago runs report only wiring findings. For an orchestrator that must prove the extension completed, set `MAGO_SYMFONY_WIRING_ATTESTATION=1` on its Mago process. This emits one `analysis-attestation` note after a PHP source run. Its bounded `extension-attestation` payload records version, `service_wiring` capability, source-file count and whether all selected service files were read completely. A gate that depends on Symfony wiring should require this note even when there are no warnings. Argus enables it for its own checks and removes it from user-facing findings.

## Use the service map in other plugins

`ContainerReferenceLoader` or `ServiceConfigLoader` returns a `ServiceMap`. Each loader reads once per instance; create a new instance for a new analysis run. `classBindings()` maps typed/default or named targets to declared classes. `constructorClassBindings()` maps an owner class and constructor position to its concrete compiled target (or `null` when unresolved or conflicting). `serviceClassBindings()` maps exact selected IDs and aliases to declared classes. Both return no bindings when the selected map is incomplete:

```php
use ByteKitsune\MagoSymfonyWiring\ContainerReferenceLoader;

$loader = new ContainerReferenceLoader($root, '.mago/container-reference.dev.json');
$map = $loader->load();

$typeBindings = $map->classBindings();
$constructorBindings = $loader->constructorClassBindings();
$serviceBindings = $map->serviceClassBindings();
$complete = $map->incomplete === [];
```

The compiled reference reflects Symfony's dev autowiring type view plus its ordinary service view. It does not prove runtime behavior or complete query reachability. Proven constructor bindings add references to Mago's symbol graph, which can improve unused-definition evidence. The plugin does not suppress Mago's native dead-code diagnostics or claim that every runtime service is reachable.

## Compare with the Symfony dev container

For the narrower source-only mode, a selected-wiring parity gate compares its inferred IDs with Symfony's debug container. Use the same ordered service-file list as the Mago extension host:

```sh
set -o pipefail
APP_ENV=dev APP_DEBUG=1 php bin/console debug:container --env=dev --format=json --show-hidden --no-interaction |
  php vendor/byte-kitsune/mago-symfony-wiring/bin/compare-container.php \
    --root=. \
    --service-file=config/services.yaml \
    --service-file=config/services.dev.yaml
```

The command never starts Symfony itself. It checks that every service ID and alias selected by the static map resolves to the same service ID and class in Symfony's dev debug container. Its one-line JSON report contains `status` (`pass`, `mismatch`, or `incomplete`), `scope`, `checked`, `mismatch_count`, `truncated`, up to 1,000 `mismatches`, and input hashes when available. Exit codes are 0, 1, and 2 respectively. Treat anything other than `pass` as a failed gate. The raw Symfony JSON can contain application configuration values; piping it avoids saving that full output to a file.

This proves parity only for **selected service IDs** and the exact inputs used for that comparison. Symfony's JSON does not attest its environment or Git revision, so the trusted caller must run the command as shown against the intended dev checkout. The result does not prove that every Symfony service was selected or that runtime objects behave identically. Imports, compiler passes, environment-sensitive code, and other dynamic wiring remain outside the static parser; a mismatch exposes one of those differences instead of silently claiming parity. The Mago worker continues to parse source without booting the application.

## Develop

```sh
composer install
sh tests/smoke.sh
```

The [fixture](tests/corpus) exercises YAML, PHP, dev overrides, `#[Target]` and incomplete bindings. A second [fixture](tests/parity-fixture) compiles a real Symfony container and checks matching and drifting bindings. Licensed under [MIT](LICENSE).

## Optional PHP/YAML configuration security (1.1.0)

The independent companion detects hardcoded string/numeric credentials assigned to sensitive names in PHP and YAML. It is opt-in and does not require a Symfony container reference. This is a bounded key-based check; it does not prove that all possible secrets are absent.

```php
use ByteKitsune\MagoSymfonyWiring\SecurityExtension;
use Mago\Sdk\Worker;

(new Worker(
    SymfonyWiringExtension::fromContainerReference(__DIR__, 'var/container-reference.json'),
    SecurityExtension::create(__DIR__, [
        // Defaults: password, passwd, pwd, secret, token, api_key,
        // access_token, client_secret, private_key, authorization.
        // 'sensitiveKeys' replaces the default list when supplied.
        'excludePaths' => ['tests/fixtures/*'],
    ]),
))->run();
```

After registering the companion, disable the overlapping native `no-literal-password` rule **only in that project's configuration**:

```toml
[linter.rules.no-literal-password]
enabled = false
```

The companion registers its own enabled error rule, `byte-kitsune/symfony-wiring/no-hardcoded-secret`. Do not disable the native rule before installing/registering the replacement. The extension cannot mutate Mago's native rule configuration. Existing wiring-only registrations keep their existing behavior. Native rule checks run with `mago lint`; analyzer issue filters do not filter these linter diagnostics.

PHP covers assignments, array keys, property assignments/defaults, constant definitions, parameter defaults, and named arguments. Names are normalized from camelCase to snake_case and match whole sensitive keys or underscore-separated suffixes: `dbPassword` and `api_token` match, `bypass` and general `key` metadata do not. Non-empty string/numeric literals and statically concatenated strings are checked. Empty/whitespace values and non-literal expressions (including Symfony `env()` helpers) are outside literal detection. Arbitrary dynamic helpers are not asserted safe.

Only an entire Symfony placeholder is permitted, including `%env(resolve:APP_PASSWORD)%`, `%env(default::APP_PASSWORD)%`, `%env(default:app.secret:APP_PASSWORD)%`, and `%env(enum:App\Enum\Kind:ENV)%`. Prefixes, suffixes, malformed placeholders and missing variable names remain findings. Processor names/arguments are checked syntactically; existence/runtime validity of custom processors is not proven. Diagnostics never include the credential value.

### Explicit source inspection API

Mago's native linter reads PHP, not YAML. For configuration review/indexing outside native PHP lint, inspect explicit file paths without executing either configuration format:

```php
use ByteKitsune\MagoSymfonyWiring\Security\ConfigSecretInspector;

$report = ConfigSecretInspector::inspectConfigFiles(
    $projectRoot,
    ['config/packages/framework.yaml', 'config/services.php'],
    ['excludePaths' => ['tests/fixtures/*']],
);
```

The result is schema version 1: `column_encoding: utf8_bytes`, `issues`, and `incomplete`. Issues contain the same rule code, severity `error`, repository-relative `path`, 1-based `line`/`column` and exclusive `end_line`/`end_column`, plus a value-free `message`. No PHP configuration is executed. Symlink traversal outside the project is rejected. Limits: 512 explicit files, 1 MiB per file, 16 MiB per batch, 4096 issues; callers should batch larger scopes. Missing/invalid/unsupported files or limit exhaustion produce `incomplete`, never a clean result.

YAML is first syntax-validated by Symfony's parser with object/tag execution disabled, then its scalar source tokens provide exact spans. Block/flow mappings, quoted multiline strings, literal/folded block strings and simple scalar anchors/aliases are supported. Complex anchors/aliases, collections at sensitive keys, custom tags and multiline plain scalar continuation are reported as incomplete for that value. General secret detection inside URLs, arbitrary text, positional call arguments, unknown dynamic expressions and complex YAML merge semantics is outside this check.

`SecurityExtension::inspectConfiguration($workerSource, $sourcePath)` returns `{schema_version: 1, status: enabled|absent|unresolved, options?}` without executing the worker. It recognizes one unconditional companion registration in a returned extension array or a top-level `Worker(...)->run()`, including simple assigned extension arrays and namespace import aliases. Conditional, deferred, mutated or dynamically configured registrations are unresolved. For a statically proven `require` result consumed by `Worker(...$extensions)->run()`, the optional `references` list contains folded absolute paths. Only literal paths, `__DIR__`/`__FILE__`, `dirname()` and immutable path variables are folded. Known conditional wiring-extension appends are accepted; unknown mutation invalidates the proof. Callers must restrict these paths to the project, inspect the referenced source themselves, and treat unresolved/ambiguous children as incomplete. An existing canonical extension file alone is not evidence of an active registration.

### Standalone CLI / Composer shortcut

The companion is usable without T3. Native `mago lint` covers PHP. Run the explicit-file CLI for YAML (or PHP + YAML together):

```sh
php vendor/byte-kitsune/mago-symfony-wiring/bin/check-config-secrets.php \
  --root=. --file=config/packages/security.yaml --file=config/services.php
```

Output is the JSON report described above. Exit codes: `0` clean within the supported scope; `1` findings; `2` incomplete coverage or invalid invocation. Repeat `--exclude=tests/fixtures/*` to exclude known fixtures explicitly. Repeat `--sensitive-key=KEY` to replace the default sensitive-key list. No directories are expanded implicitly. For example, a Composer script may name this command `check-config-secrets` and list the repository's relevant configuration files. A code `2` must not be treated as a successful security check.
