<?php

declare(strict_types=1);

$dataFiles = __DIR__ . '/api/lib/DataFiles.php';
if (!is_file($dataFiles)) {
    $dataFiles = __DIR__ . '/../server/api/lib/DataFiles.php';
}

require $dataFiles;

header('Content-Type: application/xml; charset=utf-8');
header('X-Content-Type-Options: nosniff');

$baseUrl = 'https://kolej.live';
$today = (new DateTimeImmutable('now', new DateTimeZone('Europe/Warsaw')))->format('Y-m-d');
$urls = [
    ['loc' => $baseUrl . '/', 'priority' => '1.0'],
    ['loc' => $baseUrl . '/?lista=pociagi', 'priority' => '0.9'],
    ['loc' => $baseUrl . '/?lista=pociagi-w-trasie', 'priority' => '0.9'],
    ['loc' => $baseUrl . '/?lista=pociagi-odwolane', 'priority' => '0.9'],
];

$stations = sitemap_items('stations.json');
foreach ($stations as $station) {
    $id = (int) ($station['id'] ?? 0);
    $name = sitemap_clean($station['name'] ?? null);
    if ($id <= 0 || $name === null) {
        continue;
    }

    $urls[] = [
        'loc' => $baseUrl . '/?stacja=' . rawurlencode(sitemap_slug($name)) . '&id_stacji=' . $id,
        'priority' => '0.8',
    ];
}

$trains = sitemap_items('trains-' . $today . '.json');
foreach ($trains as $train) {
    $label = sitemap_clean($train['label'] ?? null);
    if ($label === null) {
        continue;
    }

    $url = $baseUrl . '/?pociag=' . rawurlencode(sitemap_slug($label));

    $urls[] = [
        'loc' => $url,
        'priority' => '0.7',
    ];
}

$urls = array_slice(sitemap_unique_urls($urls), 0, 50000);

echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
echo "<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n";
foreach ($urls as $url) {
    echo "  <url>\n";
    echo '    <loc>' . htmlspecialchars($url['loc'], ENT_XML1 | ENT_COMPAT, 'UTF-8') . "</loc>\n";
    echo '    <lastmod>' . $today . "</lastmod>\n";
    echo '    <changefreq>daily</changefreq>' . "\n";
    echo '    <priority>' . $url['priority'] . "</priority>\n";
    echo "  </url>\n";
}
echo "</urlset>\n";

function sitemap_items(string $name): array
{
    $payload = data_read_json($name);
    $items = $payload['items'] ?? $payload;

    return is_array($items) ? array_values(array_filter($items, 'is_array')) : [];
}

function sitemap_unique_urls(array $urls): array
{
    $seen = [];
    $unique = [];

    foreach ($urls as $url) {
        if (isset($seen[$url['loc']])) {
            continue;
        }

        $seen[$url['loc']] = true;
        $unique[] = $url;
    }

    return $unique;
}

function sitemap_clean($value): ?string
{
    if ($value === null) {
        return null;
    }

    $string = trim((string) $value);

    return $string === '' ? null : $string;
}

function sitemap_slug(string $value): string
{
    $slug = str_replace(' ', '-', data_normalize_text($value));
    $slug = trim(preg_replace('/-+/', '-', $slug) ?? $slug, '-');

    return $slug !== '' ? $slug : 'kolej';
}

function sitemap_train_number(string $label): ?string
{
    if (preg_match('/\b\d{2,7}\b/', $label, $matches)) {
        return $matches[0];
    }

    return null;
}
