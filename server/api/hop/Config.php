<?php

declare(strict_types=1);

$hopLocalConfig = is_file(__DIR__ . '/Config.local.php') ? require __DIR__ . '/Config.local.php' : [];
if (!is_array($hopLocalConfig)) {
    $hopLocalConfig = [];
}

define('HOP_DB_HOST', (string) ($hopLocalConfig['HOP_DB_HOST'] ?? getenv('HOP_DB_HOST') ?: 'localhost'));
define('HOP_DB_NAME', (string) ($hopLocalConfig['HOP_DB_NAME'] ?? getenv('HOP_DB_NAME') ?: 'WSTAW_NAZWE_BAZY'));
define('HOP_DB_USER', (string) ($hopLocalConfig['HOP_DB_USER'] ?? getenv('HOP_DB_USER') ?: 'WSTAW_UZYTKOWNIKA_BAZY'));
define('HOP_DB_PASSWORD', (string) ($hopLocalConfig['HOP_DB_PASSWORD'] ?? getenv('HOP_DB_PASSWORD') ?: 'WSTAW_HASLO_BAZY'));
define('HOP_COLLECT_TOKEN', (string) ($hopLocalConfig['HOP_COLLECT_TOKEN'] ?? getenv('HOP_COLLECT_TOKEN') ?: ''));
