<?php

declare(strict_types=1);

function hop_load_config_array(string $path): array
{
    if (!is_file($path)) {
        return [];
    }

    $level = ob_get_level();
    ob_start();

    try {
        $config = require $path;
    } finally {
        while (ob_get_level() > $level) {
            ob_end_clean();
        }
    }

    return is_array($config) ? $config : [];
}

$hopLocalConfig = hop_load_config_array(__DIR__ . '/Config.local.php');

define('HOP_DB_HOST', (string) ($hopLocalConfig['HOP_DB_HOST'] ?? getenv('HOP_DB_HOST') ?: 'localhost'));
define('HOP_DB_NAME', (string) ($hopLocalConfig['HOP_DB_NAME'] ?? getenv('HOP_DB_NAME') ?: 'WSTAW_NAZWE_BAZY'));
define('HOP_DB_USER', (string) ($hopLocalConfig['HOP_DB_USER'] ?? getenv('HOP_DB_USER') ?: 'WSTAW_UZYTKOWNIKA_BAZY'));
define('HOP_DB_PASSWORD', (string) ($hopLocalConfig['HOP_DB_PASSWORD'] ?? getenv('HOP_DB_PASSWORD') ?: 'WSTAW_HASLO_BAZY'));
define('HOP_COLLECT_TOKEN', (string) ($hopLocalConfig['HOP_COLLECT_TOKEN'] ?? getenv('HOP_COLLECT_TOKEN') ?: ''));
