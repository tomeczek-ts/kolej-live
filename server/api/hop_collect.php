<?php

declare(strict_types=1);

hop_collect_register_shutdown_logger();

require __DIR__ . '/config.php';
require __DIR__ . '/PdpClient.php';
require __DIR__ . '/pdp/operations.php';
require __DIR__ . '/pdp/schedules.php';
require __DIR__ . '/hop/Database.php';
require __DIR__ . '/hop/Repository.php';

$isCli = PHP_SAPI === 'cli';
if (!$isCli) {
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');

    $token = isset($_GET['token']) && !is_array($_GET['token']) ? (string) $_GET['token'] : '';
    if (HOP_COLLECT_TOKEN === '' || !hash_equals(HOP_COLLECT_TOKEN, $token)) {
        hop_collect_log('WARN', 'Forbidden web collection request', [
            'remote_addr' => $_SERVER['REMOTE_ADDR'] ?? null,
            'request_uri' => $_SERVER['REQUEST_URI'] ?? null,
            'token_configured' => HOP_COLLECT_TOKEN !== '',
        ]);
        http_response_code(403);
        echo json_encode(['error' => 'hop_collect_forbidden'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

$date = hop_collect_requested_date($isCli);
$pdo = null;
$runId = null;
$stage = 'init';
$stageContext = [];
$summary = [
    'ok' => false,
    'date' => $date,
    'runId' => null,
    'logFile' => hop_collect_log_file(),
    'sourceGeneratedAt' => null,
    'pagesFetched' => 0,
    'trainsSeen' => 0,
    'trainRunsUpserted' => 0,
    'stationObservationsUpserted' => 0,
    'cacheFilesRemoved' => 0,
];

hop_collect_log('INFO', 'Collection started', [
    'date' => $date,
    'sapi' => PHP_SAPI,
    'cwd' => getcwd(),
]);

try {
    $stage = 'create_pdp_client';
    $stageContext = [
        'api_base_url' => PDP_API_BASE_URL,
        'cache_dir' => PDP_CACHE_DIR,
    ];
    $client = new PdpClient(PDP_API_BASE_URL, PDP_API_KEY, PDP_CACHE_DIR);

    $stage = 'connect_database';
    $stageContext = [
        'db_host' => HOP_DB_HOST,
        'db_name' => HOP_DB_NAME,
        'db_user' => HOP_DB_USER,
    ];
    hop_collect_log('INFO', 'Connecting to MySQL', $stageContext);
    $pdo = hop_pdo();
    hop_collect_log('INFO', 'Connected to MySQL', [
        'db_name' => HOP_DB_NAME,
    ]);

    $stage = 'start_run';
    $stageContext = ['date' => $date];
    $runId = hop_start_run($pdo, $date);
    $summary['runId'] = $runId;
    hop_collect_log('INFO', 'Collection run started', [
        'run_id' => $runId,
        'date' => $date,
    ]);

    $stage = 'load_schedule_map';
    $stageContext = ['date' => $date];
    $scheduleMap = hop_collect_schedule_map($client, $date);
    hop_collect_log('INFO', 'Schedule map loaded', [
        'date' => $date,
        'schedule_keys' => count($scheduleMap),
    ]);

    $page = 1;
    $pageSize = 5000;

    do {
        $stage = 'fetch_operations_page';
        $stageContext = [
            'page' => $page,
            'page_size' => $pageSize,
        ];
        $operations = pdp_operations_all($client, $page, $pageSize);
        $summary['pagesFetched']++;

        if ($summary['sourceGeneratedAt'] === null) {
            $summary['sourceGeneratedAt'] = hop_mysql_datetime($operations['generatedAt'] ?? null);
        }

        $operationsTrains = $operations['trains'] ?? [];
        $pageTrainCount = 0;
        $pageObservationCount = 0;
        hop_collect_log('INFO', 'Operations page fetched', [
            'page' => $page,
            'page_size' => $pageSize,
            'returned_trains' => is_array($operationsTrains) ? count($operationsTrains) : 0,
            'operating_date_counts' => is_array($operationsTrains) ? hop_collect_operation_date_counts($operationsTrains) : [],
            'pagination' => $operations['pagination'] ?? null,
            'generated_at' => $operations['generatedAt'] ?? null,
        ]);

        foreach (($operations['trains'] ?? []) as $operation) {
            if (!is_array($operation) || (string) ($operation['operatingDate'] ?? '') !== $date) {
                continue;
            }

            $summary['trainsSeen']++;
            $pageTrainCount++;
            $stageContext = hop_collect_operation_log_context($operation);

            $stage = 'prepare_train_payload';
            $route = hop_collect_route_for_operation($scheduleMap, $operation);
            $train = hop_collect_train_payload($operation, $route, $date);

            $stage = 'upsert_route_end_stations';
            $stageContext = array_merge($stageContext, hop_collect_train_log_context($train));
            hop_collect_upsert_route_end_stations($pdo, $train);

            $stage = 'upsert_train_run';
            $trainRunId = hop_upsert_train_run($pdo, $train);
            $summary['trainRunsUpserted']++;

            foreach (($operation['stations'] ?? []) as $stop) {
                if (!is_array($stop) || empty($stop['stationId'])) {
                    continue;
                }

                $stationId = (int) $stop['stationId'];
                $stationName = hop_collect_station_name($operations['stations'] ?? [], $route, $stationId);
                $stageContext = array_merge(
                    hop_collect_operation_log_context($operation),
                    hop_collect_train_log_context($train),
                    [
                        'train_run_id' => $trainRunId,
                        'station_id' => $stationId,
                        'station_name' => $stationName,
                        'sequence_number' => (int) ($stop['plannedSequenceNumber'] ?? $stop['actualSequenceNumber'] ?? 0),
                    ]
                );

                $stage = 'upsert_station';
                hop_upsert_station($pdo, $stationId, $stationName);

                $stage = 'upsert_station_observation';
                hop_upsert_station_observation($pdo, hop_collect_observation_payload($date, $runId, $trainRunId, $stationId, $stop));
                $summary['stationObservationsUpserted']++;
                $pageObservationCount++;
            }
        }

        $pagination = $operations['pagination'] ?? [];
        $hasNext = (bool) ($pagination['hasNextPage'] ?? false);
        $totalPages = (int) ($pagination['totalPages'] ?? $page);
        hop_collect_log('INFO', 'Operations page processed', [
            'page' => $page,
            'matched_trains' => $pageTrainCount,
            'station_observations_upserted' => $pageObservationCount,
            'next_page' => $hasNext || $page + 1 <= $totalPages ? $page + 1 : null,
        ]);
        $page++;
    } while ($hasNext || $page <= $totalPages);

    if ($summary['trainsSeen'] === 0 || $summary['stationObservationsUpserted'] === 0) {
        hop_collect_log('WARN', 'Collection finished without expected inserted rows', $summary);
    }

    $stage = 'refresh_daily_random_services';
    $stageContext = ['date' => $date];
    $summary['dailyRandomServices'] = hop_collect_refresh_daily_random_services($pdo, $date);
    hop_collect_log('INFO', 'Daily random services ready', [
        'date' => $date,
        'selection_date' => hop_collect_random_selection_date($date),
        'services' => $summary['dailyRandomServices'],
    ]);

    $stage = 'cleanup_api_cache';
    $stageContext = ['cache_dir' => PDP_CACHE_DIR];
    $summary['cacheFilesRemoved'] = hop_collect_cleanup_api_cache(PDP_CACHE_DIR);
    hop_collect_log('INFO', 'Temporary PDP cache cleaned', [
        'cache_dir' => PDP_CACHE_DIR,
        'files_removed' => $summary['cacheFilesRemoved'],
    ]);

    $stage = 'finish_run';
    $stageContext = [
        'run_id' => $runId,
        'summary' => $summary,
    ];
    hop_finish_run($pdo, $runId, $summary);
    $summary['ok'] = true;
    hop_collect_log('INFO', 'Collection finished successfully', $summary);
} catch (Throwable $exception) {
    $summary['error'] = $exception->getMessage();
    $summary['failedStage'] = $stage;
    hop_collect_log('ERROR', 'Collection failed', [
        'stage' => $stage,
        'stage_context' => $stageContext,
        'exception' => hop_collect_exception_payload($exception),
        'summary' => $summary,
    ]);

    if ($pdo instanceof PDO && is_int($runId)) {
        try {
            hop_fail_run($pdo, $runId, $exception->getMessage());
        } catch (Throwable $failException) {
            hop_collect_log('ERROR', 'Could not mark collection run as failed', [
                'run_id' => $runId,
                'exception' => hop_collect_exception_payload($failException),
            ]);
        }
    } else {
        hop_collect_log('WARN', 'Skipped database failure status update', [
            'pdo_available' => $pdo instanceof PDO,
            'run_id' => $runId,
        ]);
    }

    if (!$isCli) {
        http_response_code(500);
    }
}

echo json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
echo $isCli ? PHP_EOL : '';

function hop_collect_register_shutdown_logger(): void
{
    register_shutdown_function(static function (): void {
        $error = error_get_last();
        if (!is_array($error)) {
            return;
        }

        $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
        if (!in_array((int) ($error['type'] ?? 0), $fatalTypes, true)) {
            return;
        }

        hop_collect_log('ERROR', 'Fatal shutdown error', [
            'type' => $error['type'] ?? null,
            'message' => $error['message'] ?? null,
            'file' => $error['file'] ?? null,
            'line' => $error['line'] ?? null,
        ]);
    });
}

function hop_collect_log_file(): string
{
    return __DIR__ . '/hop/logs/hop_collect.log';
}

function hop_collect_log(string $level, string $message, array $context = []): void
{
    $logFile = hop_collect_log_file();
    $logDir = dirname($logFile);
    hop_collect_ensure_log_directory($logDir);

    $entry = [
        'time' => (new DateTimeImmutable('now', new DateTimeZone('Europe/Warsaw')))->format('Y-m-d H:i:s'),
        'level' => strtoupper($level),
        'message' => $message,
    ];

    if ($context !== []) {
        $entry['context'] = hop_collect_redact_log_context($context);
    }

    $line = json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR) . PHP_EOL;
    if (@file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX) === false) {
        error_log('[hop_collect] ' . $line);
    }
}

function hop_collect_ensure_log_directory(string $logDir): void
{
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0775, true);
    }

    $htaccess = $logDir . '/.htaccess';
    if (!is_file($htaccess)) {
        @file_put_contents($htaccess, "Require all denied\nDeny from all\n");
    }

    $index = $logDir . '/index.html';
    if (!is_file($index)) {
        @file_put_contents($index, '');
    }
}

