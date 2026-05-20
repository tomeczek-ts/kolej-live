<?php

declare(strict_types=1);

function app_config_load_array(string $path): array
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

function app_config(): array
{
    static $config = null;

    if (is_array($config)) {
        return $config;
    }

    $sharedLocal = app_config_load_array(__DIR__ . '/config.local.php');
    $legacyHopLocal = app_config_load_array(__DIR__ . '/hop/Config.local.php');

    $config = array_replace($legacyHopLocal, $sharedLocal);

    return $config;
}

function app_config_value(string $key, $default = null)
{
    $config = app_config();
    if (array_key_exists($key, $config)) {
        return $config[$key];
    }

    $env = getenv($key);
    if ($env !== false) {
        return $env;
    }

    return $default;
}
