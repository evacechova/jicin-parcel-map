<?php

declare(strict_types=1);

$config = require dirname(__DIR__) . '/app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

// Foundation smoke response; domain routes arrive in Phase 04.
if (parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) !== '/api') {
    http_response_code(404);
    echo json_encode(['message' => 'Not found'], JSON_THROW_ON_ERROR);
    return;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    echo json_encode(['message' => 'Method not allowed'], JSON_THROW_ON_ERROR);
    return;
}

echo json_encode(['name' => $config['name']], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
