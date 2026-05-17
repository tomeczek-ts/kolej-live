<?php

declare(strict_types=1);

$localConfig = is_file(__DIR__ . '/config.local.php') ? require __DIR__ . '/config.local.php' : [];
if (!is_array($localConfig)) {
    $localConfig = [];
}

define('PDP_API_BASE_URL', (string) ($localConfig['PDP_API_BASE_URL'] ?? getenv('PDP_API_BASE_URL') ?: 'https://pdp-api.plk-sa.pl'));
define('PDP_API_KEY', (string) ($localConfig['PDP_API_KEY'] ?? getenv('PDP_API_KEY') ?: 'WSTAW_KLUCZ_PDP_API'));
define('PDP_CACHE_DIR', (string) ($localConfig['PDP_CACHE_DIR'] ?? (__DIR__ . '/cache')));
define('PDP_HTTP_TIMEOUT_SECONDS', (int) ($localConfig['PDP_HTTP_TIMEOUT_SECONDS'] ?? 25));
define('CACHE_WARM_TOKEN', (string) ($localConfig['CACHE_WARM_TOKEN'] ?? getenv('CACHE_WARM_TOKEN') ?: ''));