function hop_collect_redact_log_context($value)
{
    if (is_array($value)) {
        $redacted = [];
        foreach ($value as $key => $item) {
            if (hop_collect_should_redact_log_key((string) $key)) {
                $redacted[$key] = '[redacted]';
                continue;
            }

            $redacted[$key] = hop_collect_redact_log_context($item);
        }

        return $redacted;
    }

    if (is_object($value)) {
        return '[object ' . get_class($value) . ']';
    }

    if (is_string($value) && strlen($value) > 2000) {
        return substr($value, 0, 2000) . '...[truncated]';
    }

    return $value;
}

function hop_collect_should_redact_log_key(string $key): bool
{
    return (bool) preg_match('/password|passwd|secret|authorization|api[_-]?key|(^|[_-])token($|[_-]?value)/i', $key);
}

function hop_collect_exception_payload(Throwable $exception): array
{
    return [
        'type' => get_class($exception),
        'message' => $exception->getMessage(),
        'code' => $exception->getCode(),
        'file' => $exception->getFile(),
        'line' => $exception->getLine(),
        'trace' => $exception->getTraceAsString(),
    ];
}

function hop_collect_cleanup_api_cache(string $cacheDir): int
{
    if (!is_dir($cacheDir)) {
        return 0;
    }

    $removed = 0;
    $now = time();
    $files = glob(rtrim($cacheDir, '/\\') . '/*.json');
    if (!is_array($files)) {
        return 0;
    }

    foreach ($files as $file) {
        $basename = basename($file);
        if ($basename === 'runtime-settings.json') {
            continue;
        }

        if (!preg_match('/^(negative-)?[a-f0-9]{40}\.json$/', $basename)) {
            continue;
        }

        $raw = file_get_contents($file);
        $decoded = $raw !== false ? json_decode($raw, true) : null;
        $expiresAt = is_array($decoded) ? (int) ($decoded['expiresAt'] ?? 0) : 0;
        $mtime = (int) (@filemtime($file) ?: 0);
        $expired = $expiresAt > 0 && $expiresAt < $now;
        $staleWithoutExpiry = $expiresAt <= 0 && $mtime > 0 && $mtime < $now - 86400;

        if (($expired || $staleWithoutExpiry) && @unlink($file)) {
            $removed++;
        }
    }

    return $removed;
}

