<?php

declare(strict_types=1);

function load_config_array(string $path): array
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

$localConfig = load_config_array(__DIR__ . '/config.local.php');

define('PDP_API_BASE_URL', (string) ($localConfig['PDP_API_BASE_URL'] ?? getenv('PDP_API_BASE_URL') ?: 'https://pdp-api.plk-sa.pl'));
define('PDP_API_KEY', (string) ($localConfig['PDP_API_KEY'] ?? getenv('PDP_API_KEY') ?: 'WSTAW_KLUCZ_PDP_API'));
define('PDP_CACHE_DIR', (string) ($localConfig['PDP_CACHE_DIR'] ?? (__DIR__ . '/cache')));
define('PDP_HTTP_TIMEOUT_SECONDS', (int) ($localConfig['PDP_HTTP_TIMEOUT_SECONDS'] ?? 25));
define('CACHE_WARM_TOKEN', (string) ($localConfig['CACHE_WARM_TOKEN'] ?? getenv('CACHE_WARM_TOKEN') ?: ''));
