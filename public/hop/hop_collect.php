<?php

declare(strict_types=1);

require __DIR__ . '/api_path.php';

$collectorPath = hop_public_api_path('hop_collect.php');
if ($collectorPath === null) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'hop_api_not_found'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

require $collectorPath;
