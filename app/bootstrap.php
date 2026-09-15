<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

Dotenv\Dotenv::createImmutable(dirname(__DIR__))->safeLoad();

return [
    'name' => $_ENV['APP_NAME'] ?? $_SERVER['APP_NAME'] ?? 'Mapa parcel Jičín',
];
