<?php

declare(strict_types=1);

require __DIR__ . '/api_path.php';

$pageLocale = 'pl';
$hopTranslationsPath = hop_public_api_path('lang/' . $pageLocale . '.php');
$hopTranslations = $hopTranslationsPath !== null ? require $hopTranslationsPath : [];
if (!is_array($hopTranslations)) {
    $hopTranslations = [];
}
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

function hop_page_service_options(array $services): array
{
    return array_map(static function (array $service): array {
        return [
            'slug' => hop_page_service_slug($service),
            'label' => hop_page_service_label($service),
        ];
    }, $services);
}

function hop_page_canonical_url(?array $service = null): string
{
    $baseUrl = 'https://hop.kolej.live/';

    if ($service === null) {
        return $baseUrl;
    }

    return $baseUrl . '?historia_opoznien=' . rawurlencode(hop_page_service_slug($service));
}

function hop_page_service_url(array $service): string
{
    return hop_page_canonical_url($service);
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
        $excludeSql = 'AND tr.service_key NOT IN (' . implode(',', array_fill(0, count($excluded), '?')) . ')';
    }

    $fallbackStmt = $pdo->prepare(
        "SELECT
           tr.service_key,
           MAX(tr.label) AS label,
           MAX(tr.train_number) AS train_number,
           MAX(tr.category) AS category,
           MAX(tr.origin_name) AS origin_name,
           MAX(tr.destination_name) AS destination_name,
           COUNT(DISTINCT tr.operating_date) AS days_count,
           MAX(tr.operating_date) AS last_date
         FROM hop_train_runs tr
         JOIN hop_station_observations obs ON obs.train_run_id = tr.id
         WHERE obs.observation_date = CURDATE() - INTERVAL 1 DAY
           AND (tr.label REGEXP '^(EIC|EIP|IC|TLK)([[:space:]]|$)' OR tr.category IN ('EIC', 'EIP', 'IC', 'TLK'))
           $excludeSql
         GROUP BY tr.service_key
         ORDER BY RAND()
         LIMIT $needed"
    );
    $fallbackStmt->execute($excluded);

    return array_merge($services, $fallbackStmt->fetchAll());
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
           ) obs
         ) d
         JOIN hop_train_runs tr ON tr.id = d.train_run_id
         WHERE (tr.label REGEXP '^(EIC|EIP|IC|TLK)([[:space:]]|$)' OR tr.category IN ('EIC', 'EIP', 'IC', 'TLK'))
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

