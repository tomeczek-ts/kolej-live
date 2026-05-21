<?php

declare(strict_types=1);

require __DIR__ . '/api_path.php';

$pageLocale = 'pl';
$hopTranslationsPath = hop_public_api_path('lang/' . $pageLocale . '.php');
$hopTranslations = $hopTranslationsPath !== null ? require $hopTranslationsPath : [];
if (!is_array($hopTranslations)) {
    $hopTranslations = [];
}
$businessSettingsPath = hop_public_api_path('lib/BusinessSettings.php');
if ($businessSettingsPath !== null) {
    require_once $businessSettingsPath;
}
$googleTagId = function_exists('business_setting') ? (string) business_setting('googleTagId', '') : '';
$bootstrapError = null;
$databasePath = hop_public_api_path('hop/Database.php');
if ($databasePath === null) {
    $bootstrapError = hop_t('hop.errors.api_dir_missing');
} else {
    require $databasePath;
}

function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function hop_t(string $key, array $replacements = []): string
{
    global $hopTranslations;

    $value = (string) ($hopTranslations[$key] ?? $key);

    foreach ($replacements as $name => $replacement) {
        $value = str_replace('{' . $name . '}', (string) $replacement, $value);
    }

    return $value;
}

function hop_page_services(PDO $pdo): array
{
    return $pdo->query(
        "SELECT
           service_key,
           MAX(label) AS label,
           MAX(train_number) AS train_number,
           MAX(category) AS category,
           MAX(origin_name) AS origin_name,
           MAX(destination_name) AS destination_name,
           COUNT(DISTINCT operating_date) AS days_count,
           MAX(operating_date) AS last_date
         FROM hop_train_runs
         GROUP BY service_key
         ORDER BY last_date DESC, label ASC"
    )->fetchAll();
}

function hop_page_service_label(array $service): string
{
    $relation = trim((string) ($service['origin_name'] ?? '') . hop_t('hop.service.relation_separator') . (string) ($service['destination_name'] ?? ''));
    $label = trim((string) ($service['label'] ?? ''));
    $parts = [$label];

    if ($relation !== trim(hop_t('hop.service.relation_separator'))) {
        $parts[] = $relation;
    }

    return implode(hop_t('hop.service.parts_separator'), array_filter($parts));
}

function hop_page_slugify(string $value): string
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

function hop_page_service_slug(array $service): string
{
    return hop_page_slugify((string) ($service['label'] ?? ''));
}

function hop_page_service_key_from_slug(array $services, string $requestedSlug): ?string
{
    $requestedSlug = hop_page_slugify($requestedSlug);

    foreach ($services as $service) {
        if (hop_page_service_slug($service) === $requestedSlug) {
            return (string) $service['service_key'];
        }
    }

    return null;
}

function hop_page_slug_from_path(): string
{
    $path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
    if (!is_string($path)) {
        return '';
    }

    if (preg_match('~^/historia_opoznien/([^/]+)/?$~', $path, $matches) !== 1) {
        return '';
    }

    return trim(rawurldecode($matches[1]));
}

function hop_page_service_options(array $services): array
{
    return array_map(static function (array $service): array {
        return [
            'slug' => hop_page_service_slug($service),
            'label' => hop_page_service_label($service),
        ];
    }, $services);
}

function hop_page_canonical_url(?array $service = null, string $pageKind = 'home'): string
{
    $baseUrl = 'https://hop.kolej.live/';

    if ($pageKind === 'all_trains') {
        return $baseUrl . '?pociagi=wszystkie';
    }

    if ($service === null) {
        return $baseUrl;
    }

    return $baseUrl . 'historia_opoznien/' . rawurlencode(hop_page_service_slug($service));
}

function hop_page_service_url(array $service): string
{
    return hop_page_canonical_url($service);
}

function hop_page_meta(?array $service, array $summary, string $pageKind = 'home'): array
{
    $canonical = hop_page_canonical_url($service, $pageKind);
    $image = 'https://hop.kolej.live/kolej-live-logo.svg';
    $siteName = hop_t('hop.meta.site_name');
    $serviceLabel = $service !== null ? hop_page_service_label($service) : null;

    if ($pageKind === 'all_trains') {
        $title = hop_t('hop.meta.all_trains_title');
        $description = hop_t('hop.meta.all_trains_description');
        $keywords = hop_t('hop.meta.all_trains_keywords');
        $type = 'website';
    } elseif ($serviceLabel !== null) {
        $title = hop_t('hop.meta.service_title', ['service' => $serviceLabel]);
        $description = hop_t('hop.meta.service_description', ['service' => $serviceLabel]);
        $keywords = hop_t('hop.meta.service_keywords', ['service' => $serviceLabel]);
        $type = 'article';
    } else {
        $title = hop_t('hop.meta.title');
        $description = hop_t('hop.meta.description');
        $keywords = hop_t('hop.meta.keywords');
        $type = 'website';
    }

    return [
        'title' => $title,
        'description' => $description,
        'keywords' => $keywords,
        'canonical' => $canonical,
        'image' => $image,
        'siteName' => $siteName,
        'type' => $type,
        'serviceLabel' => $serviceLabel,
        'daysCount' => (int) ($summary['days_count'] ?? 0),
        'observationsCount' => (int) ($summary['observations_count'] ?? 0),
        'avgDelay' => $summary['avg_delay'] ?? null,
        'maxDelay' => $summary['max_delay'] ?? null,
    ];
}

function hop_page_json_ld(array $meta, ?array $service): string
{
    $graph = [
        [
            '@type' => 'WebSite',
            '@id' => 'https://hop.kolej.live/#website',
            'url' => 'https://hop.kolej.live/',
            'name' => $meta['siteName'],
            'description' => hop_t('hop.meta.description'),
            'inLanguage' => 'pl-PL',
            'publisher' => [
                '@type' => 'Organization',
                '@id' => 'https://kolej.live/#organization',
                'name' => 'kolej.live',
                'url' => 'https://kolej.live/',
                'logo' => [
                    '@type' => 'ImageObject',
                    'url' => $meta['image'],
                ],
            ],
            'potentialAction' => [
                '@type' => 'SearchAction',
                'target' => 'https://hop.kolej.live/?historia_opoznien_query={search_term_string}',
                'query-input' => 'required name=search_term_string',
            ],
        ],
        [
            '@type' => $service !== null ? 'Dataset' : 'WebPage',
            '@id' => $meta['canonical'] . '#webpage',
            'url' => $meta['canonical'],
            'name' => $meta['title'],
            'description' => $meta['description'],
            'isPartOf' => ['@id' => 'https://hop.kolej.live/#website'],
            'inLanguage' => 'pl-PL',
            'about' => [
                '@type' => 'Thing',
                'name' => 'Historyczne Opóźnienia Pociągów',
            ],
        ],
    ];

    if ($service !== null) {
        $graph[1]['keywords'] = $meta['keywords'];
        $graph[1]['measurementTechnique'] = 'Daily train delay observations by station';
        $graph[1]['variableMeasured'] = ['arrival_delay_minutes', 'departure_delay_minutes', 'is_cancelled'];
        if ($meta['daysCount'] > 0) {
            $graph[1]['temporalCoverage'] = 'P' . $meta['daysCount'] . 'D';
        }
        $graph[] = [
            '@type' => 'BreadcrumbList',
            '@id' => $meta['canonical'] . '#breadcrumbs',
            'itemListElement' => [
                [
                    '@type' => 'ListItem',
                    'position' => 1,
                    'name' => 'HOP',
                    'item' => 'https://hop.kolej.live/',
                ],
                [
                    '@type' => 'ListItem',
                    'position' => 2,
                    'name' => $meta['serviceLabel'],
                    'item' => $meta['canonical'],
                ],
            ],
        ];
    }

    $json = json_encode(
        ['@context' => 'https://schema.org', '@graph' => $graph],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
    );

    return $json !== false ? $json : '{}';
}