function hop_collect_operation_log_context(array $operation): array
{
    return [
        'operating_date' => $operation['operatingDate'] ?? null,
        'schedule_id' => (int) ($operation['scheduleId'] ?? 0),
        'order_id' => (int) ($operation['orderId'] ?? 0),
        'train_order_id' => (int) ($operation['trainOrderId'] ?? 0),
        'train_status' => $operation['trainStatus'] ?? null,
    ];
}

function hop_collect_train_log_context(array $train): array
{
    return [
        'label' => $train['label'] ?? null,
        'train_number' => $train['train_number'] ?? null,
        'category' => $train['category'] ?? null,
        'origin_station_id' => $train['origin_station_id'] ?? null,
        'destination_station_id' => $train['destination_station_id'] ?? null,
    ];
}

function hop_collect_operation_date_counts(array $operations): array
{
    $counts = [];
    foreach ($operations as $operation) {
        if (!is_array($operation)) {
            continue;
        }

        $date = (string) ($operation['operatingDate'] ?? '[missing]');
        $counts[$date] = ($counts[$date] ?? 0) + 1;
    }

    arsort($counts);

    return array_slice($counts, 0, 20, true);
}

function hop_collect_requested_date(bool $isCli): string
{
    $value = null;

    if ($isCli) {
        global $argv;
        foreach (($argv ?? []) as $argument) {
            if (strpos($argument, '--date=') === 0) {
                $value = substr($argument, 7);
                break;
            }
        }
    } else {
        $value = isset($_GET['date']) && !is_array($_GET['date']) ? (string) $_GET['date'] : null;
    }

    if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        return $value;
    }

    return (new DateTimeImmutable('now', new DateTimeZone('Europe/Warsaw')))->format('Y-m-d');
}

