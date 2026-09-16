<?php

declare(strict_types=1);

use App\Api\MapApi;
use App\Api\MapApiConfig;
use App\Database\ConnectionFactory;
use App\Database\DatabaseConfig;
use App\Http\JsonResponse;
use App\Http\Request;
use App\Read\DatabaseMapReadService;
use App\Read\UnavailableMapReadService;

$config = require dirname(__DIR__) . '/app/bootstrap.php';
$request = Request::fromGlobals();
$origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
$isCorsRoute = in_array($request->path, [
    '/api/v1/cadastral-territories',
    '/api/v1/parcels',
], true) || preg_match('#^/api/v1/parcels/[^/]+$#D', $request->path) === 1;

if ($isCorsRoute && $origin !== '' && hash_equals((string) $config['cors_origin'], $origin)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
    if ($request->method === 'OPTIONS') {
        header('Access-Control-Allow-Methods: GET');
        header('Access-Control-Allow-Headers: Accept');
        http_response_code(204);
        return;
    }
}

if ($request->path === '/api') {
    if ($request->method !== 'GET') {
        (new JsonResponse(405, ['message' => 'Method not allowed'], ['Allow' => 'GET']))->emit();
        return;
    }
    (new JsonResponse(200, ['name' => $config['name']]))->emit();
    return;
}

try {
    $pdo = ConnectionFactory::create(DatabaseConfig::fromEnvironment());
    $service = new DatabaseMapReadService($pdo, new MapApiConfig());
} catch (Throwable $exception) {
    error_log(sprintf('Map API database bootstrap failed: %s', $exception::class));
    $service = new UnavailableMapReadService();
}

(new MapApi(
    $service,
    new MapApiConfig(),
    static function (string $requestId, Throwable $exception): void {
        error_log(sprintf('Map API request %s failed: %s', $requestId, $exception::class));
    },
))->handle($request)->emit();