function hop_page_ensure_search_table(PDO $pdo): void
{
    try {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS hop_train_searches (
              id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
              service_key CHAR(40) NOT NULL,
              searched_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (id),
              KEY idx_hop_searches_date_service (searched_at, service_key),
              KEY idx_hop_searches_service (service_key)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    } catch (Throwable $exception) {
        return;
    }
}

function hop_page_ensure_daily_random_table(PDO $pdo): void
{
    try {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS hop_daily_random_services (
              selection_date DATE NOT NULL,
              observation_date DATE NOT NULL,
              position SMALLINT UNSIGNED NOT NULL,
              service_key CHAR(40) NOT NULL,
              generated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (selection_date, position),
              UNIQUE KEY uq_hop_daily_random_service (selection_date, service_key),
              KEY idx_hop_daily_random_observation (observation_date),
              KEY idx_hop_daily_random_service (service_key)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    } catch (Throwable $exception) {
        return;
    }
}

function hop_page_record_search(PDO $pdo, string $serviceKey): void
{
    try {
        $stmt = $pdo->prepare(
            "INSERT INTO hop_train_searches (service_key, searched_at)
             VALUES (:service_key, NOW())"
        );
        $stmt->execute(['service_key' => $serviceKey]);
    } catch (Throwable $exception) {
        return;
    }
}

function hop_page_popular_services(PDO $pdo): array
{
    try {
        $popularStmt = $pdo->query(
            "SELECT
               tr.service_key,
               MAX(tr.label) AS label,
               MAX(tr.train_number) AS train_number,
               MAX(tr.category) AS category,
               MAX(tr.origin_name) AS origin_name,
               MAX(tr.destination_name) AS destination_name,
               COUNT(DISTINCT tr.operating_date) AS days_count,
               MAX(tr.operating_date) AS last_date,
               COUNT(DISTINCT s.id) AS searches_count
             FROM hop_train_searches s
             JOIN hop_train_runs tr ON tr.service_key = s.service_key
             WHERE s.searched_at >= NOW() - INTERVAL 7 DAY
               AND (tr.label REGEXP '^(EIC|EIP|IC|TLK)([[:space:]]|$)' OR tr.category IN ('EIC', 'EIP', 'IC', 'TLK'))
             GROUP BY tr.service_key
             HAVING searches_count >= 5
             ORDER BY searches_count DESC, MAX(s.searched_at) DESC, label ASC
             LIMIT 10"
        );
        $services = $popularStmt->fetchAll();
    } catch (Throwable $exception) {
        $services = [];
    }

    if (count($services) >= 10) {
        return $services;
    }

    $needed = 10 - count($services);
    $excluded = array_column($services, 'service_key');
    $excludeSql = '';
    if ($excluded !== []) {
        $excludeSql = 'AND d.service_key NOT IN (' . implode(',', array_fill(0, count($excluded), '?')) . ')';
    }

    try {
        $selectionDateStmt = $pdo->query(
            "SELECT MAX(selection_date)
             FROM hop_daily_random_services
             WHERE selection_date <= CURDATE()"
        );
        $selectionDate = $selectionDateStmt->fetchColumn();
        if (!is_string($selectionDate) || $selectionDate === '') {
            return $services;
        }

        $fallbackStmt = $pdo->prepare(
            "SELECT
               d.service_key,
               MAX(tr.label) AS label,
               MAX(tr.train_number) AS train_number,
               MAX(tr.category) AS category,
               MAX(tr.origin_name) AS origin_name,
               MAX(tr.destination_name) AS destination_name,
               COUNT(DISTINCT tr.operating_date) AS days_count,
               MAX(tr.operating_date) AS last_date
             FROM hop_daily_random_services d
             JOIN hop_train_runs tr ON tr.service_key = d.service_key
             WHERE d.selection_date = ?
               AND (tr.label REGEXP '^(EIC|EIP|IC|TLK)([[:space:]]|$)' OR tr.category IN ('EIC', 'EIP', 'IC', 'TLK'))
               $excludeSql
             GROUP BY d.service_key, d.position
             ORDER BY d.position ASC
             LIMIT $needed"
        );
        $fallbackStmt->execute(array_merge([$selectionDate], $excluded));

        return array_merge($services, $fallbackStmt->fetchAll());
    } catch (Throwable $exception) {
        return $services;
    }
}

function hop_page_top_delay_services(PDO $pdo, string $period): array
{
    $dateWhere = $period === 'yesterday'
        ? 'obs.observation_date = CURDATE() - INTERVAL 1 DAY'
        : 'obs.observation_date >= CURDATE() - INTERVAL 1 MONTH';

    $stmt = $pdo->query(
        "SELECT
           tr.service_key,
           MAX(tr.label) AS label,
           MAX(tr.train_number) AS train_number,
           MAX(tr.category) AS category,
           MAX(tr.origin_name) AS origin_name,
           MAX(tr.destination_name) AS destination_name,
           COUNT(DISTINCT tr.operating_date) AS days_count,
           MAX(tr.operating_date) AS last_date,
           MAX(d.row_delay) AS max_delay
         FROM (
           SELECT
             obs.train_run_id,
             obs.station_id,
             CASE
               WHEN effective_arrival_delay IS NULL AND effective_departure_delay IS NULL THEN NULL
               WHEN effective_arrival_delay IS NULL THEN effective_departure_delay
               WHEN effective_departure_delay IS NULL THEN effective_arrival_delay
               WHEN effective_arrival_delay >= effective_departure_delay THEN effective_arrival_delay
               ELSE effective_departure_delay
             END AS row_delay
           FROM (
             SELECT
               obs.train_run_id,
               obs.station_id,
               CASE
                 WHEN obs.arrival_delay_minutes IS NULL AND obs.actual_arrival IS NOT NULL THEN 0
                 ELSE obs.arrival_delay_minutes
               END AS effective_arrival_delay,
               CASE
                 WHEN obs.departure_delay_minutes IS NULL AND obs.actual_departure IS NOT NULL THEN 0
                 ELSE obs.departure_delay_minutes
               END AS effective_departure_delay
             FROM hop_station_observations obs
             WHERE $dateWhere
               AND obs.is_cancelled = 0
           ) obs
         ) d
         JOIN hop_train_runs tr ON tr.id = d.train_run_id
         WHERE (tr.label REGEXP '^(EIC|EIP|IC|TLK)([[:space:]]|$)' OR tr.category IN ('EIC', 'EIP', 'IC', 'TLK'))
           AND tr.destination_station_id IS NOT NULL
           AND d.station_id = tr.destination_station_id
         GROUP BY tr.service_key
         HAVING max_delay IS NOT NULL
         ORDER BY max_delay DESC, label ASC
         LIMIT 10"
    );

    return $stmt->fetchAll();
}

function hop_page_dates(PDO $pdo, string $serviceKey): array
{
    $stmt = $pdo->prepare(
        "SELECT DISTINCT o.observation_date
         FROM hop_station_observations o
         JOIN hop_train_runs tr ON tr.id = o.train_run_id
         WHERE tr.service_key = :service_key
         ORDER BY o.observation_date DESC
         LIMIT 14"
    );
    $stmt->execute(['service_key' => $serviceKey]);
    $dates = array_column($stmt->fetchAll(), 'observation_date');
    sort($dates);

    return $dates;
}

function hop_page_cells(PDO $pdo, string $serviceKey, array $dates): array
{
    if ($dates === []) {
        return [];
    }

    $datePlaceholders = implode(',', array_fill(0, count($dates), '?'));
    $stmt = $pdo->prepare(
        "SELECT
           o.station_id,
           s.name AS station_name,
           o.observation_date,
           MIN(o.sequence_number) AS sequence_number,
           MAX(o.actual_arrival) AS actual_arrival,
           MAX(o.actual_departure) AS actual_departure,
           MAX(CASE
             WHEN o.arrival_delay_minutes IS NULL AND o.actual_arrival IS NOT NULL THEN 0
             ELSE o.arrival_delay_minutes
           END) AS arrival_delay_minutes,
           MAX(CASE
             WHEN o.departure_delay_minutes IS NULL AND o.actual_departure IS NOT NULL THEN 0
             ELSE o.departure_delay_minutes
           END) AS departure_delay_minutes,
           MAX(o.is_cancelled) AS is_cancelled,
           MAX(o.is_confirmed) AS is_confirmed
         FROM hop_station_observations o
         JOIN hop_train_runs tr ON tr.id = o.train_run_id
         JOIN hop_stations s ON s.station_id = o.station_id
         WHERE tr.service_key = ?
           AND o.observation_date IN ($datePlaceholders)
         GROUP BY o.station_id, s.name, o.observation_date
         ORDER BY MIN(o.sequence_number), s.name"
    );
    $stmt->execute(array_merge([$serviceKey], $dates));

    return $stmt->fetchAll();
}

function hop_page_summary(PDO $pdo, string $serviceKey): array
{
    $stmt = $pdo->prepare(
        "SELECT
           COUNT(DISTINCT o.operating_date) AS days_count,
           COUNT(o.id) AS observations_count,
           ROUND(AVG(CASE
             WHEN o.effective_arrival_delay IS NULL AND o.effective_departure_delay IS NULL THEN NULL
             WHEN o.effective_arrival_delay IS NULL THEN o.effective_departure_delay
             WHEN o.effective_departure_delay IS NULL THEN o.effective_arrival_delay
             WHEN o.effective_arrival_delay >= o.effective_departure_delay THEN o.effective_arrival_delay
             ELSE o.effective_departure_delay
           END), 1) AS avg_delay,
           MAX(CASE
             WHEN o.effective_arrival_delay IS NULL AND o.effective_departure_delay IS NULL THEN NULL
             WHEN o.effective_arrival_delay IS NULL THEN o.effective_departure_delay
             WHEN o.effective_departure_delay IS NULL THEN o.effective_arrival_delay
             WHEN o.effective_arrival_delay >= o.effective_departure_delay THEN o.effective_arrival_delay
             ELSE o.effective_departure_delay
           END) AS max_delay,
           SUM(o.is_cancelled) AS cancelled_count
         FROM (
           SELECT
             obs.id,
             obs.is_cancelled,
             tr.operating_date,
             CASE
               WHEN obs.arrival_delay_minutes IS NULL AND obs.actual_arrival IS NOT NULL THEN 0
               ELSE obs.arrival_delay_minutes
             END AS effective_arrival_delay,
             CASE
               WHEN obs.departure_delay_minutes IS NULL AND obs.actual_departure IS NOT NULL THEN 0
               ELSE obs.departure_delay_minutes
             END AS effective_departure_delay
           FROM hop_station_observations obs
           JOIN hop_train_runs tr ON tr.id = obs.train_run_id
           WHERE tr.service_key = :service_key
         ) o"
    );
    $stmt->execute(['service_key' => $serviceKey]);

    return $stmt->fetch() ?: [];
}

function hop_page_cell_class(?int $delay, bool $cancelled): string
{
    if ($cancelled) {
        return 'cancelled';
    }

    if ($delay === null) {
        return 'empty';
    }

    if ($delay <= 10) {
        return 'ok';
    }

    if ($delay <= 30) {
        return 'late';
    }

    if ($delay <= 60) {
        return 'bad';
    }

    return 'critical';
}

function hop_page_cell_label(?int $delay, bool $cancelled): string
{
    if ($cancelled) {
        return hop_t('hop.cell.cancelled_short');
    }

    if ($delay === null) {
        return '';
    }

    if ($delay === 0) {
        return hop_t('hop.cell.on_time_short');
    }

    if ($delay > 0) {
        return hop_t('hop.cell.delay_positive', ['minutes' => $delay]);
    }

    return (string) $delay;
}

function hop_page_delay_title_part($delay): string
{
    return $delay !== null ? hop_t('hop.common.minutes', ['minutes' => $delay]) : '';
}

function hop_page_time_label($value): string
{
    if ($value === null || $value === '') {
        return hop_t('hop.common.empty');
    }

    try {
        return (new DateTimeImmutable((string) $value))->format('H:i');
    } catch (Throwable $exception) {
        $raw = trim((string) $value);

        return strlen($raw) >= 5 ? substr($raw, 0, 5) : $raw;
    }
}

function hop_page_event_label($timeValue, ?int $delay, bool $cancelled): string
{
    if ($cancelled) {
        return hop_t('hop.cell.cancelled_short');
    }

    $time = hop_page_time_label($timeValue);
    if ($time === hop_t('hop.common.empty') && $delay === null) {
        return hop_t('hop.common.empty');
    }

    return hop_t('hop.cell.event_format', [
        'time' => $time,
        'delay' => hop_page_cell_label($delay, false),
    ]);
}

$error = $bootstrapError;
$services = [];
$selected = null;
$selectedService = null;
$dates = [];
$rows = [];
$summary = [];
$popularServices = [];
$topYesterday = [];
$topMonth = [];
$showAllServices = isset($_GET['pociagi']) && !is_array($_GET['pociagi']) && (string) $_GET['pociagi'] === 'wszystkie';

try {
    if ($error !== null) {
        throw new RuntimeException($error);
    }

    $pdo = hop_pdo();
    hop_page_ensure_search_table($pdo);
    hop_page_ensure_daily_random_table($pdo);
    $services = hop_page_services($pdo);
    $requestedSlug = hop_page_slug_from_path();
    if ($requestedSlug === '') {
        $requestedSlug = isset($_GET['historia_opoznien']) && !is_array($_GET['historia_opoznien']) ? trim((string) $_GET['historia_opoznien']) : '';
    }
    $legacyServiceKey = isset($_GET['service']) && !is_array($_GET['service']) ? (string) $_GET['service'] : '';
    $serviceKeys = array_column($services, 'service_key');
    $selected = $requestedSlug !== '' ? hop_page_service_key_from_slug($services, $requestedSlug) : null;

    if ($selected === null && in_array($legacyServiceKey, $serviceKeys, true)) {
        $selected = $legacyServiceKey;
    }

    if ($selected === null) {
        $requestedQuery = isset($_GET['historia_opoznien_query']) && !is_array($_GET['historia_opoznien_query'])
            ? trim((string) $_GET['historia_opoznien_query'])
            : '';
        if ($requestedQuery === '') {
            $requestedQuery = isset($_GET['service_query']) && !is_array($_GET['service_query']) ? trim((string) $_GET['service_query']) : '';
        }

        if ($requestedQuery !== '') {
            foreach ($services as $service) {
                if (hop_page_service_label($service) === $requestedQuery) {
                    $selected = (string) $service['service_key'];
                    break;
                }
            }
        }
    }

    if ($selected !== null) {
        foreach ($services as $service) {
            if ($service['service_key'] === $selected) {
                $selectedService = $service;
                break;
            }
        }

        hop_page_record_search($pdo, $selected);

        $dates = hop_page_dates($pdo, $selected);
        $cells = hop_page_cells($pdo, $selected, $dates);
        $summary = hop_page_summary($pdo, $selected);

        foreach ($cells as $cell) {
            $stationId = (int) $cell['station_id'];
            if (!isset($rows[$stationId])) {
                $rows[$stationId] = [
                    'name' => $cell['station_name'],
                    'sequence' => (int) $cell['sequence_number'],
                    'cells' => [],
                ];
            }

            $rows[$stationId]['cells'][(string) $cell['observation_date']] = $cell;
        }
    }

    $popularServices = hop_page_popular_services($pdo);
    $topYesterday = hop_page_top_delay_services($pdo, 'yesterday');
    $topMonth = hop_page_top_delay_services($pdo, 'month');
} catch (Throwable $exception) {
    $error = $exception->getMessage();
}

$pageKind = $showAllServices ? 'all_trains' : 'home';
$pageMeta = hop_page_meta($selectedService, $summary, $pageKind);
$pageJsonLd = hop_page_json_ld($pageMeta, $selectedService);
?>
<!doctype html>
<html lang="<?= e($pageLocale) ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e($pageMeta['title']) ?></title>
  <meta name="description" content="<?= e($pageMeta['description']) ?>">
  <meta name="keywords" content="<?= e($pageMeta['keywords']) ?>">
  <meta name="robots" content="index, follow, max-snippet:-1, max-image-preview:large, max-video-preview:-1">
  <meta name="googlebot" content="index, follow">
  <meta name="application-name" content="HOP">
  <meta name="theme-color" content="#c7222a">
  <link rel="canonical" href="<?= e($pageMeta['canonical']) ?>">
  <link rel="icon" type="image/svg+xml" href="/favicon.svg">
  <link rel="alternate" hreflang="pl-PL" href="<?= e($pageMeta['canonical']) ?>">
  <link rel="alternate" hreflang="x-default" href="<?= e($pageMeta['canonical']) ?>">
  <meta property="og:locale" content="pl_PL">
  <meta property="og:type" content="<?= e($pageMeta['type']) ?>">
  <meta property="og:site_name" content="<?= e($pageMeta['siteName']) ?>">
  <meta property="og:title" content="<?= e($pageMeta['title']) ?>">
  <meta property="og:description" content="<?= e($pageMeta['description']) ?>">
  <meta property="og:url" content="<?= e($pageMeta['canonical']) ?>">
  <meta property="og:image" content="<?= e($pageMeta['image']) ?>">
  <meta name="twitter:card" content="summary">
  <meta name="twitter:title" content="<?= e($pageMeta['title']) ?>">
  <meta name="twitter:description" content="<?= e($pageMeta['description']) ?>">
  <meta name="twitter:image" content="<?= e($pageMeta['image']) ?>">
  <script>
    (function () {
      try {
        document.documentElement.dataset.theme = localStorage.getItem('kolej.live.theme') === 'dark' ? 'dark' : 'light';
        document.documentElement.dataset.accessibility = localStorage.getItem('kolej.live.accessibility') === '1' ? 'true' : 'false';
      } catch (error) {
        document.documentElement.dataset.theme = 'light';
      }
    })();
  </script>
  <?php if ($googleTagId !== ''): ?>
  <script async src="https://www.googletagmanager.com/gtag/js?id=<?= e($googleTagId) ?>"></script>
  <script>
    window.dataLayer = window.dataLayer || [];
    function gtag(){dataLayer.push(arguments);}
    gtag('js', new Date());

    gtag('config', '<?= e($googleTagId) ?>');
  </script>
  <?php endif; ?>
  <script type="application/ld+json">
<?= $pageJsonLd ?>
  </script>
  <style>
    :root {
      color-scheme: light;
      font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
      --bg: #ffffff;
      --ink: #111317;
      --muted: #667085;
      --line: #dce1e6;
      --soft: #f6f7f8;
      --surface: #ffffff;
      --surface-muted: #f9fafb;
      --red: #c7222a;
      --green: #087a54;
      --amber: #b7791f;
      --shadow: 0 18px 48px rgba(22, 30, 42, .08);
      --focus: 0 0 0 3px rgba(199, 34, 42, .22);
    }
    :root[data-theme="dark"] {
      color-scheme: dark;
      --bg: #0d1117;
      --ink: #f5f7fb;
      --muted: #a8b3c2;
      --line: #2c3644;
      --soft: #151b23;
      --surface: #111821;
      --surface-muted: #151d28;
      --red: #ff5b66;
      --green: #55d39a;
      --amber: #ffd166;
      --shadow: 0 22px 64px rgba(0, 0, 0, .34);
      --focus: 0 0 0 3px rgba(255, 91, 102, .32);
    }
    :root[data-accessibility="true"] {
      color-scheme: light;
      --bg: #fffdf0;
      --ink: #050505;
      --muted: #3b3511;
      --line: #6f5d00;
      --soft: #fff3b0;
      --surface: #ffffff;
      --surface-muted: #fff7cb;
      --red: #9b111e;
      --green: #005f3f;
      --amber: #765000;
      --shadow: 0 18px 48px rgba(5, 5, 5, .14);
      --focus: 0 0 0 4px rgba(5, 5, 5, .28);
    }
    :root[data-theme="dark"][data-accessibility="true"] {
      color-scheme: dark;
      --bg: #050505;
      --ink: #fff8c9;
      --muted: #ffe88a;
      --line: #8f7d1f;
      --soft: #111003;
      --surface: #0b0b06;
      --surface-muted: #151303;
      --red: #ffd400;
      --green: #fff08a;
      --amber: #ffd400;
      --shadow: 0 24px 70px rgba(0, 0, 0, .5);
      --focus: 0 0 0 4px rgba(255, 212, 0, .5);
    }
    * { box-sizing: border-box; }
    body { margin: 0; color: var(--ink); background: linear-gradient(180deg, var(--bg), var(--bg) 52%, var(--soft)); }
    .shell { width: min(1280px, calc(100% - 28px)); margin: 0 auto; padding: 24px 0 40px; }
    .top { display: flex; justify-content: space-between; gap: 16px; align-items: center; padding: 10px 0 18px; border-bottom: 1px solid var(--line); }
    .brand { display: flex; align-items: center; gap: 14px; color: var(--ink); text-decoration: none; }
    .brand-logo { width: 196px; height: auto; display: block; }
    :root[data-theme="dark"] .brand-logo { content: url("/kolej-live-logo-dark.svg"); }
    :root[data-theme="dark"][data-accessibility="true"] .brand-logo { content: url("/kolej-live-logo-dark.svg"); }
    .brand span { max-width: 360px; font-size: clamp(15px, 2vw, 18px); line-height: 1.15; font-weight: 760; letter-spacing: 0; }
    .top-actions { display: flex; align-items: center; justify-content: flex-end; gap: 12px; flex-wrap: wrap; }
    .top-link { min-height: 38px; display: inline-flex; align-items: center; justify-content: center; padding: 0 12px; color: var(--ink); text-decoration: none; border: 1px solid var(--line); border-radius: 8px; background: var(--surface); font-size: 13px; font-weight: 820; }
    .top-link:hover { color: var(--red); border-color: rgba(199, 34, 42, .45); }
    .parent-service { display: grid; justify-items: end; gap: 2px; color: var(--muted); font-size: 12px; font-weight: 720; }
    .parent-service a { color: var(--ink); text-decoration: none; font-size: 16px; font-weight: 820; }
    .parent-service a:hover { color: var(--red); }
    .theme-controls { display: inline-flex; gap: 6px; padding: 3px; background: var(--surface); border: 1px solid var(--line); border-radius: 8px; }
    .theme-controls button { width: 34px; min-width: 34px; min-height: 32px; display: inline-grid; place-items: center; padding: 0; color: var(--muted); background: transparent; border-radius: 6px; font-size: 0; font-weight: 760; }
    .theme-controls button svg { width: 17px; height: 17px; color: currentColor; }
    .theme-controls button.active { color: #fff; background: var(--red); }
    .hero { display: grid; gap: 10px; padding: 24px 0 18px; }
    h1 { margin: 0; font-size: clamp(28px, 4vw, 44px); line-height: 1; letter-spacing: 0; }
    p { margin: 0; color: var(--muted); line-height: 1.5; }
    .panel { padding: 16px; border: 1px solid var(--line); border-radius: 8px; background: var(--surface); box-shadow: var(--shadow); }
    .controls { display: grid; grid-template-columns: minmax(260px, 1fr); gap: 12px; align-items: end; margin-bottom: 14px; }
    label { display: grid; gap: 6px; color: var(--muted); font-size: 12px; font-weight: 800; text-transform: uppercase; }
    input, button { font: inherit; }
    .service-search-wrap { position: relative; display: grid; grid-template-columns: 20px minmax(0, 1fr) 42px; align-items: center; gap: 10px; min-height: 52px; padding: 0 6px 0 14px; background: var(--surface); border: 1px solid var(--line); border-radius: 8px; }
    .service-search-wrap svg { color: var(--muted); }
    .service-search { width: 100%; min-height: 42px; padding: 0; color: var(--ink); background: transparent; border: 0; font-weight: 700; outline: 0; }
    .service-search-wrap:focus-within { outline: 2px solid rgba(199, 34, 42, .18); border-color: var(--red); }
    .service-search:focus { outline: 0; }
    .service-search-submit { min-width: 38px; min-height: 38px; display: inline-grid; place-items: center; padding: 0; }
    .service-search-submit svg { color: #fff; }
    .service-suggestions { position: absolute; z-index: 20; top: calc(100% + 8px); right: 0; left: 0; display: grid; max-height: 360px; overflow: auto; padding: 6px; background: var(--surface); border: 1px solid var(--line); border-radius: 8px; box-shadow: var(--shadow); }
    .service-suggestion-row { width: 100%; min-height: 52px; display: grid; grid-template-columns: 20px minmax(0, 1fr); gap: 10px; align-items: center; padding: 9px 10px; color: var(--ink); background: transparent; border: 0; border-radius: 6px; text-align: left; cursor: pointer; }
    .service-suggestion-row:hover, .service-suggestion-row:focus-visible { background: var(--soft); outline: 0; }
    .service-suggestion-row svg { color: var(--red); }
    .service-suggestion-row strong, .service-suggestion-row small { display: block; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .service-suggestion-row strong { font-size: 14px; font-weight: 790; }
    .service-suggestion-row small { color: var(--muted); font-size: 12px; text-transform: none; }
    .service-suggestion-empty { min-height: 48px; display: flex; align-items: center; gap: 8px; padding: 0 10px; color: var(--muted); font-size: 12px; text-transform: none; }
    button { min-height: 42px; padding: 0 14px; border: 0; border-radius: 8px; color: #fff; background: var(--red); cursor: pointer; font-weight: 780; }
    .metrics { display: grid; grid-template-columns: repeat(5, minmax(120px, 1fr)); gap: 8px; margin: 12px 0 16px; }
    .metric { padding: 12px; background: var(--soft); border: 1px solid var(--line); border-radius: 8px; }
    .metric span { display: block; color: var(--muted); font-size: 12px; font-weight: 800; text-transform: uppercase; }
    .metric strong { display: block; margin-top: 4px; font-size: 20px; }
    .table-wrap { overflow: auto; border: 1px solid var(--line); border-radius: 8px; }
    table { width: 100%; min-width: 780px; border-collapse: collapse; }
    th, td { padding: 10px 12px; border-bottom: 1px solid var(--line); text-align: center; white-space: nowrap; }
    th { position: sticky; top: 0; z-index: 1; background: var(--soft); color: #3b424d; font-size: 12px; text-transform: uppercase; }
    th:first-child, td:first-child { position: sticky; left: 0; z-index: 2; text-align: left; background: var(--surface); }
    th:first-child { background: var(--soft); z-index: 3; }
    tbody tr > td { border-bottom: 2px solid #c5ccd5; }
    .station { width: 220px; min-width: 220px; max-width: 220px; font-weight: 760; white-space: normal; line-height: 1.25; }
    td.observation { min-width: 138px; padding: 0; vertical-align: middle; }
    .stop-cell { display: grid; min-width: 132px; }
    .stop-line { display: grid; grid-template-columns: 46px minmax(76px, 1fr); align-items: center; min-height: 28px; border-bottom: 1px solid #edf0f3; }
    .stop-line:last-child { border-bottom: 0; }
    .stop-kind { color: var(--ink); font-size: 13px; text-align: right; padding-right: 8px; }
    .cell { display: inline-flex; min-width: 76px; min-height: 22px; align-items: center; justify-content: center; border-radius: 6px; font-weight: 820; }
    .cell.ok { color: var(--green); background: color-mix(in srgb, var(--green) 14%, var(--surface)); }
    .cell.late { color: var(--amber); background: color-mix(in srgb, var(--amber) 15%, var(--surface)); }
    .cell.bad { color: var(--red); background: color-mix(in srgb, var(--red) 12%, var(--surface)); }
    .cell.critical { color: #fff; background: #7f1d1d; }
    .cell.cancelled { color: var(--red); background: color-mix(in srgb, var(--red) 12%, var(--surface)); }
    .cell.unknown { color: var(--muted); background: var(--soft); }
    .cell.empty { background: transparent; }
    .hint, .error { padding: 14px; border-radius: 8px; line-height: 1.45; }
    .hint { color: #475467; background: var(--soft); }
    .error { color: var(--red); background: color-mix(in srgb, var(--red) 10%, var(--surface)); border: 1px solid color-mix(in srgb, var(--red) 40%, var(--line)); }
    .popular-panel, .delay-panel { padding: 16px; border: 1px solid var(--line); border-radius: 8px; background: var(--surface); box-shadow: var(--shadow); }
    .popular-panel { margin-top: 18px; }
    .delay-panels { display: grid; grid-template-columns: 1fr; gap: 20px; margin-top: 18px; }
    .popular-panel h2, .delay-panel h2 { margin: 0 0 12px; font-size: 18px; line-height: 1.2; }
    .service-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px; }
    .service-card { min-height: 74px; display: grid; align-content: space-between; gap: 8px; padding: 12px; color: var(--ink); text-decoration: none; border: 1px solid var(--line); border-radius: 8px; background: var(--soft); font-weight: 760; }
    .service-card:hover { border-color: rgba(199, 34, 42, .45); box-shadow: 0 8px 22px rgba(22, 30, 42, .08); }
    .service-card-name { line-height: 1.25; }
    .all-services-grid { margin-top: 16px; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); }
    .service-card-value { color: var(--red); font-weight: 860; white-space: nowrap; }
    .cookie-notice { position: fixed; z-index: 50; right: 18px; bottom: 18px; width: min(460px, calc(100% - 36px)); display: grid; grid-template-columns: minmax(0, 1fr) auto; gap: 14px; align-items: center; padding: 14px; color: var(--ink); background: var(--surface); border: 1px solid var(--line); border-radius: 8px; box-shadow: var(--shadow); }
    .cookie-notice strong { display: block; margin-bottom: 3px; font-size: 14px; }
    .cookie-notice p { margin: 0; font-size: 13px; line-height: 1.35; }
    .cookie-notice button { white-space: nowrap; }
    .cookie-notice[hidden] { display: none; }
    button:focus-visible, a:focus-visible, input:focus-visible { outline: 0; box-shadow: var(--focus); }
    :root[data-accessibility="true"] body { font-size: 18px; }
    :root[data-accessibility="true"] .theme-controls button { width: 46px; min-width: 46px; }
    :root[data-accessibility="true"] button,
    :root[data-accessibility="true"] .service-search { min-height: 46px; }
    @media (max-width: 820px) {
      .top, .controls { grid-template-columns: 1fr; align-items: stretch; }
      .top { display: grid; }
      .top-actions { justify-content: start; }
      .brand-logo { width: 154px; }
      .metrics { grid-template-columns: 1fr 1fr; }
      .delay-panels { grid-template-columns: 1fr; }
      th.station, td.station { width: 136px; min-width: 136px; max-width: 136px; padding: 8px; font-size: 13px; }
      .cookie-notice { right: 10px; bottom: 10px; left: 10px; width: auto; grid-template-columns: 1fr; }
    }
  </style>
</head>
<body>
  <main class="shell">
    <header class="top">
      <a class="brand" href="https://hop.kolej.live/" aria-label="<?= e(hop_t('hop.brand.name')) ?>">
        <img class="brand-logo" src="/kolej-live-logo.svg" alt="<?= e(hop_t('hop.logo_alt')) ?>">
        <span><?= e(hop_t('hop.brand.name')) ?></span>
      </a>
      <div class="top-actions">
        <div class="theme-controls" aria-label="<?= e(hop_t('hop.theme.aria')) ?>">
          <button type="button" data-theme-toggle aria-label="<?= e(hop_t('hop.theme.dark')) ?>" title="<?= e(hop_t('hop.theme.dark')) ?>">
            <svg data-icon="moon" viewBox="0 0 24 24" aria-hidden="true"><path d="M20.3 14.8A8.2 8.2 0 0 1 9.2 3.7 8.8 8.8 0 1 0 20.3 14.8Z" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
            <svg data-icon="sun" viewBox="0 0 24 24" aria-hidden="true" hidden><circle cx="12" cy="12" r="4" fill="none" stroke="currentColor" stroke-width="2"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
          </button>
          <button type="button" data-accessibility-toggle aria-label="<?= e(hop_t('hop.theme.accessibility')) ?>" title="<?= e(hop_t('hop.theme.accessibility')) ?>">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M2.5 12s3.5-6 9.5-6 9.5 6 9.5 6-3.5 6-9.5 6-9.5-6-9.5-6Z" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/><circle cx="12" cy="12" r="3" fill="none" stroke="currentColor" stroke-width="2"/></svg>
          </button>
        </div>
        <a class="top-link" href="https://hop.kolej.live/?pociagi=wszystkie"><?= e(hop_t('hop.nav.all_trains')) ?></a>
        <div class="parent-service">
          <span><?= e(hop_t('hop.parent_service.note')) ?></span>
          <a href="https://kolej.live/"><?= e(hop_t('hop.parent_service.link')) ?></a>
        </div>
      </div>
    </header>

    <section class="hero">
      <h1><?= e(hop_t('hop.hero.title')) ?></h1>
      <p><?= e(hop_t('hop.hero.body')) ?></p>
    </section>

    <?php if ($error !== null): ?>
      <div class="error"><?= e(hop_t('hop.errors.data_read', ['details' => $error])) ?></div>
    <?php elseif ($showAllServices): ?>
      <section class="panel">
        <h2><?= e(hop_t('hop.all_trains.title')) ?></h2>
        <div class="hint"><?= e(hop_t('hop.all_trains.body')) ?></div>
        <?php if ($services === []): ?>
          <div class="hint"><?= e(hop_t('hop.all_trains.empty')) ?></div>
        <?php else: ?>
          <div class="service-grid all-services-grid">
            <?php foreach ($services as $service): ?>
              <a class="service-card" href="<?= e(hop_page_service_url($service)) ?>">
                <span class="service-card-name"><?= e(hop_page_service_label($service)) ?></span>
              </a>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </section>
    <?php elseif ($selected === null): ?>
      <section class="panel">
        <form class="controls" method="get" data-service-form>
          <label>
            <?= e(hop_t('hop.search.label')) ?>
            <div class="service-search-wrap">
              <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m21 21-4.3-4.3M10.8 18a7.2 7.2 0 1 1 0-14.4 7.2 7.2 0 0 1 0 14.4Z" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
              <input
                class="service-search"
                id="service-search"
                name="historia_opoznien_query"
                type="search"
                autocomplete="off"
                placeholder="<?= e(hop_t('hop.search.placeholder')) ?>"
                value=""
                aria-controls="service-suggestions"
                aria-expanded="false"
                data-service-search
              >
              <button class="service-search-submit" type="submit" aria-label="<?= e(hop_t('hop.search.submit')) ?>">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m9 18 6-6-6-6" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/></svg>
              </button>
              <div class="service-suggestions" id="service-suggestions" role="listbox" aria-label="<?= e(hop_t('hop.search.suggestions_aria')) ?>" hidden data-service-suggestions></div>
            </div>
            <input type="hidden" name="historia_opoznien" value="" data-service-slug>
          </label>
        </form>
        <div class="hint"><?= e(hop_t('hop.empty_hint')) ?></div>
      </section>
    <?php else: ?>
      <section class="panel">
        <form class="controls" method="get" data-service-form>
          <label>
            <?= e(hop_t('hop.search.label')) ?>
            <div class="service-search-wrap">
              <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m21 21-4.3-4.3M10.8 18a7.2 7.2 0 1 1 0-14.4 7.2 7.2 0 0 1 0 14.4Z" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
              <input
                class="service-search"
                id="service-search"
                name="historia_opoznien_query"
                type="search"
                autocomplete="off"
                placeholder="<?= e(hop_t('hop.search.placeholder')) ?>"
                value=""
                aria-controls="service-suggestions"
                aria-expanded="false"
                data-service-search
              >
              <button class="service-search-submit" type="submit" aria-label="<?= e(hop_t('hop.search.submit')) ?>">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m9 18 6-6-6-6" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/></svg>
              </button>
              <div class="service-suggestions" id="service-suggestions" role="listbox" aria-label="<?= e(hop_t('hop.search.suggestions_aria')) ?>" hidden data-service-suggestions></div>
            </div>
            <input type="hidden" name="historia_opoznien" value="<?= e($selectedService !== null ? hop_page_service_slug($selectedService) : '') ?>" data-service-slug>
          </label>
        </form>

        <div class="metrics">
          <div class="metric"><span><?= e(hop_t('hop.metrics.train')) ?></span><strong><?= e($selectedService['label'] ?? hop_t('hop.common.empty')) ?></strong></div>
          <div class="metric"><span><?= e(hop_t('hop.metrics.days')) ?></span><strong><?= e($summary['days_count'] ?? 0) ?></strong></div>
          <div class="metric"><span><?= e(hop_t('hop.metrics.observations')) ?></span><strong><?= e($summary['observations_count'] ?? 0) ?></strong></div>
          <div class="metric"><span><?= e(hop_t('hop.metrics.avg_delay')) ?></span><strong><?= e($summary['avg_delay'] ?? hop_t('hop.common.empty')) ?> <?= e(hop_t('hop.common.minute_unit')) ?></strong></div>
          <div class="metric"><span><?= e(hop_t('hop.metrics.max_delay')) ?></span><strong><?= e($summary['max_delay'] ?? hop_t('hop.common.empty')) ?> <?= e(hop_t('hop.common.minute_unit')) ?></strong></div>
        </div>

        <?php if ($dates === [] || $rows === []): ?>
          <div class="hint"><?= e(hop_t('hop.train_empty')) ?></div>
        <?php else: ?>
          <div class="table-wrap" data-history-table>
            <table>
              <thead>
                <tr>
                  <th class="station"><?= e(hop_t('hop.table.station')) ?></th>
                  <?php foreach ($dates as $date): ?>
                    <th><?= e($date) ?></th>
                  <?php endforeach; ?>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($rows as $row): ?>
                  <tr>
                    <td class="station"><?= e($row['name']) ?></td>
                    <?php foreach ($dates as $date): ?>
                      <?php
                        $cell = $row['cells'][$date] ?? null;
                        $cancelled = $cell !== null && (int) ($cell['is_cancelled'] ?? 0) > 0;
                        $arrivalDelay = $cell !== null ? hop_int_or_null($cell['arrival_delay_minutes'] ?? null) : null;
                        $departureDelay = $cell !== null ? hop_int_or_null($cell['departure_delay_minutes'] ?? null) : null;
                        $arrivalClass = hop_page_cell_class($arrivalDelay, $cancelled);
                        $departureClass = hop_page_cell_class($departureDelay, $cancelled);
                        $arrivalTitle = $cell !== null
                          ? hop_t('hop.cell.title_arrival', [
                              'time' => hop_page_time_label($cell['actual_arrival'] ?? null),
                              'delay' => hop_page_delay_title_part($arrivalDelay),
                            ])
                          : '';
                        $departureTitle = $cell !== null
                          ? hop_t('hop.cell.title_departure', [
                              'time' => hop_page_time_label($cell['actual_departure'] ?? null),
                              'delay' => hop_page_delay_title_part($departureDelay),
                            ])
                          : '';
                      ?>
                      <td class="observation">
                        <div class="stop-cell">
                          <div class="stop-line">
                            <span class="stop-kind"><?= e(hop_t('hop.table.arrival_short')) ?></span>
                            <span class="cell <?= e($arrivalClass) ?>" title="<?= e($arrivalTitle) ?>"><?= e($cell !== null ? hop_page_event_label($cell['actual_arrival'] ?? null, $arrivalDelay, $cancelled) : hop_t('hop.common.empty')) ?></span>
                          </div>
                          <div class="stop-line">
                            <span class="stop-kind"><?= e(hop_t('hop.table.departure_short')) ?></span>
                            <span class="cell <?= e($departureClass) ?>" title="<?= e($departureTitle) ?>"><?= e($cell !== null ? hop_page_event_label($cell['actual_departure'] ?? null, $departureDelay, $cancelled) : hop_t('hop.common.empty')) ?></span>
                          </div>
                        </div>
                      </td>
                    <?php endforeach; ?>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </section>
    <?php endif; ?>

    <?php if ($error === null && !$showAllServices): ?>
      <section class="popular-panel" aria-label="<?= e(hop_t('hop.popular.aria')) ?>">
        <h2><?= e(hop_t('hop.popular.title')) ?></h2>
        <?php if ($popularServices === []): ?>
          <div class="hint"><?= e(hop_t('hop.popular.empty')) ?></div>
        <?php else: ?>
          <div class="service-grid">
            <?php foreach ($popularServices as $service): ?>
              <a class="service-card" href="<?= e(hop_page_service_url($service)) ?>">
                <span class="service-card-name"><?= e(hop_page_service_label($service)) ?></span>
              </a>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </section>

      <section class="delay-panels" aria-label="<?= e(hop_t('hop.top_delays.aria')) ?>">
        <div class="delay-panel">
          <h2><?= e(hop_t('hop.top_delays.yesterday_title')) ?></h2>
          <?php if ($topYesterday === []): ?>
            <div class="hint"><?= e(hop_t('hop.top_delays.yesterday_empty')) ?></div>
          <?php else: ?>
            <div class="service-grid">
              <?php foreach ($topYesterday as $service): ?>
                <a class="service-card" href="<?= e(hop_page_service_url($service)) ?>">
                  <span class="service-card-name"><?= e(hop_page_service_label($service)) ?></span>
                  <span class="service-card-value"><?= e(hop_t('hop.common.minutes', ['minutes' => $service['max_delay']])) ?></span>
                </a>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>

        <div class="delay-panel">
          <h2><?= e(hop_t('hop.top_delays.month_title')) ?></h2>
          <?php if ($topMonth === []): ?>
            <div class="hint"><?= e(hop_t('hop.top_delays.month_empty')) ?></div>
          <?php else: ?>
            <div class="service-grid">
              <?php foreach ($topMonth as $service): ?>
                <a class="service-card" href="<?= e(hop_page_service_url($service)) ?>">
                  <span class="service-card-name"><?= e(hop_page_service_label($service)) ?></span>
                  <span class="service-card-value"><?= e(hop_t('hop.common.minutes', ['minutes' => $service['max_delay']])) ?></span>
                </a>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </section>
    <?php endif; ?>
    <aside class="cookie-notice" data-cookie-notice role="dialog" aria-live="polite" aria-label="<?= e(hop_t('hop.cookies.title')) ?>">
      <div>
        <strong><?= e(hop_t('hop.cookies.title')) ?></strong>
        <p><?= e(hop_t('hop.cookies.body')) ?></p>
      </div>
      <button type="button" data-cookie-accept><?= e(hop_t('hop.cookies.accept')) ?></button>
    </aside>
  </main>
  <script>
    (function () {
      var themeToggle = document.querySelector('[data-theme-toggle]');
      var accessibilityToggle = document.querySelector('[data-accessibility-toggle]');
      var cookieNotice = document.querySelector('[data-cookie-notice]');
      var cookieAccept = document.querySelector('[data-cookie-accept]');
      var darkLabel = <?= json_encode(hop_t('hop.theme.dark'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '""' ?>;
      var lightLabel = <?= json_encode(hop_t('hop.theme.light'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '""' ?>;

      function readStorage(key) {
        try {
          return localStorage.getItem(key);
        } catch (error) {
          return null;
        }
      }

      function writeStorage(key, value) {
        try {
          localStorage.setItem(key, value);
        } catch (error) {
          return;
        }
      }

      function syncVisualControls() {
        var dark = document.documentElement.dataset.theme === 'dark';
        var accessible = document.documentElement.dataset.accessibility === 'true';
        if (themeToggle) {
          themeToggle.setAttribute('aria-label', dark ? lightLabel : darkLabel);
          themeToggle.setAttribute('title', dark ? lightLabel : darkLabel);
          themeToggle.classList.toggle('active', dark);
          themeToggle.setAttribute('aria-pressed', dark ? 'true' : 'false');
          var moonIcon = themeToggle.querySelector('[data-icon="moon"]');
          var sunIcon = themeToggle.querySelector('[data-icon="sun"]');
          if (moonIcon && sunIcon) {
            moonIcon.hidden = dark;
            sunIcon.hidden = !dark;
          }
        }
        if (accessibilityToggle) {
          accessibilityToggle.classList.toggle('active', accessible);
          accessibilityToggle.setAttribute('aria-pressed', accessible ? 'true' : 'false');
        }
      }

      if (themeToggle) {
        themeToggle.addEventListener('click', function () {
          var next = document.documentElement.dataset.theme === 'dark' ? 'light' : 'dark';
          document.documentElement.dataset.theme = next;
          writeStorage('kolej.live.theme', next);
          syncVisualControls();
        });
      }

      if (accessibilityToggle) {
        accessibilityToggle.addEventListener('click', function () {
          var enabled = document.documentElement.dataset.accessibility !== 'true';
          document.documentElement.dataset.accessibility = enabled ? 'true' : 'false';
          writeStorage('kolej.live.accessibility', enabled ? '1' : '0');
          syncVisualControls();
        });
      }

      if (cookieNotice && readStorage('kolej.live.cookies') === 'accepted') {
        cookieNotice.hidden = true;
      }

      if (cookieAccept && cookieNotice) {
        cookieAccept.addEventListener('click', function () {
          writeStorage('kolej.live.cookies', 'accepted');
          cookieNotice.hidden = true;
        });
      }

      syncVisualControls();

      var form = document.querySelector('[data-service-form]');
      if (!form) {
        return;
      }

      var input = form.querySelector('[data-service-search]');
      var serviceSlug = form.querySelector('[data-service-slug]');
      var suggestionsBox = form.querySelector('[data-service-suggestions]');
      var options = <?= json_encode(hop_page_service_options($services), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]' ?>;
      var canonicalBaseUrl = <?= json_encode(hop_page_canonical_url(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '""' ?>;
      var chooseSuggestionMessage = <?= json_encode(hop_t('hop.search.choose_suggestion'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '""' ?>;
      var slugByLabel = {};
      var slugByNormalizedLabel = {};
      var currentSlug = serviceSlug.value;
      var historyTable = document.querySelector('[data-history-table]');

      function normalize(value) {
        return value
          .toLowerCase()
          .normalize('NFD')
          .replace(/[\u0300-\u036f]/g, '')
          .replace(/ł/g, 'l');
      }

      options.forEach(function (option) {
        slugByLabel[option.label] = option.slug || '';
        slugByNormalizedLabel[normalize(option.label)] = option.slug || '';
      });

      function matchingSuggestions() {
        var value = input.value.trim();
        if (value.length < 2) {
          return [];
        }

        var query = normalize(value);

        return options
          .filter(function (option) {
            return normalize(option.label).indexOf(query) !== -1;
          })
          .slice(0, 12);
      }

      function hideSuggestions() {
        if (!suggestionsBox) {
          return;
        }

        suggestionsBox.hidden = true;
        input.setAttribute('aria-expanded', 'false');
      }

      function pickSuggestion(option) {
        input.value = option.label;
        serviceSlug.value = option.slug || '';
        hideSuggestions();

        if (serviceSlug.value !== '' && serviceSlug.value !== currentSlug) {
          window.location.href = serviceUrl(serviceSlug.value);
        }
      }

      function renderSuggestions() {
        if (!suggestionsBox) {
          return;
        }

        var matches = matchingSuggestions();
        suggestionsBox.innerHTML = '';

        if (matches.length === 0) {
          hideSuggestions();
          return;
        }

        matches.forEach(function (option, index) {
          var row = document.createElement('button');
          row.type = 'button';
          row.className = 'service-suggestion-row';
          row.setAttribute('role', 'option');
          row.setAttribute('id', 'service-suggestion-' + index);
          row.innerHTML = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 17V7c0-2.2 1.8-4 4-4h8c2.2 0 4 1.8 4 4v10c0 1.7-1.3 3-3 3H7c-1.7 0-3-1.3-3-3Z" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/><path d="M8 7h8M8 12h8M8 20l-2 2M16 20l2 2" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/><circle cx="8" cy="16" r="1.5" fill="currentColor"/><circle cx="16" cy="16" r="1.5" fill="currentColor"/></svg><span><strong></strong><small>HOP</small></span>';
          row.querySelector('strong').textContent = option.label;
          row.addEventListener('mousedown', function (event) {
            event.preventDefault();
          });
          row.addEventListener('click', function () {
            pickSuggestion(option);
          });
          suggestionsBox.appendChild(row);
        });

        suggestionsBox.hidden = false;
        input.setAttribute('aria-expanded', 'true');
      }

      function syncServiceSlug() {
        var value = input.value.trim();
        serviceSlug.value = slugByLabel[value] || slugByNormalizedLabel[normalize(value)] || '';
      }

      function serviceUrl(slug) {
        return canonicalBaseUrl.replace(/\/$/, '') + '/historia_opoznien/' + encodeURIComponent(slug);
      }

      function navigateWhenReady() {
        syncServiceSlug();

        if (serviceSlug.value !== '' && serviceSlug.value !== currentSlug) {
          window.location.href = serviceUrl(serviceSlug.value);
        }
      }

      input.addEventListener('input', function () {
        renderSuggestions();
        input.setCustomValidity('');
      });
      input.addEventListener('focus', function () {
        renderSuggestions();
      });
      input.addEventListener('blur', function () {
        window.setTimeout(hideSuggestions, 160);
      });
      form.addEventListener('submit', function (event) {
        event.preventDefault();
        navigateWhenReady();

        if (serviceSlug.value === '') {
          input.setCustomValidity(chooseSuggestionMessage);
          input.reportValidity();
        }
      });

      if (historyTable) {
        requestAnimationFrame(function () {
          historyTable.scrollLeft = historyTable.scrollWidth;
        });
      }
    }());
  </script>
</body>
</html>