function hop_collect_refresh_daily_random_services(PDO $pdo, string $observationDate): int
{
    hop_collect_ensure_daily_random_table($pdo);

    $selectionDate = hop_collect_random_selection_date($observationDate);
    $existingStmt = $pdo->prepare(
        "SELECT COUNT(*)
         FROM hop_daily_random_services
         WHERE selection_date = :selection_date"
    );
    $existingStmt->execute(['selection_date' => $selectionDate]);
    $existing = (int) $existingStmt->fetchColumn();
    if ($existing > 0) {
        return $existing;
    }

    $selectStmt = $pdo->prepare(
        "SELECT tr.service_key
         FROM hop_train_runs tr
         JOIN hop_station_observations obs ON obs.train_run_id = tr.id
         WHERE obs.observation_date = :observation_date
           AND (tr.label REGEXP '^(EIC|EIP|IC|TLK)([[:space:]]|$)' OR tr.category IN ('EIC', 'EIP', 'IC', 'TLK'))
         GROUP BY tr.service_key
         ORDER BY RAND()
         LIMIT 10"
    );
    $selectStmt->execute(['observation_date' => $observationDate]);
    $serviceKeys = $selectStmt->fetchAll(PDO::FETCH_COLUMN);
    if ($serviceKeys === []) {
        return 0;
    }

    $insertStmt = $pdo->prepare(
        "INSERT INTO hop_daily_random_services (selection_date, observation_date, position, service_key, generated_at)
         VALUES (:selection_date, :observation_date, :position, :service_key, NOW())
         ON DUPLICATE KEY UPDATE service_key = VALUES(service_key), generated_at = VALUES(generated_at)"
    );

    $position = 1;
    foreach ($serviceKeys as $serviceKey) {
        $insertStmt->execute([
            'selection_date' => $selectionDate,
            'observation_date' => $observationDate,
            'position' => $position,
            'service_key' => $serviceKey,
        ]);
        $position++;
    }

    return $position - 1;
}

