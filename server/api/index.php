<?php

declare(strict_types=1);

require __DIR__ . '/config.php';
require __DIR__ . '/PdpClient.php';
require __DIR__ . '/lib/DataFiles.php';
require __DIR__ . '/lib/Translations.php';
require __DIR__ . '/lib/StationCoordinates.php';
require __DIR__ . '/pdp/stations.php';
require __DIR__ . '/pdp/schedules.php';
require __DIR__ . '/pdp/operations.php';
require __DIR__ . '/pdp/disruptions.php';
require __DIR__ . '/pdp/dictionaries.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, private');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    respond(405, ['error' => ['code' => 'method_not_allowed', 'message' => 'Dozwolone sa tylko zapytania GET.']]);
}

$publicAction = isset($_GET['action']) && !is_array($_GET['action']) ? trim((string) $_GET['action']) : 'search';
if ($publicAction === 'translations') {
    respond(200, translationsEndpoint());
}
if ($publicAction === 'seo_links') {
    respond(200, seoLinksEndpoint());
}
if ($publicAction === 'track_link') {
    respond(200, trackLinkEndpoint());
}

if (PDP_API_KEY === '' || strpos(PDP_API_KEY, 'WSTAW_') === 0) {
    respond(500, [
        'error' => [
            'code' => 'missing_api_key',
            'message' => 'Brak klucza PDP API. Ustaw PDP_API_KEY w server/api/config.php przed wdrozeniem.',
        ],
    ]);
}

$client = new PdpClient(PDP_API_BASE_URL, PDP_API_KEY, PDP_CACHE_DIR);

try {
    $action = queryString('action', 'search');

    switch ($action) {
        case 'search':
            respond(200, searchEndpoint($client));
            break;
        case 'stations':
            respond(200, stationsEndpoint($client));
            break;
        case 'nearby_stations':
            respond(200, nearbyStationsEndpoint($client));
            break;
        case 'suggest':
            respond(200, suggestEndpoint($client));
            break;
        case 'translations':
            respond(200, translationsEndpoint());
            break;
        case 'seo_links':
            respond(200, seoLinksEndpoint());
            break;
        case 'track_link':
            respond(200, trackLinkEndpoint());
            break;
        case 'station':
            respond(200, stationEndpoint($client));
            break;
        case 'train':
            respond(200, trainEndpoint($client));
            break;
        case 'trains':
            respond(200, trainsEndpoint($client));
            break;
        case 'disruptions':
            respond(200, disruptionsEndpoint($client));
            break;
        case 'stats':
            respond(200, statsEndpoint($client));
            break;
        case 'version':
            respond(200, pdp_data_version($client));
            break;
        default:
            respond(404, ['error' => ['code' => 'unknown_action', 'message' => 'Nieznana akcja API.']]);
    }
} catch (PdpApiException $exception) {
    respond(httpStatusForException($exception), [
        'error' => [
            'code' => 'pdp_api_error',
            'message' => $exception->getMessage(),
            'details' => $exception->payload(),
        ],
    ]);
} catch (Throwable $exception) {
    respond(500, [
        'error' => [
            'code' => 'server_error',
            'message' => 'Wystapil blad po stronie kolej.live.',
            'details' => $exception->getMessage(),
        ],
    ]);
}

function searchEndpoint(PdpClient $client): array
{
    $query = trim(queryString('q', ''));
    $mode = queryString('mode', 'auto');
    $date = queryDate('date');

    if (mb_strlen_safe($query) < 2) {
        return [
            'query' => $query,
            'date' => $date,
            'stations' => [],
            'trains' => [],
            'warnings' => [],
        ];
    }

    $stations = [];
    $trains = [];
    $warnings = [];

    if ($mode === 'auto' || $mode === 'station') {
        $stations = stationSuggestions($client, $query, 10);
    }

    if ($mode === 'auto' || $mode === 'train') {
        try {
            $trains = trainSuggestions($client, $query, $date, 14);
        } catch (Throwable $exception) {
            $warnings[] = 'Nie udalo sie pobrac listy pociagow: ' . $exception->getMessage();
        }
    }

    return [
        'query' => $query,
        'date' => $date,
        'stations' => $stations,
        'trains' => $trains,
        'warnings' => $warnings,
        'generatedAt' => gmdate(DATE_ATOM),
    ];
}

function stationsEndpoint(PdpClient $client): array
{
    $query = trim(queryString('q', ''));

    return [
        'query' => $query,
        'stations' => stationSuggestions($client, $query, queryInt('limit', 20, 1, 50)),
        'generatedAt' => gmdate(DATE_ATOM),
    ];
}

function nearbyStationsEndpoint(PdpClient $client): array
{
    $latitude = queryFloat('lat');
    $longitude = queryFloat('lon');
    $limit = queryInt('limit', 8, 1, 20);

    if ($latitude === null || $longitude === null || !station_coordinates_are_valid($latitude, $longitude)) {
        respond(422, [
            'error' => [
                'code' => 'location_required',
                'message' => 'Brakuje poprawnych wspolrzednych lokalizacji.',
            ],
        ]);
    }

    $stations = stationsWithCoordinates($client);
    $items = [];

    foreach ($stations as $station) {
        $distanceKm = haversineDistanceKm($latitude, $longitude, (float) $station['latitude'], (float) $station['longitude']);
        $items[] = [
            'id' => (int) $station['id'],
            'name' => (string) $station['name'],
            'distanceKm' => round($distanceKm, 1),
        ];
    }

    usort($items, static fn(array $a, array $b): int => ($a['distanceKm'] <=> $b['distanceKm']) ?: strcmp($a['name'], $b['name']));

    return [
        'latitude' => $latitude,
        'longitude' => $longitude,
        'stations' => array_slice($items, 0, $limit),
        'warnings' => $items === [] ? ['Brak wspolrzednych stacji w lokalnym cache.'] : [],
        'generatedAt' => gmdate(DATE_ATOM),
    ];
}

function translationsEndpoint(): array
{
    $locale = translations_normalize_locale(queryString('lang', translations_default_locale()));

    return [
        'locale' => $locale,
        'texts' => translations_for_locale($locale),
        'generatedAt' => gmdate(DATE_ATOM),
    ];
}

function seoLinksEndpoint(): array
{
    $recent = recentSeoLinks(4);
    $random = randomSeoLinks(6, array_column($recent, 'href'));

    return [
        'links' => array_slice(array_merge($recent, $random), 0, 10),
        'recent' => $recent,
        'random' => $random,
        'generatedAt' => gmdate(DATE_ATOM),
    ];
}

