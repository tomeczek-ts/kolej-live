<?php

declare(strict_types=1);

function data_file_path(string $name): string
{
    return __DIR__ . '/../data/' . ltrim($name, '/');
}

function data_read_json(string $name): ?array
{
    $path = data_file_path($name);
    if (!is_file($path)) {
        return null;
    }

    $raw = file_get_contents($path);
    if ($raw === false || $raw === '') {
        return null;
    }

    $decoded = json_decode($raw, true);

    return is_array($decoded) ? $decoded : null;
}

function data_write_json(string $name, array $payload): void
{
    $path = data_file_path($name);
    $dir = dirname($path);
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }

    file_put_contents($path, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
}

function data_write_txt(string $name, array $rows): void
{
    $path = data_file_path($name);
    $dir = dirname($path);
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }

    file_put_contents($path, implode(PHP_EOL, $rows) . PHP_EOL, LOCK_EX);
}

function data_find_items(string $file, string $query, int $limit, array $fields): array
{
    $payload = data_read_json($file);
    $items = $payload['items'] ?? $payload;
    if (!is_array($items)) {
        return [];
    }

    $needle = data_normalize_text($query);
    if ($needle === '') {
        return array_slice(array_values($items), 0, $limit);
    }

    $ranked = [];
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }

        $haystackParts = [];
        foreach ($fields as $field) {
            $haystackParts[] = (string) ($item[$field] ?? '');
        }

        $haystack = data_normalize_text(implode(' ', $haystackParts));
        $position = strpos($haystack, $needle);
        if ($position === false) {
            continue;
        }

        $ranked[] = ['rank' => $position, 'item' => $item];
    }

    usort($ranked, static function (array $a, array $b): int {
        return ($a['rank'] <=> $b['rank']) ?: strcmp((string) ($a['item']['label'] ?? $a['item']['name'] ?? ''), (string) ($b['item']['label'] ?? $b['item']['name'] ?? ''));
    });

    return array_slice(array_column($ranked, 'item'), 0, $limit);
}

function data_normalize_text(string $value): string
{
    $lower = function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    $ascii = function_exists('iconv') ? iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $lower) : $lower;

    return trim(preg_replace('/[^a-z0-9]+/', ' ', (string) $ascii) ?? $lower);
}