function hop_collect_ensure_daily_random_table(PDO $pdo): void
{
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
}

function hop_collect_random_selection_date(string $observationDate): string
{
    return (new DateTimeImmutable($observationDate, new DateTimeZone('Europe/Warsaw')))
        ->modify('+1 day')
        ->format('Y-m-d');
}

function hop_collect_schedule_map(PdpClient $client, string $date): array
{
    $schedules = pdp_schedules_for_day($client, $date);
    $stationDict = $schedules['dictionaries']['stations'] ?? [];
    $map = [];

    foreach (($schedules['routes'] ?? []) as $route) {
        if (!is_array($route)) {
            continue;
        }

        $summary = hop_collect_route_summary($route, $stationDict, $date);
        foreach ([
            (int) ($route['orderId'] ?? 0),
            (int) ($route['trainOrderId'] ?? 0),
        ] as $orderId) {
            if ($summary['scheduleId'] > 0 && $orderId > 0) {
                $map[$summary['scheduleId'] . '|' . $orderId] = $summary;
            }
        }
    }

    return $map;
}

function hop_collect_route_for_operation(array $scheduleMap, array $operation): ?array
{
    $scheduleId = (int) ($operation['scheduleId'] ?? 0);
    foreach ([(int) ($operation['orderId'] ?? 0), (int) ($operation['trainOrderId'] ?? 0)] as $orderId) {
        $key = $scheduleId . '|' . $orderId;
        if (isset($scheduleMap[$key])) {
            return $scheduleMap[$key];
        }
    }

    return null;
}

function hop_collect_train_payload(array $operation, ?array $route, string $date): array
{
    $scheduleId = (int) ($operation['scheduleId'] ?? 0);
    $orderId = (int) ($operation['orderId'] ?? 0);
    $trainOrderId = (int) ($operation['trainOrderId'] ?? 0);
    $label = hop_clean($route['label'] ?? null) ?? ('Pociag ' . $scheduleId . '/' . $orderId);
    $serviceParts = [
        hop_clean($route['category'] ?? null),
        hop_clean($route['number'] ?? null),
        hop_clean($route['name'] ?? null),
        hop_clean($route['originName'] ?? null),
        hop_clean($route['destinationName'] ?? null),
    ];

    return [
        'operating_date' => $date,
        'schedule_id' => $scheduleId,
        'order_id' => $orderId,
        'train_order_id' => $trainOrderId,
        'service_key' => sha1(implode('|', array_map(static fn($value): string => (string) $value, $serviceParts)) ?: ($scheduleId . '|' . $orderId)),
        'label' => $label,
        'train_number' => hop_clean($route['number'] ?? null),
        'category' => hop_clean($route['category'] ?? null),
        'train_name' => hop_clean($route['name'] ?? null),
        'carrier_code' => hop_clean($route['carrierCode'] ?? null),
        'origin_station_id' => hop_int_or_null($route['originStationId'] ?? null),
        'destination_station_id' => hop_int_or_null($route['destinationStationId'] ?? null),
        'origin_name' => hop_clean($route['originName'] ?? null),
        'destination_name' => hop_clean($route['destinationName'] ?? null),
        'train_status' => hop_clean($operation['trainStatus'] ?? null),
        'station_count' => (int) ($route['stationCount'] ?? count($operation['stations'] ?? [])),
        'first_departure' => hop_mysql_datetime($route['firstDeparture'] ?? null),
        'last_arrival' => hop_mysql_datetime($route['lastArrival'] ?? null),
    ];
}