function trackLinkEndpoint(): array
{
    $type = queryString('type');
    $label = cleanNullable(queryString('label'));
    $href = canonicalTrackedHref(queryString('href'));
    $slug = cleanNullable(queryString('slug'));
    $subtitle = cleanNullable(queryString('subtitle'));

    if (!in_array($type, ['station', 'train'], true) || $label === null || $href === null) {
        return ['ok' => false, 'generatedAt' => gmdate(DATE_ATOM)];
    }

    if ($type === 'station' && !isPublicStationName($label)) {
        return ['ok' => false, 'generatedAt' => gmdate(DATE_ATOM)];
    }

    $link = [
        'type' => $type,
        'label' => truncateText($label, 120),
        'slug' => $slug !== null ? seoSlug($slug) : seoSlug($label),
        'href' => $href,
        'subtitle' => $type === 'station' ? 'Stacja' : ($subtitle !== null ? truncateText($subtitle, 140) : seoSlug($label)),
        'source' => 'recent',
        'lastSeenAt' => gmdate(DATE_ATOM),
    ];

    $payload = data_read_json('recent-searches.json');
    $items = is_array($payload['items'] ?? null) ? $payload['items'] : [];
    $deduped = [$link];

    foreach ($items as $item) {
        if (!is_array($item) || ($item['href'] ?? null) === $href) {
            continue;
        }

        if (($item['type'] ?? null) === 'station' && !isPublicStationName((string) ($item['label'] ?? ''))) {
            continue;
        }

        $deduped[] = $item;
        if (count($deduped) >= 80) {
            break;
        }
    }

    data_write_json('recent-searches.json', [
        'generatedAt' => gmdate(DATE_ATOM),
        'items' => $deduped,
    ]);

    return ['ok' => true, 'generatedAt' => gmdate(DATE_ATOM)];
}

function suggestEndpoint(PdpClient $client): array
{
    $query = trim(queryString('q', ''));
    $date = queryDate('date');
    $limit = queryInt('limit', 10, 1, 25);

    if (mb_strlen_safe($query) < 2) {
        return [
            'query' => $query,
            'date' => $date,
            'suggestions' => [],
            'generatedAt' => gmdate(DATE_ATOM),
        ];
    }

    $suggestions = [];

    foreach (stationSuggestions($client, $query, $limit) as $station) {
        $suggestions[] = [
            'type' => 'station',
            'label' => $station['name'],
            'subtitle' => 'Stacja',
            'value' => $station['name'],
            'stationId' => $station['id'],
        ];
    }

    foreach (trainSuggestions($client, $query, $date, $limit) as $train) {
        $suggestions[] = [
            'type' => 'train',
            'label' => $train['label'],
            'subtitle' => trainSuggestionSubtitle($train),
            'value' => $train['label'],
            'scheduleId' => $train['scheduleId'],
            'orderId' => $train['orderId'],
            'operationOrderId' => $train['operationOrderId'],
            'operatingDate' => $train['operatingDate'],
        ];
    }

    foreach (dictionarySuggestions($client, $query, max(3, (int) floor($limit / 2))) as $item) {
        $suggestions[] = $item;
    }

    return [
        'query' => $query,
        'date' => $date,
        'suggestions' => array_slice($suggestions, 0, $limit * 3),
        'generatedAt' => gmdate(DATE_ATOM),
    ];
}

function stationEndpoint(PdpClient $client): array
{
    $stationId = queryInt('id', 0, 1, 999999999);
    $date = queryDate('date');

    if ($stationId <= 0) {
        respond(422, ['error' => ['code' => 'station_required', 'message' => 'Brakuje parametru id stacji.']]);
    }

    $schedules = pdp_schedules_for_station($client, $stationId, $date);
    $operations = pdp_operations_for_station($client, $stationId);
    $disruptions = pdp_disruptions($client, $date, $stationId);

    $stationName = stationNameFromScheduleDictionary($schedules['dictionaries']['stations'] ?? [], $stationId)
        ?? stationNameFromOperationsDictionary($operations['stations'] ?? [], $stationId)
        ?? ('Stacja ' . $stationId);

    return [
        'station' => [
            'id' => $stationId,
            'name' => $stationName,
        ],
        'date' => $date,
        'board' => buildStationBoard($schedules, $operations, $stationId, $date),
        'disruptions' => normalizeDisruptions($disruptions),
        'generatedAt' => $operations['generatedAt'] ?? $schedules['generatedAt'] ?? gmdate(DATE_ATOM),
    ];
}

function trainEndpoint(PdpClient $client): array
{
    $scheduleId = queryInt('scheduleId', 0, 1, 999999999);
    $orderId = queryInt('orderId', 0, 1, 999999999);
    $operationOrderId = queryInt('operationOrderId', $orderId, 1, 999999999);
    $date = queryDate('operatingDate', queryDate('date'));

    if ($scheduleId <= 0 || $orderId <= 0) {
        respond(422, ['error' => ['code' => 'train_required', 'message' => 'Brakuje identyfikatorów pociągu.']]);
    }

    $route = pdp_schedule_route($client, $scheduleId, $orderId);
    $operation = pdp_operation_train($client, $scheduleId, $operationOrderId, $date);
    $stationMap = stationDictionaryMap($client);

    return [
        'train' => routeSummary($route, $stationMap, $date),
        'operationOrderId' => $operationOrderId,
        'date' => $date,
        'status' => statusInfo($operation['trainStatus'] ?? null),
        'timeline' => buildTrainTimeline($route, $operation, $stationMap, $date),
        'generatedAt' => gmdate(DATE_ATOM),
    ];
}

function trainsEndpoint(PdpClient $client): array
{
    $date = queryDate('date');
    $kind = queryString('kind', 'all');
    if (!in_array($kind, ['all', 'running', 'cancelled'], true)) {
        $kind = 'all';
    }

    $trains = trainsForDay($client, $date);

    if ($kind !== 'all') {
        $trains = trainsByOperationStatus($client, $date, $trains, $kind);
    }

    usort($trains, static function (array $a, array $b): int {
        return strcmp((string) ($a['firstDeparture'] ?? ''), (string) ($b['firstDeparture'] ?? ''));
    });

    return [
        'date' => $date,
        'kind' => $kind,
        'trains' => array_values($trains),
        'generatedAt' => gmdate(DATE_ATOM),
    ];
}

function statsEndpoint(PdpClient $client): array
{
    $date = queryDate('date');
    $stats = pdp_operation_statistics($client, $date);

    return [
        'date' => $date,
        'stats' => $stats,
        'generatedAt' => $stats['generatedAt'] ?? gmdate(DATE_ATOM),
    ];
}

function disruptionsEndpoint(PdpClient $client): array
{
    $date = queryDate('date');
    $stationId = queryInt('stationId', 0, 0, 999999999);
    $payload = pdp_disruptions($client, $date, $stationId > 0 ? $stationId : null);

    return [
        'date' => $date,
        'stationId' => $stationId > 0 ? $stationId : null,
        'disruptions' => normalizeDisruptions($payload),
        'generatedAt' => $payload['generatedAt'] ?? gmdate(DATE_ATOM),
    ];
}

function stationSuggestions(PdpClient $client, string $query, int $limit): array
{
    $cached = data_find_items('stations.json', $query, max($limit * 3, $limit), ['name']);
    if ($cached !== []) {
        $items = [];

        foreach ($cached as $station) {
            if (!isset($station['id'], $station['name']) || !isPublicStationName((string) $station['name'])) {
                continue;
            }

            $items[] = [
                'id' => (int) $station['id'],
                'name' => (string) $station['name'],
            ];

            if (count($items) >= $limit) {
                break;
            }
        }

        return $items;
    }

    $response = pdp_stations_search($client, $query, min(max($limit, 1), 50));

    $stations = $response['stations'] ?? [];
    $items = [];

    foreach ($stations as $station) {
        if (!isset($station['id'], $station['name']) || !isPublicStationName((string) $station['name'])) {
            continue;
        }

        $items[] = [
            'id' => (int) $station['id'],
            'name' => (string) $station['name'],
        ];
    }

    return $items;
}

