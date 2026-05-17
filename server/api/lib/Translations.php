<?php

declare(strict_types=1);

function translations_supported_locales(): array
{
    return ['pl', 'en'];
}

function translations_default_locale(): string
{
    return 'pl';
}

function translations_normalize_locale(string $locale): string
{
    return in_array($locale, translations_supported_locales(), true)
        ? $locale
        : translations_default_locale();
}

function translations_for_locale(string $locale): array
{
    $locale = translations_normalize_locale($locale);
    $path = __DIR__ . '/../lang/' . $locale . '.php';
    $translations = is_file($path) ? require $path : [];

    return is_array($translations) ? $translations : [];
}
