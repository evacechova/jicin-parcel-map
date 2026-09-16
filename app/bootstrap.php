<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

Dotenv\Dotenv::createImmutable(dirname(__DIR__))->safeLoad();

return [
    'name' => $_ENV['APP_NAME'] ?? $_SERVER['APP_NAME'] ?? 'Mapa parcel Jičín',
    'cors_origin' => $_ENV['APP_CORS_ORIGIN'] ?? $_SERVER['APP_CORS_ORIGIN'] ?? 'http://127.0.0.1:5173',
];