function stationsWithCoordinates(PdpClient $client): array
{
    $cachedCoordinates = station_coordinates_cached_items();
    if ($cachedCoordinates !== []) {
        return array_values(array_filter($cachedCoordinates, static fn(array $station): bool => isPublicStationName((string) $station['name'])));
    }

    $payload = data_read_json('stations.json');
    $rows = is_array($payload['items'] ?? null) ? $payload['items'] : [];

    if ($rows === []) {
        $rows = pdp_stations_all($client)['stations'] ?? [];
    }

    $fallbacks = [];
    foreach (station_coordinates_fallbacks() as $fallback) {
        foreach (station_coordinates_name_keys((string) $fallback['name']) as $key) {
            $fallbacks[$key] = [
                'latitude' => (float) $fallback['latitude'],
                'longitude' => (float) $fallback['longitude'],
            ];
        }
    }

    $items = [];
    foreach ($rows as $row) {
        if (!is_array($row) || !isset($row['id'], $row['name'])) {
            continue;
        }

        $name = trim((string) $row['name']);
        if (!isPublicStationName($name)) {
            continue;
        }

        $coordinates = station_coordinates_from_row($row);
        if ($coordinates === null) {
            foreach (station_coordinates_name_keys($name) as $key) {
                if (isset($fallbacks[$key])) {
                    $coordinates = $fallbacks[$key];
                    break;
                }
            }
        }

        if ($coordinates === null) {
            continue;
        }

        $items[] = [
            'id' => (int) $row['id'],
            'name' => $name,
            'latitude' => (float) $coordinates['latitude'],
            'longitude' => (float) $coordinates['longitude'],
        ];
    }

    return $items;
}

function haversineDistanceKm(float $latA, float $lonA, float $latB, float $lonB): float
{
    $earthRadiusKm = 6371.0;
    $latDelta = deg2rad($latB - $latA);
    $lonDelta = deg2rad($lonB - $lonA);
    $a = sin($latDelta / 2) ** 2
        + cos(deg2rad($latA)) * cos(deg2rad($latB)) * sin($lonDelta / 2) ** 2;

    return $earthRadiusKm * 2 * atan2(sqrt($a), sqrt(1 - $a));
}

function trainSuggestions(PdpClient $client, string $query, string $date, int $limit): array
{
    $cached = data_find_items('trains-' . $date . '.json', $query, $limit, ['label', 'name', 'number', 'category', 'carrierCode', 'origin', 'destination']);
    if ($cached !== []) {
        $matches = array_values(array_map(static fn(array $train): array => [
            'scheduleId' => (int) ($train['scheduleId'] ?? 0),
            'orderId' => (int) ($train['orderId'] ?? 0),
            'operationOrderId' => (int) ($train['operationOrderId'] ?? $train['orderId'] ?? 0),
            'trainOrderId' => (int) ($train['trainOrderId'] ?? 0),
            'operatingDate' => (string) ($train['operatingDate'] ?? $date),
            'label' => (string) ($train['label'] ?? ''),
            'name' => cleanNullable($train['name'] ?? null),
            'number' => cleanNullable($train['number'] ?? null),
            'category' => cleanNullable($train['category'] ?? null),
            'carrierCode' => cleanNullable($train['carrierCode'] ?? null),
            'origin' => cleanNullable($train['origin'] ?? null),
            'destination' => cleanNullable($train['destination'] ?? null),
            'stationCount' => (int) ($train['stationCount'] ?? 0),
            'firstDeparture' => cleanNullable($train['firstDeparture'] ?? null),
            'lastArrival' => cleanNullable($train['lastArrival'] ?? null),
        ], array_filter($cached, static fn(array $train): bool => !empty($train['scheduleId']) && !empty($train['orderId']))));

        if (trainSuggestionsNeedTimes($matches)) {
            try {
                return mergeTrainSuggestionTimes($matches, liveTrainSuggestions($client, $query, $date, $limit));
            } catch (Throwable $exception) {
                return $matches;
            }
        }

        return $matches;
    }

    return liveTrainSuggestions($client, $query, $date, $limit);
}

function liveTrainSuggestions(PdpClient $client, string $query, string $date, int $limit): array
{
    $response = pdp_schedules_for_day($client, $date);

    $stationDict = $response['dictionaries']['stations'] ?? [];
    $routes = $response['routes'] ?? [];
    $matches = [];

    foreach ($routes as $route) {
        if (!routeMatchesQuery($route, $query)) {
            continue;
        }

        $matches[] = routeSummary($route, $stationDict, $date);
        if (count($matches) >= $limit) {
            break;
        }
    }

    return $matches;
}

function trainSuggestionsNeedTimes(array $matches): bool
{
    foreach ($matches as $train) {
        if (empty($train['firstDeparture']) || empty($train['lastArrival'])) {
            return true;
        }
    }

    return false;
}

function mergeTrainSuggestionTimes(array $cached, array $live): array
{
    $liveByKey = [];
    foreach ($live as $train) {
        $key = trainSuggestionKey($train);
        if ($key !== null) {
            $liveByKey[$key] = $train;
        }
    }

    return array_values(array_map(static function (array $train) use ($liveByKey): array {
        $key = trainSuggestionKey($train);
        $live = $key !== null ? ($liveByKey[$key] ?? null) : null;
        if ($live === null) {
            return $train;
        }

        foreach (['origin', 'destination', 'firstDeparture', 'lastArrival'] as $field) {
            if (empty($train[$field]) && !empty($live[$field])) {
                $train[$field] = $live[$field];
            }
        }

        return $train;
    }, $cached));
}

function trainSuggestionKey(array $train): ?string
{
    $scheduleId = (int) ($train['scheduleId'] ?? 0);
    $orderId = (int) ($train['orderId'] ?? 0);
    if ($scheduleId <= 0 || $orderId <= 0) {
        return null;
    }

    return $scheduleId . ':' . $orderId;
}

function trainSuggestionSubtitle(array $train): string
{
    $origin = cleanNullable($train['origin'] ?? null) ?? '-';
    $destination = cleanNullable($train['destination'] ?? null) ?? '-';
    $departure = clockFromIso($train['firstDeparture'] ?? null);
    $arrival = clockFromIso($train['lastArrival'] ?? null);

    return trim($origin . ' ' . ($departure ?? '') . ' -> ' . ($arrival ?? '') . ' ' . $destination);
}

function trainsForDay(PdpClient $client, string $date): array
{
    $cached = data_read_json('trains-' . $date . '.json');
    $items = is_array($cached['items'] ?? null) ? $cached['items'] : [];
    if ($items !== []) {
        return array_values(array_map(static fn(array $train): array => normalizeTrainSummary($train, $date), array_filter($items, 'is_array')));
    }

    $response = pdp_schedules_for_day($client, $date);
    $stationDict = $response['dictionaries']['stations'] ?? [];
    $trains = [];

    foreach (($response['routes'] ?? []) as $route) {
        if (is_array($route)) {
            $trains[] = routeSummary($route, $stationDict, $date);
        }
    }

    return $trains;
}

