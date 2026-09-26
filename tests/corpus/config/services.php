<?php

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use App\FormatterInterface;
use App\DevFormatter;

return App::config([
    'services' => [
        'formatter.named' => ['class' => DevFormatter::class, 'autowire' => false, 'arguments' => [service('mailer')]],
        FormatterInterface::class . ' $textFormatter' => service('formatter.named'),
    ],
]);