function hop_collect_upsert_route_end_stations(PDO $pdo, array $train): void
{
    if (!empty($train['origin_station_id']) && !empty($train['origin_name'])) {
        hop_upsert_station($pdo, (int) $train['origin_station_id'], (string) $train['origin_name']);
    }

    if (!empty($train['destination_station_id']) && !empty($train['destination_name'])) {
        hop_upsert_station($pdo, (int) $train['destination_station_id'], (string) $train['destination_name']);
    }
}

function hop_collect_observation_payload(string $date, int $runId, int $trainRunId, int $stationId, array $stop): array
{
    $actualArrival = hop_mysql_datetime($stop['actualArrival'] ?? null);
    $actualDeparture = hop_mysql_datetime($stop['actualDeparture'] ?? null);
    $arrivalDelay = hop_collect_delay_or_zero_when_actual(hop_int_or_null($stop['arrivalDelayMinutes'] ?? null), $actualArrival);
    $departureDelay = hop_collect_delay_or_zero_when_actual(hop_int_or_null($stop['departureDelayMinutes'] ?? null), $actualDeparture);
    $knownDelays = array_values(array_filter([$arrivalDelay, $departureDelay], static fn($value): bool => $value !== null));

    return [
        'observation_date' => $date,
        'run_id' => $runId,
        'train_run_id' => $trainRunId,
        'station_id' => $stationId,
        'sequence_number' => (int) ($stop['plannedSequenceNumber'] ?? $stop['actualSequenceNumber'] ?? 0),
        'planned_arrival' => hop_mysql_datetime($stop['plannedArrival'] ?? null),
        'planned_departure' => hop_mysql_datetime($stop['plannedDeparture'] ?? null),
        'actual_arrival' => $actualArrival,
        'actual_departure' => $actualDeparture,
        'arrival_delay_minutes' => $arrivalDelay,
        'departure_delay_minutes' => $departureDelay,
        'max_delay_minutes' => $knownDelays !== [] ? max($knownDelays) : null,
        'is_confirmed' => hop_bool_int($stop['isConfirmed'] ?? false),
        'is_cancelled' => hop_bool_int($stop['isCancelled'] ?? false),
    ];
}

function hop_collect_delay_or_zero_when_actual(?int $delay, ?string $actualTime): ?int
{
    if ($delay !== null) {
        return $delay;
    }

    return $actualTime !== null ? 0 : null;
}

function hop_collect_route_summary(array $route, array $stationDict, string $date): array
{
    $stations = $route['stations'] ?? [];
    usort($stations, static fn(array $a, array $b): int => ((int) ($a['orderNumber'] ?? 0)) <=> ((int) ($b['orderNumber'] ?? 0)));

    $first = $stations[0] ?? null;
    $last = $stations !== [] ? $stations[count($stations) - 1] : null;
    $number = hop_collect_route_number($route);
    $category = hop_clean($route['commercialCategorySymbol'] ?? null);
    $name = hop_clean($route['name'] ?? null);
    $labelParts = array_values(array_filter([$category, $number, $name]));
    $originId = $first !== null ? (int) ($first['stationId'] ?? 0) : null;
    $destinationId = $last !== null ? (int) ($last['stationId'] ?? 0) : null;

    return [
        'scheduleId' => (int) ($route['scheduleId'] ?? 0),
        'orderId' => (int) ($route['orderId'] ?? 0),
        'trainOrderId' => (int) ($route['trainOrderId'] ?? 0),
        'label' => $labelParts !== [] ? implode(' ', $labelParts) : ('Pociag ' . ($number ?? '')),
        'number' => $number,
        'category' => $category,
        'name' => $name,
        'carrierCode' => hop_clean($route['carrierCode'] ?? null),
        'originStationId' => $originId,
        'destinationStationId' => $destinationId,
        'originName' => $originId !== null ? hop_collect_name_from_schedule_dict($stationDict, $originId) : null,
        'destinationName' => $destinationId !== null ? hop_collect_name_from_schedule_dict($stationDict, $destinationId) : null,
        'stationCount' => count($stations),
        'stations' => $stations,
        'stationNames' => hop_collect_station_names_from_route($stations, $stationDict),
        'firstDeparture' => $first !== null ? hop_collect_planned_datetime($date, $first['departureTime'] ?? $first['arrivalTime'] ?? null, $first['departureDay'] ?? $first['arrivalDay'] ?? 0) : null,
        'lastArrival' => $last !== null ? hop_collect_planned_datetime($date, $last['arrivalTime'] ?? $last['departureTime'] ?? null, $last['arrivalDay'] ?? $last['departureDay'] ?? 0) : null,
    ];
}

