<?php

declare(strict_types=1);

use ByteKitsune\MagoSymfonyWiring\SymfonyWiringExtension;
use Mago\Sdk\Worker;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

(new Worker(SymfonyWiringExtension::create(__DIR__, [
    'config/services.yaml',
    'config/services.dev.yaml',
])))->run();