function trainsByOperationStatus(PdpClient $client, string $date, array $scheduledTrains, string $kind): array
{
    $scheduleMap = trainSummaryMap($scheduledTrains);
    $matches = [];
    $page = 1;
    $pageSize = 5000;

    do {
        $operations = pdp_operations_all($client, $page, $pageSize, 45);
        foreach (($operations['trains'] ?? []) as $operation) {
            if (!is_array($operation) || (string) ($operation['operatingDate'] ?? '') !== $date) {
                continue;
            }

            $status = cleanNullable($operation['trainStatus'] ?? null);
            if (!operationStatusMatchesList($status, $kind)) {
                continue;
            }

            $summary = trainSummaryForOperation($scheduleMap, $operation, $date);
            if ($summary !== null) {
                $matches[$summary['scheduleId'] . '-' . $summary['orderId']] = $summary;
            }
        }

        $pagination = $operations['pagination'] ?? [];
        $hasNext = (bool) ($pagination['hasNextPage'] ?? false);
        $totalPages = (int) ($pagination['totalPages'] ?? $page);
        $page++;
    } while ($hasNext || $page <= $totalPages);

    return array_values($matches);
}

function trainSummaryMap(array $trains): array
{
    $map = [];
    foreach ($trains as $train) {
        if (!is_array($train)) {
            continue;
        }

        $summary = normalizeTrainSummary($train, (string) ($train['operatingDate'] ?? todayWarsaw()));
        foreach ([(int) $summary['orderId'], (int) $summary['trainOrderId'], (int) $summary['operationOrderId']] as $orderId) {
            if ($summary['scheduleId'] > 0 && $orderId > 0) {
                $map[$summary['scheduleId'] . '|' . $orderId] = $summary;
            }
        }
    }

    return $map;
}

function trainSummaryForOperation(array $scheduleMap, array $operation, string $date): ?array
{
    $scheduleId = (int) ($operation['scheduleId'] ?? 0);
    foreach ([(int) ($operation['orderId'] ?? 0), (int) ($operation['trainOrderId'] ?? 0)] as $orderId) {
        $key = $scheduleId . '|' . $orderId;
        if (isset($scheduleMap[$key])) {
            $summary = $scheduleMap[$key];
            $summary['operationOrderId'] = $orderId;
            return $summary;
        }
    }

    if ($scheduleId <= 0) {
        return null;
    }

    $orderId = (int) ($operation['orderId'] ?? $operation['trainOrderId'] ?? 0);
    if ($orderId <= 0) {
        return null;
    }

    return [
        'scheduleId' => $scheduleId,
        'orderId' => $orderId,
        'operationOrderId' => (int) ($operation['trainOrderId'] ?? $orderId),
        'trainOrderId' => (int) ($operation['trainOrderId'] ?? 0),
        'operatingDate' => (string) ($operation['operatingDate'] ?? $date),
        'label' => 'Pociag ' . $scheduleId . '/' . $orderId,
        'name' => null,
        'number' => null,
        'category' => null,
        'carrierCode' => null,
        'origin' => null,
        'destination' => null,
        'stationCount' => count($operation['stations'] ?? []),
        'firstDeparture' => null,
        'lastArrival' => null,
    ];
}

function operationStatusMatchesList(?string $status, string $kind): bool
{
    if ($kind === 'running') {
        return $status === 'P';
    }

    if ($kind === 'cancelled') {
        return in_array($status, ['X', 'Q'], true);
    }

    return true;
}

function normalizeTrainSummary(array $train, string $date): array
{
    return [
        'scheduleId' => (int) ($train['scheduleId'] ?? 0),
        'orderId' => (int) ($train['orderId'] ?? 0),
        'operationOrderId' => (int) ($train['operationOrderId'] ?? $train['trainOrderId'] ?? $train['orderId'] ?? 0),
        'trainOrderId' => (int) ($train['trainOrderId'] ?? 0),
        'operatingDate' => (string) ($train['operatingDate'] ?? $date),
        'label' => (string) ($train['label'] ?? ''),
        'name' => cleanNullable($train['name'] ?? null),
        'number' => cleanNullable($train['number'] ?? null),
        'category' => cleanNullable($train['category'] ?? null),
        'carrierCode' => cleanNullable($train['carrierCode'] ?? null),
        'origin' => cleanNullable($train['origin'] ?? null),
        'destination' => cleanNullable($train['destination'] ?? null),
        'stationCount' => (int) ($train['stationCount'] ?? 0),
        'firstDeparture' => cleanNullable($train['firstDeparture'] ?? null),
        'lastArrival' => cleanNullable($train['lastArrival'] ?? null),
    ];
}

function dictionarySuggestions(PdpClient $client, string $query, int $limit): array
{
    $suggestions = [];

    $carriers = data_find_items('carriers.json', $query, $limit, ['code', 'name']);
    if ($carriers === []) {
        $carriers = pdp_carriers($client)['carriers'] ?? [];
        $carriers = filterDictionaryRows($carriers, $query, ['code', 'name'], $limit);
    }

    foreach ($carriers as $carrier) {
        $code = cleanNullable($carrier['code'] ?? null);
        $name = cleanNullable($carrier['name'] ?? null);
        if ($code === null && $name === null) {
            continue;
        }

        $suggestions[] = [
            'type' => 'carrier',
            'label' => $name ?? $code,
            'subtitle' => $code !== null ? 'Przewoznik: ' . $code : 'Przewoznik',
            'value' => $code ?? $name,
        ];
    }

    $categories = data_find_items('commercial-categories.json', $query, $limit, ['code', 'name', 'carrierCode', 'speedCategoryCode']);
    if ($categories === []) {
        $categories = pdp_commercial_categories($client)['commercialCategories'] ?? [];
        $categories = filterDictionaryRows($categories, $query, ['code', 'name', 'carrierCode', 'speedCategoryCode'], $limit);
    }

    foreach ($categories as $category) {
        $code = cleanNullable($category['code'] ?? null);
        $name = cleanNullable($category['name'] ?? null);
        if ($code === null && $name === null) {
            continue;
        }

        $suggestions[] = [
            'type' => 'category',
            'label' => trim(($code ?? '') . ($name !== null ? ' - ' . $name : '')),
            'subtitle' => cleanNullable($category['carrierCode'] ?? null) !== null ? 'Kategoria, ' . $category['carrierCode'] : 'Kategoria handlowa',
            'value' => $code ?? $name,
        ];
    }

    $cities = data_find_items('cities.json', $query, $limit, ['name']);
    if ($cities === []) {
        $cities = pdp_cities($client, $query)['cities'] ?? [];
        $cities = filterDictionaryRows($cities, $query, ['name'], $limit);
    }

    foreach ($cities as $city) {
        $name = cleanNullable($city['name'] ?? null);
        if ($name === null) {
            continue;
        }

        $count = (int) ($city['stationCount'] ?? count($city['stationIds'] ?? []));
        $suggestions[] = [
            'type' => 'city',
            'label' => $name,
            'subtitle' => 'Miasto, stacje: ' . $count,
            'value' => $name,
            'stationIds' => array_values(array_map('intval', $city['stationIds'] ?? [])),
        ];
    }

    return $suggestions;
}

