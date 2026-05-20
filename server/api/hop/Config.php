<?php

declare(strict_types=1);

require_once __DIR__ . '/../AppConfig.php';

define('HOP_DB_HOST', (string) app_config_value('HOP_DB_HOST', 'localhost'));
define('HOP_DB_NAME', (string) app_config_value('HOP_DB_NAME', 'WSTAW_NAZWE_BAZY'));
define('HOP_DB_USER', (string) app_config_value('HOP_DB_USER', 'WSTAW_UZYTKOWNIKA_BAZY'));
define('HOP_DB_PASSWORD', (string) app_config_value('HOP_DB_PASSWORD', 'WSTAW_HASLO_BAZY'));
define('HOP_COLLECT_TOKEN', (string) app_config_value('HOP_COLLECT_TOKEN', ''));
