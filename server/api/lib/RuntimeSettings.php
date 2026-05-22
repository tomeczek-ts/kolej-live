<?php

declare(strict_types=1);

function runtime_settings_path(): string
{
    $dir = defined('PDP_CACHE_DIR') ? (string) PDP_CACHE_DIR : (__DIR__ . '/../cache');

    return rtrim($dir, '/\\') . '/runtime-settings.json';
}

function runtime_settings_defaults(): array
{
    return [
        'gaDisabled' => false,
        'updatedAt' => null,
        'updatedBy' => null,
    ];
}

function runtime_settings_read(): array
{
    $defaults = runtime_settings_defaults();
    $path = runtime_settings_path();
    if (!is_file($path)) {
        return $defaults;
    }

    $raw = file_get_contents($path);
    if ($raw === false) {
        return $defaults;
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return $defaults;
    }

    return array_replace($defaults, $decoded);
}

function runtime_settings_write(array $settings): bool
{
    $path = runtime_settings_path();
    $dir = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }

    if (!is_dir($dir) || !is_writable($dir)) {
        return false;
    }

    $payload = array_replace(runtime_settings_defaults(), $settings);

    return file_put_contents($path, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), LOCK_EX) !== false;
}

function runtime_google_analytics_enabled(): bool
{
    return !((bool) (runtime_settings_read()['gaDisabled'] ?? false));
}