function filterDictionaryRows(array $rows, string $query, array $fields, int $limit): array
{
    $needle = normalizeText($query);
    $matches = [];

    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $haystack = [];
        foreach ($fields as $field) {
            $haystack[] = (string) ($row[$field] ?? '');
        }

        if (strpos(normalizeText(implode(' ', $haystack)), $needle) !== false) {
            $matches[] = $row;
        }

        if (count($matches) >= $limit) {
            break;
        }
    }

    return $matches;
}

function recentSeoLinks(int $limit): array
{
    $payload = data_read_json('recent-searches.json');
    $items = is_array($payload['items'] ?? null) ? $payload['items'] : [];
    $links = [];

    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }

        $type = $item['type'] ?? null;
        $label = cleanNullable($item['label'] ?? null);
        $href = canonicalTrackedHref($item['href'] ?? '');
        if (!in_array($type, ['station', 'train'], true) || $label === null || $href === null) {
            continue;
        }

        if ($type === 'station' && !isPublicStationName($label)) {
            continue;
        }

        $links[] = [
            'type' => $type,
            'label' => $label,
            'slug' => seoSlug($item['slug'] ?? $label),
            'href' => $href,
            'subtitle' => $type === 'station' ? 'Stacja' : (cleanNullable($item['subtitle'] ?? null) ?? seoSlug($label)),
            'source' => 'recent',
        ];

        if (count($links) >= $limit) {
            break;
        }
    }

    return $links;
}

function randomSeoLinks(int $limit, array $excludeHrefs = []): array
{
    $excluded = array_fill_keys(array_filter(array_map('strval', $excludeHrefs)), true);
    $candidates = [];

    $stations = data_read_json('stations.json');
    foreach (($stations['items'] ?? []) as $station) {
        if (!is_array($station)) {
            continue;
        }
        $link = stationSeoLinkFromRow($station, 'random');
        if ($link !== null) {
            $candidates[] = $link;
        }
    }

    $trains = data_read_json('trains-' . todayWarsaw() . '.json');
    foreach (($trains['items'] ?? []) as $train) {
        if (!is_array($train)) {
            continue;
        }
        $link = trainSeoLinkFromRow($train, 'random');
        if ($link !== null) {
            $candidates[] = $link;
        }
    }

    shuffle($candidates);

    $links = [];
    foreach ($candidates as $link) {
        if (isset($excluded[$link['href']])) {
            continue;
        }

        $links[] = $link;
        $excluded[$link['href']] = true;
        if (count($links) >= $limit) {
            break;
        }
    }

    return $links;
}

function stationSeoLinkFromRow(array $station, string $source): ?array
{
    $id = (int) ($station['id'] ?? 0);
    $name = cleanNullable($station['name'] ?? null);
    if ($id <= 0 || $name === null || !isPublicStationName($name)) {
        return null;
    }

    $slug = seoSlug($name);

    return [
        'type' => 'station',
        'label' => $name,
        'slug' => $slug,
        'href' => '/?stacja=' . rawurlencode($slug) . '&id_stacji=' . $id,
        'subtitle' => 'Stacja',
        'source' => $source,
    ];
}

function isPublicStationName(string $name): bool
{
    $name = trim($name);
    if ($name === '' || strpos($name, ' -') !== false || preg_match('/^(stacja|station)\s+\d+$/iu', $name) === 1) {
        return false;
    }

    $lettersOnly = preg_replace('/[^\p{L}]+/u', '', $name) ?? '';
    if ($lettersOnly === '') {
        return false;
    }

    $upper = function_exists('mb_strtoupper') ? mb_strtoupper($name, 'UTF-8') : strtoupper($name);

    return $name !== $upper;
}

function trainSeoLinkFromRow(array $train, string $source): ?array
{
    $label = cleanNullable($train['label'] ?? null);
    if ($label === null) {
        return null;
    }

    $slug = seoSlug($label);
    $number = cleanNullable($train['number'] ?? null) ?? trainNumberFromLabel($label);
    $href = '/?pociag=' . rawurlencode($slug);

    return [
        'type' => 'train',
        'label' => $label,
        'slug' => $slug,
        'href' => $href,
        'subtitle' => $number !== null ? ($number . ' ' . $slug) : $slug,
        'source' => $source,
    ];
}

function buildStationBoard(array $schedules, array $operations, int $stationId, string $date): array
{
    $stationDict = $schedules['dictionaries']['stations'] ?? [];
    $operationMap = operationMap($operations);
    $items = [];

    foreach (($schedules['routes'] ?? []) as $route) {
        $summary = routeSummary($route, $stationDict, $date);
        $operation = firstOperationForRoute($operationMap, $summary);
        $liveStop = $operation ? operationStopForStation($operation, $stationId, $summary['orderNumber'] ?? null) : null;

        foreach (routeStopsAtStation($route, $stationId) as $stop) {
            $arrivalIso = plannedDateTime($date, $stop['arrivalTime'] ?? null, $stop['arrivalDay'] ?? 0);
            $departureIso = plannedDateTime($date, $stop['departureTime'] ?? null, $stop['departureDay'] ?? 0);
            $platform = $stop['departurePlatform'] ?? $stop['arrivalPlatform'] ?? null;
            $track = $stop['departureTrack'] ?? $stop['arrivalTrack'] ?? null;

            if ($arrivalIso !== null) {
                $items[] = boardItem('arrival', $summary, $stationId, $arrivalIso, $platform, $track, $operation, $liveStop, $stop);
            }

            if ($departureIso !== null) {
                $items[] = boardItem('departure', $summary, $stationId, $departureIso, $platform, $track, $operation, $liveStop, $stop);
            }
        }
    }

    usort($items, static function (array $a, array $b): int {
        return strcmp((string) ($a['plannedTime'] ?? ''), (string) ($b['plannedTime'] ?? ''));
    });

    return $items;
}

