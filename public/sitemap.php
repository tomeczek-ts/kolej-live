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
$hopBaseUrl = 'https://hop.kolej.live';
$today = (new DateTimeImmutable('now', new DateTimeZone('Europe/Warsaw')))->format('Y-m-d');
$urls = [
    ['loc' => $baseUrl . '/', 'priority' => '1.0'],
    ['loc' => $baseUrl . '/?lista=pociagi', 'priority' => '0.9'],
    ['loc' => $baseUrl . '/?lista=pociagi-w-trasie', 'priority' => '0.9'],
    ['loc' => $baseUrl . '/?lista=pociagi-odwolane', 'priority' => '0.9'],
    ['loc' => $hopBaseUrl . '/', 'priority' => '0.8'],
    ['loc' => $hopBaseUrl . '/sitemap.xml', 'priority' => '0.4'],
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

foreach (sitemap_hop_services(20000) as $service) {
    $slug = sitemap_clean($service['slug'] ?? null);
    if ($slug === null) {
        continue;
    }

    $urls[] = [
        'loc' => $hopBaseUrl . '/?historia_opoznien=' . rawurlencode($slug),
        'priority' => '0.7',
        'lastmod' => sitemap_clean($service['lastmod'] ?? null) ?? $today,
    ];
}

$urls = array_slice(sitemap_unique_urls($urls), 0, 50000);

echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
echo "<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n";
foreach ($urls as $url) {
    echo "  <url>\n";
    echo '    <loc>' . htmlspecialchars($url['loc'], ENT_XML1 | ENT_COMPAT, 'UTF-8') . "</loc>\n";
    echo '    <lastmod>' . htmlspecialchars($url['lastmod'] ?? $today, ENT_XML1 | ENT_COMPAT, 'UTF-8') . "</lastmod>\n";
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

function sitemap_hop_services(int $limit): array
{
    $databasePath = __DIR__ . '/api/hop/Database.php';
    if (!is_file($databasePath)) {
        $databasePath = __DIR__ . '/../server/api/hop/Database.php';
    }

    if (!is_file($databasePath)) {
        return [];
    }

    try {
        require_once $databasePath;
        if (!function_exists('hop_pdo')) {
            return [];
        }

        $stmt = hop_pdo()->prepare(
            "SELECT
               MAX(label) AS label,
               MAX(operating_date) AS lastmod
             FROM hop_train_runs
             GROUP BY service_key
             ORDER BY MAX(operating_date) DESC, MAX(label) ASC
             LIMIT :limit"
        );
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        $services = [];
        foreach ($stmt->fetchAll() as $row) {
            $label = sitemap_clean($row['label'] ?? null);
            if ($label === null) {
                continue;
            }

            $services[] = [
                'slug' => sitemap_slug($label),
                'lastmod' => sitemap_clean($row['lastmod'] ?? null),
            ];
        }

        return $services;
    } catch (Throwable $exception) {
        return [];
    }
}
