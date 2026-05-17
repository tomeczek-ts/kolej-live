<?php

declare(strict_types=1);

function hop_public_api_path(string $relativePath): ?string
{
    $relativePath = ltrim(str_replace('\\', '/', $relativePath), '/');
    $documentRoot = isset($_SERVER['DOCUMENT_ROOT']) ? (string) $_SERVER['DOCUMENT_ROOT'] : '';

    $apiRoots = [
        __DIR__ . '/api',
        __DIR__ . '/../api',
        __DIR__ . '/../../server/api',
    ];

    if ($documentRoot !== '') {
        $apiRoots[] = rtrim($documentRoot, '/\\') . '/api';
        $apiRoots[] = dirname(rtrim($documentRoot, '/\\')) . '/api';
    }

    foreach ($apiRoots as $apiRoot) {
        $candidate = $apiRoot . '/' . $relativePath;
        if (is_file($candidate)) {
            return $candidate;
        }
    }

    return null;
}