function hop_collect_station_names_from_route(array $stations, array $stationDict): array
{
    $names = [];
    foreach ($stations as $station) {
        $stationId = (int) ($station['stationId'] ?? 0);
        if ($stationId > 0) {
            $names[(string) $stationId] = hop_collect_name_from_schedule_dict($stationDict, $stationId) ?? ('Stacja ' . $stationId);
        }
    }

    return $names;
}

function hop_collect_station_name(array $operationStationDict, ?array $route, int $stationId): string
{
    $fromOperation = $operationStationDict[(string) $stationId] ?? null;
    if (is_string($fromOperation) && trim($fromOperation) !== '') {
        return trim($fromOperation);
    }

    $fromRoute = $route['stationNames'][(string) $stationId] ?? null;
    if (is_string($fromRoute) && trim($fromRoute) !== '') {
        return trim($fromRoute);
    }

    return 'Stacja ' . $stationId;
}

function hop_collect_route_number(array $route): ?string
{
    foreach (['nationalNumber', 'internationalDepartureNumber', 'internationalArrivalNumber'] as $key) {
        $value = hop_clean($route[$key] ?? null);
        if ($value !== null) {
            return $value;
        }
    }

    foreach (($route['stations'] ?? []) as $station) {
        foreach (['departureTrainNumber', 'arrivalTrainNumber'] as $key) {
            $value = hop_clean($station[$key] ?? null);
            if ($value !== null) {
                return $value;
            }
        }
    }

    return null;
}

function hop_collect_name_from_schedule_dict(array $dictionary, int $stationId): ?string
{
    $entry = $dictionary[(string) $stationId] ?? null;
    if (is_array($entry)) {
        return hop_clean($entry['name'] ?? null);
    }

    return is_string($entry) ? hop_clean($entry) : null;
}

function hop_collect_planned_datetime(string $date, $timeValue, $dayOffset = 0): ?string
{
    if ($timeValue === null || $timeValue === '') {
        return null;
    }

    $parsed = hop_collect_parse_time((string) $timeValue);
    if ($parsed === null) {
        return null;
    }

    $minutes = ((int) $dayOffset * 24 * 60) + ($parsed['days'] * 24 * 60) + ($parsed['hours'] * 60) + $parsed['minutes'];
    $base = new DateTimeImmutable($date . ' 00:00:00', new DateTimeZone('Europe/Warsaw'));

    return $base->modify('+' . $minutes . ' minutes')->format(DateTimeInterface::ATOM);
}

function hop_collect_parse_time(string $value): ?array
{
    $value = trim($value);
    if (preg_match('/^(?:(\d+)\.)?(\d{1,3}):(\d{2})(?::(\d{2})(?:\.\d+)?)?$/', $value, $matches)) {
        return [
            'days' => isset($matches[1]) && $matches[1] !== '' ? (int) $matches[1] : 0,
            'hours' => (int) $matches[2],
            'minutes' => (int) $matches[3],
        ];
    }

    if (preg_match('/^P(?:(\d+)D)?T?(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?$/i', $value, $matches)) {
        return [
            'days' => isset($matches[1]) && $matches[1] !== '' ? (int) $matches[1] : 0,
            'hours' => isset($matches[2]) && $matches[2] !== '' ? (int) $matches[2] : 0,
            'minutes' => isset($matches[3]) && $matches[3] !== '' ? (int) $matches[3] : 0,
        ];
    }

    return null;
}