function boardItem(string $kind, array $summary, int $stationId, string $plannedTime, ?string $platform, ?string $track, ?array $operation, ?array $liveStop, array $plannedStop): array
{
    $delay = null;
    $actualTime = null;

    if ($liveStop !== null) {
        if ($kind === 'departure') {
            $delay = $liveStop['departureDelayMinutes'] ?? null;
            $actualTime = $liveStop['actualDeparture'] ?? null;
        } else {
            $delay = $liveStop['arrivalDelayMinutes'] ?? null;
            $actualTime = $liveStop['actualArrival'] ?? null;
        }
    }

    $delayMinutes = delayMinutesFromPdp($delay, $actualTime, $plannedTime);

    return [
        'id' => $summary['scheduleId'] . '-' . $summary['orderId'] . '-' . $stationId . '-' . $kind . '-' . substr(sha1($plannedTime), 0, 8),
        'kind' => $kind,
        'stationId' => $stationId,
        'scheduleId' => $summary['scheduleId'],
        'orderId' => $summary['orderId'],
        'operationOrderId' => $summary['operationOrderId'],
        'operatingDate' => $summary['operatingDate'],
        'label' => $summary['label'],
        'name' => $summary['name'],
        'number' => $summary['number'],
        'category' => $summary['category'],
        'carrierCode' => $summary['carrierCode'],
        'origin' => $summary['origin'],
        'destination' => $summary['destination'],
        'firstDeparture' => $summary['firstDeparture'] ?? null,
        'lastArrival' => $summary['lastArrival'] ?? null,
        'plannedTime' => $plannedTime,
        'actualTime' => $actualTime,
        'delayMinutes' => $delayMinutes,
        'delayLabel' => delayLabel($delayMinutes),
        'platform' => cleanNullable($platform),
        'track' => cleanNullable($track),
        'stopTypeName' => cleanNullable($plannedStop['stopTypeName'] ?? null),
        'status' => statusInfo($operation['trainStatus'] ?? null),
        'isConfirmed' => (bool) ($liveStop['isConfirmed'] ?? false),
        'isCancelled' => (bool) ($liveStop['isCancelled'] ?? false),
    ];
}

function buildTrainTimeline(array $route, array $operation, array $stationMap, string $date): array
{
    $operationStops = $operation['stations'] ?? [];
    $items = [];

    foreach (sortedRouteStations($route) as $plannedStop) {
        $stationId = (int) ($plannedStop['stationId'] ?? 0);
        $liveStop = operationStopForStation(['stations' => $operationStops], $stationId, $plannedStop['orderNumber'] ?? null);
        $actualArrival = $liveStop['actualArrival'] ?? null;
        $actualDeparture = $liveStop['actualDeparture'] ?? null;
        $plannedArrival = plannedDateTime($date, $plannedStop['arrivalTime'] ?? null, $plannedStop['arrivalDay'] ?? 0);
        $plannedDeparture = plannedDateTime($date, $plannedStop['departureTime'] ?? null, $plannedStop['departureDay'] ?? 0);

        $items[] = [
            'stationId' => $stationId,
            'stationName' => stationNameFromAnyDictionary($stationMap, $stationId) ?? ('Stacja ' . $stationId),
            'orderNumber' => (int) ($plannedStop['orderNumber'] ?? 0),
            'plannedArrival' => $plannedArrival,
            'plannedDeparture' => $plannedDeparture,
            'actualArrival' => $actualArrival,
            'actualDeparture' => $actualDeparture,
            'arrivalDelayMinutes' => delayMinutesFromPdp($liveStop['arrivalDelayMinutes'] ?? null, $actualArrival, $plannedArrival),
            'departureDelayMinutes' => delayMinutesFromPdp($liveStop['departureDelayMinutes'] ?? null, $actualDeparture, $plannedDeparture),
            'platform' => cleanNullable($plannedStop['departurePlatform'] ?? $plannedStop['arrivalPlatform'] ?? null),
            'track' => cleanNullable($plannedStop['departureTrack'] ?? $plannedStop['arrivalTrack'] ?? null),
            'isConfirmed' => (bool) ($liveStop['isConfirmed'] ?? false),
            'isCancelled' => (bool) ($liveStop['isCancelled'] ?? false),
            'stopTypeName' => cleanNullable($plannedStop['stopTypeName'] ?? null),
        ];
    }

    return $items;
}

function normalizeDisruptions(array $response): array
{
    $types = array_merge(cachedDisruptionTypes(), $response['disruptionTypes'] ?? []);
    $stations = $response['stations'] ?? [];
    $items = [];

    foreach (($response['disruptions'] ?? []) as $disruption) {
        $startId = $disruption['startStationId'] ?? null;
        $endId = $disruption['endStationId'] ?? null;
        $startStation = $startId !== null ? stationNameFromAnyDictionary($stations, (int) $startId) : null;
        $endStation = $endId !== null ? stationNameFromAnyDictionary($stations, (int) $endId) : null;
        $typeCode = cleanNullable($disruption['disruptionTypeCode'] ?? null);
        $typeName = normalizeDisruptionType($typeCode !== null ? ($types[$typeCode] ?? null) : null);
        $message = normalizeDisruptionMessage($disruption['message'] ?? null, $startStation, $endStation);

        if ($message === null) {
            continue;
        }

        $items[] = [
            'id' => $disruption['disruptionId'] ?? substr(sha1($message), 0, 10),
            'type' => $typeName ?? 'Utrudnienie',
            'message' => $message,
            'startStation' => $startStation,
            'endStation' => $endStation,
            'affectedRoutesCount' => count($disruption['affectedRoutes'] ?? []),
        ];
    }

    return $items;
}

function cachedDisruptionTypes(): array
{
    $payload = data_read_json('disruption-types.json');
    if (!is_array($payload)) {
        return [];
    }

    $items = $payload['items'] ?? $payload;
    $map = [];

    foreach ($items as $key => $value) {
        if (is_string($key) && is_string($value)) {
            $map[$key] = $value;
            continue;
        }

        if (is_array($value) && isset($value['code'], $value['name'])) {
            $map[(string) $value['code']] = (string) $value['name'];
        }
    }

    return $map;
}

function normalizeDisruptionType($value): ?string
{
    $type = cleanNullable($value);

    if ($type === null || isTechnicalDisruptionText($type)) {
        return null;
    }

    return $type;
}

