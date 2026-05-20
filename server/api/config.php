<?php

declare(strict_types=1);

require_once __DIR__ . '/AppConfig.php';

define('PDP_API_BASE_URL', (string) app_config_value('PDP_API_BASE_URL', 'https://pdp-api.plk-sa.pl'));
define('PDP_API_KEY', (string) app_config_value('PDP_API_KEY', 'WSTAW_KLUCZ_PDP_API'));
define('PDP_CACHE_DIR', (string) app_config_value('PDP_CACHE_DIR', __DIR__ . '/cache'));
define('PDP_HTTP_TIMEOUT_SECONDS', (int) app_config_value('PDP_HTTP_TIMEOUT_SECONDS', 25));
define('CACHE_WARM_TOKEN', (string) app_config_value('CACHE_WARM_TOKEN', ''));
