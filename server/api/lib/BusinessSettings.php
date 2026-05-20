<?php

declare(strict_types=1);

function business_settings_path(): ?string
{
    $candidates = [
        __DIR__ . '/../business-settings.json',
        __DIR__ . '/../../../business-settings.json',
    ];

    foreach ($candidates as $candidate) {
        if (is_file($candidate)) {
            return $candidate;
        }
    }

    return null;
}

function business_settings(): array
{
    static $settings = null;

    if (is_array($settings)) {
        return $settings;
    }

    $path = business_settings_path();
    if ($path === null) {
        $settings = [];
        return $settings;
    }

    $raw = file_get_contents($path);
    $decoded = $raw !== false ? json_decode($raw, true) : null;
    $settings = is_array($decoded) ? $decoded : [];

    return $settings;
}

function business_setting(string $path, $default = null)
{
    $value = business_settings();

    foreach (explode('.', $path) as $segment) {
        if (!is_array($value) || !array_key_exists($segment, $value)) {
            return $default;
        }

        $value = $value[$segment];
    }

    return $value;
}

function business_setting_int(string $path, int $default, int $min = PHP_INT_MIN, int $max = PHP_INT_MAX): int
{
    $value = business_setting($path, $default);
    if (!is_numeric($value)) {
        return $default;
    }

    return min(max((int) $value, $min), $max);
}

function business_cache_ttl(string $name, int $default): int
{
    return business_setting_int('apiCacheTtlSeconds.' . $name, $default, 0);
}
