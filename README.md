# Mago Symfony Wiring

**Beta: 0.1.0-beta.1.** The configuration subset and public API may change
before a stable release; pin the exact prerelease version in consumers.

A Mago Analyzer Plugin that checks literal Symfony service wiring for the dev
environment. It never boots Symfony or evaluates PHP configuration. It accepts
only an explicit ordered list of shared and dev service files; test/prod files
are rejected. Unsupported configuration is recorded as incomplete evidence.

Install `byte-kitsune/mago-symfony-wiring` with `carthage-software/mago` and
register the package factory in the application's `.mago/extensions.php`:

```php
use ByteKitsune\MagoSymfonyWiring\SymfonyWiringExtension;
use Mago\Sdk\Worker;

require dirname(__DIR__) . '/vendor/autoload.php';
(new Worker(SymfonyWiringExtension::create(dirname(__DIR__), [
    'config/services.yaml',
    'config/services.dev.yaml',
])))->run();
```

Then set `[extension-hosts.php] command = ["php", ".mago/extensions.php"]`
in `mago.toml`. The first release resolves explicit service IDs, aliases,
constructor arguments, and `#[Target]` named aliases; it reports unproven
targets without claiming a complete Symfony container model. The public
`ServiceConfigLoader`/`ServiceMap` API also supports other analyzer plugins.
