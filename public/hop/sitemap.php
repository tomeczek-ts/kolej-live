<?php

declare(strict_types=1);

require __DIR__ . '/api_path.php';

header('Content-Type: application/xml; charset=utf-8');
header('X-Content-Type-Options: nosniff');

$baseUrl = 'https://hop.kolej.live';
$today = (new DateTimeImmutable('now', new DateTimeZone('Europe/Warsaw')))->format('Y-m-d');
$urls = [
    ['loc' => $baseUrl . '/', 'priority' => '1.0', 'lastmod' => $today],
    ['loc' => $baseUrl . '/?pociagi=wszystkie', 'priority' => '0.9', 'lastmod' => $today],
];

$databasePath = hop_public_api_path('hop/Database.php');
if ($databasePath !== null) {
    require_once $databasePath;
}

if (function_exists('hop_pdo')) {
    try {
        $stmt = hop_pdo()->prepare(
            "SELECT
               MAX(label) AS label,
               MAX(operating_date) AS lastmod
             FROM hop_train_runs
             GROUP BY service_key
             ORDER BY MAX(operating_date) DESC, MAX(label) ASC
             LIMIT :limit"
        );
        $stmt->bindValue(':limit', 49999, PDO::PARAM_INT);
        $stmt->execute();

        foreach ($stmt->fetchAll() as $row) {
            $label = hop_sitemap_clean($row['label'] ?? null);
            if ($label === null) {
                continue;
            }

            $urls[] = [
                'loc' => $baseUrl . '/?historia_opoznien=' . rawurlencode(hop_sitemap_slug($label)),
                'priority' => '0.8',
                'lastmod' => hop_sitemap_clean($row['lastmod'] ?? null) ?? $today,
            ];
        }
    } catch (Throwable $exception) {
        // Sitemap must stay readable even before the HOP database is initialized.
    }
}

$urls = hop_sitemap_unique_urls($urls);

echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
echo "<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n";
foreach ($urls as $url) {
    echo "  <url>\n";
    echo '    <loc>' . htmlspecialchars($url['loc'], ENT_XML1 | ENT_COMPAT, 'UTF-8') . "</loc>\n";
    echo '    <lastmod>' . htmlspecialchars($url['lastmod'] ?? $today, ENT_XML1 | ENT_COMPAT, 'UTF-8') . "</lastmod>\n";
    echo "    <changefreq>daily</changefreq>\n";
    echo '    <priority>' . htmlspecialchars($url['priority'], ENT_XML1 | ENT_COMPAT, 'UTF-8') . "</priority>\n";
    echo "  </url>\n";
}
echo "</urlset>\n";

function hop_sitemap_clean($value): ?string
{
    if ($value === null) {
        return null;
    }

    $string = trim((string) $value);

    return $string === '' ? null : $string;
}

function hop_sitemap_slug(string $value): string
{
    $value = strtr($value, [
        'ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n', 'ó' => 'o', 'ś' => 's', 'ż' => 'z', 'ź' => 'z',
        'Ą' => 'A', 'Ć' => 'C', 'Ę' => 'E', 'Ł' => 'L', 'Ń' => 'N', 'Ó' => 'O', 'Ś' => 'S', 'Ż' => 'Z', 'Ź' => 'Z',
    ]);
    $value = strtolower($value);
    $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
    $value = trim($value, '-');

    return $value !== '' ? $value : 'pociag';
}

function hop_sitemap_unique_urls(array $urls): array
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
