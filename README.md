# Mago Symfony Wiring

[![Tests](https://github.com/Byte-Kitsune/mago-symfony-wiring/actions/workflows/check.yml/badge.svg?branch=main)](https://github.com/Byte-Kitsune/mago-symfony-wiring/actions/workflows/check.yml)
[![Security Check](https://github.com/Byte-Kitsune/mago-symfony-wiring/actions/workflows/security.yml/badge.svg?branch=main)](https://github.com/Byte-Kitsune/mago-symfony-wiring/actions/workflows/security.yml)

Static evidence for Symfony service wiring in [Mago](https://mago.carthage.software/1.50.0/en/). This beta is an **Analyzer** plugin: it checks constructor `#[Target]` bindings and adds proven autowiring references to Mago's symbol graph. It parses configuration; it never boots Symfony or executes PHP service files.

Start with the [small runnable example](examples/README.md) to see why a dev service override and a named `#[Target]` need explicit wiring evidence.

## Install and run

Requires PHP 8.2+ and Mago 1.50. Pin the beta in your project:

```sh
composer require --dev carthage-software/mago:1.50.0 byte-kitsune/mago-symfony-wiring:0.1.0-beta.4
```

Add an extension host to `mago.toml`:

```toml
[extension-hosts.php]
command = ["php", ".mago/extensions.php"]
```

Create `.mago/extensions.php`:

```php
<?php

use ByteKitsune\MagoSymfonyWiring\SymfonyWiringExtension;
use Mago\Sdk\Worker;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

(new Worker(SymfonyWiringExtension::create($root, [
    'config/services.yaml',
    'config/services.dev.yaml',
])))->run();
```

Run `vendor/bin/mago analyze`. List the service files explicitly, in merge order: shared files first, then dev overrides. Paths are relative to the project root. `config/services/dev/*.yaml` files can be listed individually; test, prod and staging paths are rejected. The worker is trusted configuration, so review its file list alongside code changes.

## Supported configuration

Each selected YAML or PHP file needs a `services` node. The parser supports explicit service classes, aliases, arguments and `autowire`; `_defaults` supports `autowire`. YAML `when@dev.services` overrides the file's shared entries. PHP files must return a literal `App::config(['services' => ...])` expression; supported literals include strings, booleans, `ClassName::class`, arrays and `service('id')`. Dynamic expressions are incomplete.

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

`#[Target]` without a proven matching dev binding produces the `unresolved-target` warning. A missing, linked, oversized or unsupported service file makes the map incomplete; the plugin does not guess bindings from partial configuration.

The Analyzer emits one `analysis-attestation` note after a PHP source run. Its bounded `extension-attestation` payload records version, `service_wiring` capability, source-file count and whether all selected service files were read completely. A gate that depends on Symfony wiring should require this note even when there are no warnings.

## Use the service map in other plugins

`ServiceConfigLoader` returns a `ServiceMap` with `incomplete` reasons and SHA-256 `hashes` for selected files. `classBindings()` maps typed/default or named targets to declared classes. `serviceClassBindings()` maps exact IDs and aliases to declared classes. Both return no bindings when the selected map is incomplete:

```php
use ByteKitsune\MagoSymfonyWiring\ServiceConfigLoader;

$map = (new ServiceConfigLoader($root, [
    'config/services.yaml',
    'config/services.dev.yaml',
]))->load();

$typeBindings = $map->classBindings();
$serviceBindings = $map->serviceClassBindings();
$complete = $map->incomplete === [];
```

These are literal dev-configuration facts, not a compiled-container model. Proven constructor bindings add references to Mago's symbol graph, which can improve unused-definition evidence. The plugin does not suppress Mago's native dead-code diagnostics or claim that every runtime service is reachable.

## Develop

```sh
composer install
sh tests/smoke.sh
```

The [fixture](tests/corpus) exercises YAML, PHP, dev overrides, `#[Target]` and incomplete bindings. Licensed under [MIT](LICENSE).