function normalizeDisruptionMessage($value, ?string $startStation, ?string $endStation): ?string
{
    $message = cleanNullable($value);
    if ($message === null) {
        return null;
    }

    $message = html_entity_decode($message, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $message = trim(preg_replace('/\s+/u', ' ', $message) ?? $message);
    $message = replaceDisruptionPlaceholders($message, $startStation, $endStation);

    if ($message === null) {
        return null;
    }

    $message = trim(preg_replace('/\s+/u', ' ', $message) ?? $message);
    $message = preg_replace('/\s+([,.!?;:])/u', '$1', $message) ?? $message;

    if (mb_strlen_safe($message) <= 10 || isTechnicalDisruptionText($message) || hasUnresolvedDisruptionPlaceholder($message)) {
        return null;
    }

    return $message;
}

function replaceDisruptionPlaceholders(string $message, ?string $startStation, ?string $endStation): ?string
{
    $missingRequiredValue = false;

    $result = preg_replace_callback('/\{([^{}]+)\}/u', static function (array $matches) use ($startStation, $endStation, &$missingRequiredValue): string {
        $key = normalizeText($matches[1]);

        if (in_array($key, ['stacja poczatkowa', 'stacja startowa', 'station start', 'start station'], true)) {
            if ($startStation === null) {
                $missingRequiredValue = true;
                return '';
            }

            return $startStation;
        }

        if (in_array($key, ['stacja koncowa', 'station end', 'end station'], true)) {
            if ($endStation === null) {
                $missingRequiredValue = true;
                return '';
            }

            return $endStation;
        }

        return $matches[0];
    }, $message);

    if ($missingRequiredValue || $result === null) {
        return null;
    }

    return trim($result);
}

function hasUnresolvedDisruptionPlaceholder(string $message): bool
{
    return (bool) preg_match('/\{[^{}]+\}/u', $message);
}

function isTechnicalDisruptionText(string $value): bool
{
    $normalized = str_replace(' ', '_', normalizeText($value));

    return (bool) preg_match('/^utr_?\d+$/i', $normalized);
}

function routeSummary(array $route, array $stationDict, string $date): array
{
    $stations = sortedRouteStations($route);
    $origin = $stations !== [] ? stationNameFromAnyDictionary($stationDict, (int) ($stations[0]['stationId'] ?? 0)) : null;
    $destinationStop = $stations !== [] ? $stations[count($stations) - 1] : null;
    $destination = $destinationStop ? stationNameFromAnyDictionary($stationDict, (int) ($destinationStop['stationId'] ?? 0)) : null;
    $number = routeNumber($route);
    $category = cleanNullable($route['commercialCategorySymbol'] ?? null);
    $name = cleanNullable($route['name'] ?? null);
    $labelParts = array_values(array_filter([$category, $number, $name]));

    return [
        'scheduleId' => (int) ($route['scheduleId'] ?? 0),
        'orderId' => (int) ($route['orderId'] ?? 0),
        'operationOrderId' => (int) ($route['trainOrderId'] ?? $route['orderId'] ?? 0),
        'trainOrderId' => (int) ($route['trainOrderId'] ?? 0),
        'operatingDate' => $date,
        'label' => $labelParts !== [] ? implode(' ', $labelParts) : ('Pociag ' . ($number ?? '')),
        'name' => $name,
        'number' => $number,
        'category' => $category,
        'carrierCode' => cleanNullable($route['carrierCode'] ?? null),
        'origin' => $origin,
        'destination' => $destination,
        'stationCount' => count($stations),
        'firstDeparture' => $stations !== [] ? plannedDateTime($date, $stations[0]['departureTime'] ?? $stations[0]['arrivalTime'] ?? null, $stations[0]['departureDay'] ?? $stations[0]['arrivalDay'] ?? 0) : null,
        'lastArrival' => $destinationStop ? plannedDateTime($date, $destinationStop['arrivalTime'] ?? $destinationStop['departureTime'] ?? null, $destinationStop['arrivalDay'] ?? $destinationStop['departureDay'] ?? 0) : null,
    ];
}

function routeMatchesQuery(array $route, string $query): bool
{
    $needle = normalizeText($query);
    $fields = [
        $route['name'] ?? '',
        $route['carrierCode'] ?? '',
        $route['nationalNumber'] ?? '',
        $route['internationalArrivalNumber'] ?? '',
        $route['internationalDepartureNumber'] ?? '',
        $route['commercialCategorySymbol'] ?? '',
    ];

    foreach (($route['stations'] ?? []) as $station) {
        $fields[] = $station['arrivalTrainNumber'] ?? '';
        $fields[] = $station['departureTrainNumber'] ?? '';
        $fields[] = $station['arrivalCommercialCategory'] ?? '';
        $fields[] = $station['departureCommercialCategory'] ?? '';
    }

    return strpos(normalizeText(implode(' ', $fields)), $needle) !== false;
}

function routeNumber(array $route): ?string
{
    foreach (['nationalNumber', 'internationalDepartureNumber', 'internationalArrivalNumber'] as $key) {
        $value = cleanNullable($route[$key] ?? null);
        if ($value !== null) {
            return $value;
        }
    }

    foreach (($route['stations'] ?? []) as $station) {
        foreach (['departureTrainNumber', 'arrivalTrainNumber'] as $key) {
            $value = cleanNullable($station[$key] ?? null);
            if ($value !== null) {
                return $value;
            }
        }
    }

    return null;
}

function sortedRouteStations(array $route): array
{
    $stations = $route['stations'] ?? [];
    usort($stations, static function (array $a, array $b): int {
        return ((int) ($a['orderNumber'] ?? 0)) <=> ((int) ($b['orderNumber'] ?? 0));
    });

    return $stations;
}

function routeStopsAtStation(array $route, int $stationId): array
{
    return array_values(array_filter(sortedRouteStations($route), static function (array $station) use ($stationId): bool {
        return (int) ($station['stationId'] ?? 0) === $stationId;
    }));
}

function operationMap(array $operations): array
{
    $map = [];

    foreach (($operations['trains'] ?? []) as $operation) {
        $date = (string) ($operation['operatingDate'] ?? '');
        $scheduleId = (string) ($operation['scheduleId'] ?? '');
        foreach ([$operation['orderId'] ?? null, $operation['trainOrderId'] ?? null] as $orderId) {
            if ($scheduleId !== '' && $orderId !== null && $date !== '') {
                $map[$scheduleId . '|' . $orderId . '|' . $date] = $operation;
            }
        }
    }

    return $map;
}

function firstOperationForRoute(array $operationMap, array $summary): ?array
{
    $keys = [
        $summary['scheduleId'] . '|' . $summary['operationOrderId'] . '|' . $summary['operatingDate'],
        $summary['scheduleId'] . '|' . $summary['orderId'] . '|' . $summary['operatingDate'],
    ];

    foreach ($keys as $key) {
        if (isset($operationMap[$key])) {
            return $operationMap[$key];
        }
    }

    return null;
}

function operationStopForStation(array $operation, int $stationId, $plannedOrderNumber = null): ?array
{
    $fallback = null;

    foreach (($operation['stations'] ?? []) as $stop) {
        if ((int) ($stop['stationId'] ?? 0) !== $stationId) {
            continue;
        }

        if ($fallback === null) {
            $fallback = $stop;
        }

        if ($plannedOrderNumber !== null && (int) ($stop['plannedSequenceNumber'] ?? $stop['actualSequenceNumber'] ?? 0) === (int) $plannedOrderNumber) {
            return $stop;
        }
    }

    return $fallback;
}

function statusInfo($code): array
{
    $code = is_string($code) ? $code : null;
    $map = [
        'S' => ['label' => 'Nie rozpoczal', 'tone' => 'idle'],
        'P' => ['label' => 'W trasie', 'tone' => 'live'],
        'C' => ['label' => 'Zakonczony', 'tone' => 'done'],
        'X' => ['label' => 'Odwolany', 'tone' => 'cancelled'],
        'Q' => ['label' => 'Czesciowo odwolany', 'tone' => 'warning'],
    ];

    return [
        'code' => $code,
        'label' => $code !== null && isset($map[$code]) ? $map[$code]['label'] : 'Brak statusu',
        'tone' => $code !== null && isset($map[$code]) ? $map[$code]['tone'] : 'unknown',
    ];
}

function delayLabel(?int $delay): string
{
    if ($delay === null) {
        return '';
    }

    if ($delay <= 0) {
        return 'Planowo';
    }

    return '+' . $delay . ' min';
}

function delayMinutesFromPdp($delay, $actualTime, $plannedTime = null): ?int
{
    $numericDelay = numericOrNull($delay);
    if ($numericDelay !== null) {
        return $numericDelay;
    }

    $actual = cleanNullable($actualTime);
    if ($actual === null) {
        return null;
    }

    $calculatedDelay = delayMinutesFromActualAndPlanned($actual, cleanNullable($plannedTime));
    if ($calculatedDelay !== null) {
        return $calculatedDelay;
    }

    return 0;
}

function delayMinutesFromActualAndPlanned(string $actualTime, ?string $plannedTime): ?int
{
    if ($plannedTime === null) {
        return null;
    }

    $actual = parsePdpDateTime($actualTime);
    $planned = parsePdpDateTime($plannedTime);
    if ($actual === null || $planned === null) {
        return null;
    }

    $diffSeconds = $actual->getTimestamp() - $planned->getTimestamp();
    if ($diffSeconds <= 0) {
        return 0;
    }

    return (int) ceil($diffSeconds / 60);
}

function parsePdpDateTime(string $value): ?DateTimeImmutable
{
    $value = trim($value);
    if ($value === '') {
        return null;
    }

    try {
        if (preg_match('/(?:Z|[+-]\d{2}:?\d{2})$/i', $value)) {
            return new DateTimeImmutable($value);
        }

        return new DateTimeImmutable($value, new DateTimeZone('Europe/Warsaw'));
    } catch (Throwable $exception) {
        return null;
    }
}

function stationDictionaryMap(PdpClient $client): array
{
    $payload = data_read_json('stations.json');
    $stations = is_array($payload) ? ($payload['items'] ?? $payload['stations'] ?? []) : [];

    if ($stations === []) {
        $response = pdp_stations_all($client);
        $stations = $response['stations'] ?? [];
    }

    $map = [];
    foreach ($stations as $station) {
        if (isset($station['id'], $station['name'])) {
            $map[(string) $station['id']] = (string) $station['name'];
        }
    }

    return $map;
}

function stationNameFromScheduleDictionary(array $dictionary, int $stationId): ?string
{
    $entry = $dictionary[(string) $stationId] ?? null;
    if (is_array($entry)) {
        return cleanNullable($entry['name'] ?? null);
    }

    if (is_string($entry)) {
        return cleanNullable($entry);
    }

    return null;
}

function stationNameFromOperationsDictionary(array $dictionary, int $stationId): ?string
{
    $value = $dictionary[(string) $stationId] ?? null;

    return is_string($value) ? cleanNullable($value) : null;
}

function stationNameFromAnyDictionary(array $dictionary, int $stationId): ?string
{
    return stationNameFromScheduleDictionary($dictionary, $stationId)
        ?? stationNameFromOperationsDictionary($dictionary, $stationId);
}

function plannedDateTime(string $date, $timeValue, $dayOffset = 0): ?string
{
    if ($timeValue === null || $timeValue === '') {
        return null;
    }

    $parsed = parseDurationLikeTime((string) $timeValue);
    if ($parsed === null) {
        return null;
    }

    $minutes = ((int) $dayOffset * 24 * 60) + ($parsed['days'] * 24 * 60) + ($parsed['hours'] * 60) + $parsed['minutes'];
    $base = new DateTimeImmutable($date . ' 00:00:00', new DateTimeZone('Europe/Warsaw'));

    return $base->modify('+' . $minutes . ' minutes')->format(DateTimeInterface::ATOM);
}

function parseDurationLikeTime(string $value): ?array
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

function queryString(string $name, string $default = ''): string
{
    $value = $_GET[$name] ?? $default;
    if (is_array($value)) {
        return $default;
    }

    return trim((string) $value);
}

function queryDate(string $name, ?string $default = null): string
{
    $value = queryString($name, $default ?? todayWarsaw());
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        return $value;
    }

    return $default ?? todayWarsaw();
}