try {
    if ($error !== null) {
        throw new RuntimeException($error);
    }

    $pdo = hop_pdo();
    hop_page_ensure_search_table($pdo);
    $services = hop_page_services($pdo);
    $requestedSlug = isset($_GET['historia_opoznien']) && !is_array($_GET['historia_opoznien']) ? trim((string) $_GET['historia_opoznien']) : '';
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
?>
<!doctype html>
<html lang="<?= e($pageLocale) ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e(hop_t('hop.meta.title')) ?></title>
  <link rel="canonical" href="<?= e(hop_page_canonical_url($selectedService)) ?>">
  <style>
    :root {
      color-scheme: light;
      font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
      --ink: #111317;
      --muted: #667085;
      --line: #dce1e6;
      --soft: #f6f7f8;
      --red: #c7222a;
      --green: #087a54;
      --amber: #b7791f;
    }
    * { box-sizing: border-box; }
    body { margin: 0; color: var(--ink); background: #fff; }
    .shell { width: min(1280px, calc(100% - 28px)); margin: 0 auto; padding: 24px 0 40px; }
    .top { display: flex; justify-content: space-between; gap: 16px; align-items: center; padding-bottom: 18px; border-bottom: 1px solid var(--line); }
    .brand { display: flex; align-items: center; gap: 14px; font-size: 22px; font-weight: 820; }
    .brand-logo { width: 196px; height: auto; display: block; }
    .brand span { max-width: 360px; line-height: 1.15; }
    .parent-service { display: grid; justify-items: end; gap: 2px; color: var(--muted); font-size: 12px; font-weight: 720; }
    .parent-service a { color: var(--ink); text-decoration: none; font-size: 16px; font-weight: 820; }
    .parent-service a:hover { color: var(--red); }
    .hero { display: grid; gap: 10px; padding: 24px 0 18px; }
    h1 { margin: 0; font-size: clamp(28px, 4vw, 44px); line-height: 1; letter-spacing: 0; }
    p { margin: 0; color: var(--muted); line-height: 1.5; }
    .panel { padding: 16px; border: 1px solid var(--line); border-radius: 8px; background: #fff; box-shadow: 0 18px 48px rgba(22, 30, 42, .08); }
    .controls { display: grid; grid-template-columns: minmax(260px, 1fr); gap: 12px; align-items: end; margin-bottom: 14px; }
    label { display: grid; gap: 6px; color: var(--muted); font-size: 12px; font-weight: 800; text-transform: uppercase; }
    input, button { font: inherit; }
    .service-search { width: 100%; min-height: 42px; padding: 0 12px; color: var(--ink); background: #fff; border: 1px solid var(--line); border-radius: 8px; font-weight: 700; }
    .service-search:focus { outline: 2px solid rgba(199, 34, 42, .18); border-color: var(--red); }
    button { min-height: 42px; padding: 0 14px; border: 0; border-radius: 8px; color: #fff; background: var(--red); cursor: pointer; font-weight: 780; }
    .metrics { display: grid; grid-template-columns: repeat(5, minmax(120px, 1fr)); gap: 8px; margin: 12px 0 16px; }
    .metric { padding: 12px; background: var(--soft); border: 1px solid var(--line); border-radius: 8px; }
    .metric span { display: block; color: var(--muted); font-size: 12px; font-weight: 800; text-transform: uppercase; }
    .metric strong { display: block; margin-top: 4px; font-size: 20px; }
    .table-wrap { overflow: auto; border: 1px solid var(--line); border-radius: 8px; }
    table { width: 100%; min-width: 780px; border-collapse: collapse; }
    th, td { padding: 10px 12px; border-bottom: 1px solid var(--line); text-align: center; white-space: nowrap; }
    th { position: sticky; top: 0; z-index: 1; background: var(--soft); color: #3b424d; font-size: 12px; text-transform: uppercase; }
    th:first-child, td:first-child { position: sticky; left: 0; z-index: 2; text-align: left; background: #fff; }
    th:first-child { background: var(--soft); z-index: 3; }
    tbody tr > td { border-bottom: 2px solid #c5ccd5; }
    .station { min-width: 260px; font-weight: 760; }
    td.observation { min-width: 138px; padding: 0; vertical-align: middle; }
    .stop-cell { display: grid; min-width: 132px; }
    .stop-line { display: grid; grid-template-columns: 46px minmax(76px, 1fr); align-items: center; min-height: 28px; border-bottom: 1px solid #edf0f3; }
    .stop-line:last-child { border-bottom: 0; }
    .stop-kind { color: var(--ink); font-size: 13px; text-align: right; padding-right: 8px; }
    .cell { display: inline-flex; min-width: 76px; min-height: 22px; align-items: center; justify-content: center; border-radius: 6px; font-weight: 820; }
    .cell.ok { color: var(--green); background: #e7f6ef; }
    .cell.late { color: #7a4d00; background: #fff4df; }
    .cell.bad { color: #94191f; background: #fff1f2; }
    .cell.critical { color: #fff; background: #7f1d1d; }
    .cell.cancelled { color: #94191f; background: #fff1f2; }
    .cell.unknown { color: #596273; background: #eef1f4; }
    .cell.empty { background: transparent; }
    .hint, .error { padding: 14px; border-radius: 8px; line-height: 1.45; }
    .hint { color: #475467; background: var(--soft); }
    .error { color: #94191f; background: #fff1f2; border: 1px solid #f1b7bb; }
    .popular-panel, .delay-panel { padding: 16px; border: 1px solid var(--line); border-radius: 8px; background: #fff; }
    .popular-panel { margin-top: 18px; }
    .delay-panels { display: grid; grid-template-columns: 1fr; gap: 20px; margin-top: 18px; }
    .popular-panel h2, .delay-panel h2 { margin: 0 0 12px; font-size: 18px; line-height: 1.2; }
    .service-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px; }
    .delay-panel .service-grid { grid-template-columns: 1fr; }
    .service-card { min-height: 74px; display: grid; align-content: space-between; gap: 8px; padding: 12px; color: var(--ink); text-decoration: none; border: 1px solid var(--line); border-radius: 8px; background: var(--soft); font-weight: 760; }
    .service-card:hover { border-color: rgba(199, 34, 42, .45); box-shadow: 0 8px 22px rgba(22, 30, 42, .08); }
    .service-card-name { line-height: 1.25; }
    .service-card-value { color: var(--red); font-weight: 860; white-space: nowrap; }
    @media (max-width: 820px) {
      .top, .controls { grid-template-columns: 1fr; align-items: stretch; }
      .top { display: grid; }
      .brand-logo { width: 154px; }
      .metrics { grid-template-columns: 1fr 1fr; }
      .delay-panels { grid-template-columns: 1fr; }
    }
  </style>
</head>
<body>
  <main class="shell">
    <header class="top">
      <div class="brand">
        <img class="brand-logo" src="/kolej-live-logo.svg" alt="<?= e(hop_t('hop.logo_alt')) ?>">
        <span><?= e(hop_t('hop.brand.name')) ?></span>
      </div>
      <div class="parent-service">
        <span><?= e(hop_t('hop.parent_service.note')) ?></span>
        <a href="https://kolej.live/"><?= e(hop_t('hop.parent_service.link')) ?></a>
      </div>
    </header>

    <section class="hero">
      <h1><?= e(hop_t('hop.hero.title')) ?></h1>
      <p><?= e(hop_t('hop.hero.body')) ?></p>
    </section>

    <?php if ($error !== null): ?>
      <div class="error"><?= e(hop_t('hop.errors.data_read', ['details' => $error])) ?></div>
    <?php elseif ($selected === null): ?>
      <section class="panel">
        <form class="controls" method="get" data-service-form>
          <label>
            <?= e(hop_t('hop.search.label')) ?>
            <input
              class="service-search"
              id="service-search"
              name="historia_opoznien_query"
              type="search"
              list="service-options"
              autocomplete="off"
              placeholder="<?= e(hop_t('hop.search.placeholder')) ?>"
              value=""
              data-service-search
            >
            <input type="hidden" name="historia_opoznien" value="" data-service-slug>
            <datalist id="service-options">
            </datalist>
          </label>
        </form>
        <div class="hint"><?= e(hop_t('hop.empty_hint')) ?></div>
      </section>
    <?php else: ?>
      <section class="panel">
        <form class="controls" method="get" data-service-form>
          <label>
            <?= e(hop_t('hop.search.label')) ?>
            <input
              class="service-search"
              id="service-search"
              name="historia_opoznien_query"
              type="search"
              list="service-options"
              autocomplete="off"
              placeholder="<?= e(hop_t('hop.search.placeholder')) ?>"
              value=""
              data-service-search
            >
            <input type="hidden" name="historia_opoznien" value="<?= e($selectedService !== null ? hop_page_service_slug($selectedService) : '') ?>" data-service-slug>
            <datalist id="service-options">
            </datalist>
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
          <div class="table-wrap">
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

    <?php if ($error === null): ?>
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
  </main>
  <script>
    (function () {
      var form = document.querySelector('[data-service-form]');
      if (!form) {
        return;
      }

      var input = form.querySelector('[data-service-search]');
      var serviceSlug = form.querySelector('[data-service-slug]');
      var optionList = document.getElementById('service-options');
      var options = <?= json_encode(hop_page_service_options($services), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]' ?>;
      var canonicalBaseUrl = <?= json_encode(hop_page_canonical_url(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '""' ?>;
      var chooseSuggestionMessage = <?= json_encode(hop_t('hop.search.choose_suggestion'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '""' ?>;
      var slugByLabel = {};
      var currentSlug = serviceSlug.value;

      options.forEach(function (option) {
        slugByLabel[option.label] = option.slug || '';
      });

      function normalize(value) {
        return value.toLowerCase();
      }

      function renderSuggestions() {
        var value = input.value.trim();
        optionList.innerHTML = '';

        if (value.length < 3) {
          return;
        }

        var query = normalize(value);
        var rendered = 0;
        options.some(function (option) {
          if (normalize(option.label).indexOf(query) === -1) {
            return false;
          }

          var element = document.createElement('option');
          element.value = option.label;
          optionList.appendChild(element);
          rendered += 1;

          return rendered >= 80;
        });
      }

      function syncServiceSlug() {
        var value = input.value.trim();
        serviceSlug.value = slugByLabel[value] || '';
      }

      function serviceUrl(slug) {
        var url = new URL(canonicalBaseUrl);
        url.searchParams.set('historia_opoznien', slug);
        return url.toString();
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
        navigateWhenReady();
      });
      form.addEventListener('submit', function (event) {
        event.preventDefault();
        navigateWhenReady();

        if (serviceSlug.value === '') {
          input.setCustomValidity(chooseSuggestionMessage);
          input.reportValidity();
        }
      });
    }());
  </script>
</body>
</html>