function queryInt(string $name, int $default, int $min, int $max): int
{
    $value = $_GET[$name] ?? $default;
    if (is_array($value) || !is_numeric($value)) {
        return $default;
    }

    $int = (int) $value;

    return min(max($int, $min), $max);
}

function queryFloat(string $name): ?float
{
    $value = $_GET[$name] ?? null;
    if (is_array($value) || $value === null || $value === '') {
        return null;
    }

    $normalized = str_replace(',', '.', trim((string) $value));

    return is_numeric($normalized) ? (float) $normalized : null;
}

function todayWarsaw(): string
{
    return (new DateTimeImmutable('now', new DateTimeZone('Europe/Warsaw')))->format('Y-m-d');
}

function numericOrNull($value): ?int
{
    return is_numeric($value) ? (int) $value : null;
}

function cleanNullable($value): ?string
{
    if ($value === null) {
        return null;
    }

    $string = trim((string) $value);

    return $string === '' ? null : $string;
}

function truncateText(string $value, int $maxLength): string
{
    if (mb_strlen_safe($value) <= $maxLength) {
        return $value;
    }

    return function_exists('mb_substr') ? mb_substr($value, 0, $maxLength, 'UTF-8') : substr($value, 0, $maxLength);
}

function seoSlug($value): string
{
    $slug = str_replace(' ', '-', data_normalize_text((string) $value));
    $slug = trim(preg_replace('/-+/', '-', $slug) ?? $slug, '-');

    return $slug !== '' ? $slug : 'kolej';
}

function trainNumberFromLabel(string $label): ?string
{
    if (preg_match('/\b\d{2,7}\b/', $label, $matches)) {
        return $matches[0];
    }

    return null;
}

function canonicalTrackedHref(string $href): ?string
{
    $href = trim($href);
    if ($href === '') {
        return null;
    }

    $parts = parse_url($href);
    if ($parts === false) {
        return null;
    }

    $path = $parts['path'] ?? '/';
    $query = $parts['query'] ?? '';
    if (!in_array($path, ['', '/'], true) || $query === '') {
        return null;
    }

    parse_str($query, $params);
    $allowed = [];

    foreach (['stacja', 'id_stacji', 'pociag', 'szukaj', 'tryb', 'lista', 'data'] as $key) {
        if (isset($params[$key]) && !is_array($params[$key])) {
            $allowed[$key] = truncateText((string) $params[$key], 120);
        }
    }

    if ($allowed === []) {
        return null;
    }

    return '/?' . http_build_query($allowed, '', '&', PHP_QUERY_RFC3986);
}

function timestampOrNull($value): ?int
{
    $string = cleanNullable($value);
    if ($string === null) {
        return null;
    }

    try {
        return (new DateTimeImmutable($string, new DateTimeZone('Europe/Warsaw')))->getTimestamp();
    } catch (Throwable $exception) {
        return null;
    }
}

function clockFromIso($value): ?string
{
    $string = cleanNullable($value);
    if ($string === null) {
        return null;
    }

    try {
        return (new DateTimeImmutable($string))->setTimezone(new DateTimeZone('Europe/Warsaw'))->format('H:i');
    } catch (Throwable $exception) {
        return null;
    }
}

function normalizeText(string $value): string
{
    return data_normalize_text($value);
}

function mb_strlen_safe(string $value): int
{
    return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
}

function httpStatusForException(PdpApiException $exception): int
{
    $status = $exception->statusCode();
    if ($status >= 400 && $status <= 499) {
        return $status;
    }

    return 502;
}

function respond(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
