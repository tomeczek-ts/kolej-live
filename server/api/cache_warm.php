<?php

declare(strict_types=1);

require __DIR__ . '/config.php';
require __DIR__ . '/PdpClient.php';
require __DIR__ . '/lib/DataFiles.php';
require __DIR__ . '/pdp/stations.php';
require __DIR__ . '/pdp/schedules.php';
require __DIR__ . '/pdp/disruptions.php';
require __DIR__ . '/pdp/dictionaries.php';

$isCli = PHP_SAPI === 'cli';

if (!$isCli) {
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');

    $token = isset($_GET['token']) && !is_array($_GET['token']) ? (string) $_GET['token'] : '';
    if (CACHE_WARM_TOKEN === '' || !hash_equals(CACHE_WARM_TOKEN, $token)) {
        http_response_code(403);
        echo json_encode(['error' => 'cache_warm_forbidden'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

if (PDP_API_KEY === '' || strpos(PDP_API_KEY, 'WSTAW_') === 0) {
    cache_warm_output(['ok' => false, 'error' => 'missing_api_key'], $isCli);
}

$client = new PdpClient(PDP_API_BASE_URL, PDP_API_KEY, PDP_CACHE_DIR);
$today = cache_warm_today();
$tomorrow = (new DateTimeImmutable($today, new DateTimeZone('Europe/Warsaw')))->modify('+1 day')->format('Y-m-d');
$summary = [
    'ok' => true,
    'generatedAt' => gmdate(DATE_ATOM),
    'dates' => [$today, $tomorrow],
    'files' => [],
];

try {
    $stations = cache_warm_stations(pdp_stations_all($client)['stations'] ?? []);
    cache_warm_write('stations', $stations, array_map(static fn(array $item): string => $item['id'] . "\t" . $item['name'], $stations), $summary);

    $carriers = cache_warm_carriers(pdp_carriers($client)['carriers'] ?? []);
    cache_warm_write('carriers', $carriers, array_map(static fn(array $item): string => $item['code'] . "\t" . $item['name'], $carriers), $summary);

    $categories = cache_warm_categories(pdp_commercial_categories($client)['commercialCategories'] ?? []);
    cache_warm_write('commercial-categories', $categories, array_map(static fn(array $item): string => $item['code'] . "\t" . $item['name'] . "\t" . $item['carrierCode'], $categories), $summary);

    $cities = cache_warm_cities(pdp_cities($client)['cities'] ?? []);
    cache_warm_write('cities', $cities, array_map(static fn(array $item): string => $item['name'] . "\t" . $item['stationCount'] . "\t" . implode(',', $item['stationIds']), $cities), $summary);

    $stopTypes = cache_warm_stop_types(pdp_stop_types($client)['stopTypes'] ?? []);
    cache_warm_write('stop-types', $stopTypes, array_map(static fn(array $item): string => $item['id'] . "\t" . $item['description'], $stopTypes), $summary);

    $disruptions = pdp_disruptions($client, $today, null);
    $disruptionTypes = cache_warm_disruption_types($disruptions['disruptionTypes'] ?? []);
    cache_warm_write('disruption-types', $disruptionTypes, array_map(static fn(array $item): string => $item['code'] . "\t" . $item['name'], $disruptionTypes), $summary);

    foreach ([$today, $tomorrow] as $date) {
        $schedules = pdp_schedules_for_day($client, $date);
        $trains = cache_warm_trains($schedules['routes'] ?? [], $schedules['dictionaries']['stations'] ?? [], $date);
        cache_warm_write('trains-' . $date, $trains, array_map(static function (array $item): string {
            return $item['label'] . "\t" . ($item['origin'] ?? '') . "\t" . ($item['destination'] ?? '') . "\t" . $item['scheduleId'] . "\t" . $item['orderId'];
        }, $trains), $summary);
    }
} catch (Throwable $exception) {
    $summary['ok'] = false;
    $summary['error'] = $exception->getMessage();
}

cache_warm_output($summary, $isCli);

function cache_warm_write(string $basename, array $items, array $txtRows, array &$summary): void
{
    data_write_json($basename . '.json', [
        'generatedAt' => gmdate(DATE_ATOM),
        'count' => count($items),
        'items' => array_values($items),
    ]);
    data_write_txt($basename . '.txt', $txtRows);

    $summary['files'][$basename . '.json'] = count($items);
    $summary['files'][$basename . '.txt'] = count($txtRows);
}

function cache_warm_stations(array $rows): array
{
    $items = [];
    foreach ($rows as $row) {
        if (!isset($row['id'], $row['name'])) {
            continue;
        }

        $name = trim((string) $row['name']);
        if (!cache_warm_is_public_station_name($name)) {
            continue;
        }

        $items[] = ['id' => (int) $row['id'], 'name' => $name];
    }

    return $items;
}

function cache_warm_is_public_station_name(string $name): bool
{
    $name = trim($name);
    if ($name === '' || strpos($name, ' -') !== false) {
        return false;
    }

    $lettersOnly = preg_replace('/[^\p{L}]+/u', '', $name) ?? '';
    if ($lettersOnly === '') {
        return false;
    }

    $upper = function_exists('mb_strtoupper') ? mb_strtoupper($name, 'UTF-8') : strtoupper($name);

    return $name !== $upper;
}

function cache_warm_carriers(array $rows): array
{
    $items = [];
    foreach ($rows as $row) {
        $code = cache_warm_clean($row['code'] ?? null);
        $name = cache_warm_clean($row['name'] ?? null);
        if ($code !== null || $name !== null) {
            $items[] = [
                'code' => $code ?? '',
                'name' => $name ?? $code ?? '',
                'validFrom' => cache_warm_clean($row['validFrom'] ?? null),
                'validTo' => cache_warm_clean($row['validTo'] ?? null),
            ];
        }
    }

    return $items;
}

function cache_warm_categories(array $rows): array
{
    $items = [];
    foreach ($rows as $row) {
        $code = cache_warm_clean($row['code'] ?? null);
        $name = cache_warm_clean($row['name'] ?? null);
        if ($code !== null || $name !== null) {
            $items[] = [
                'code' => $code ?? '',
                'name' => $name ?? $code ?? '',
                'carrierCode' => cache_warm_clean($row['carrierCode'] ?? null) ?? '',
                'speedCategoryCode' => cache_warm_clean($row['speedCategoryCode'] ?? null) ?? '',
            ];
        }
    }

    return $items;
}

function cache_warm_cities(array $rows): array
{
    $items = [];
    foreach ($rows as $row) {
        $name = cache_warm_clean($row['name'] ?? null);
        if ($name !== null) {
            $stationIds = array_values(array_map('intval', $row['stationIds'] ?? []));
            $items[] = [
                'name' => $name,
                'stationCount' => (int) ($row['stationCount'] ?? count($stationIds)),
                'stationIds' => $stationIds,
            ];
        }
    }

    return $items;
}

function cache_warm_stop_types(array $rows): array
{
    $items = [];
    foreach ($rows as $row) {
        if (isset($row['id'])) {
            $items[] = [
                'id' => (int) $row['id'],
                'description' => cache_warm_clean($row['description'] ?? null) ?? '',
            ];
        }
    }

    return $items;
}

function cache_warm_disruption_types(array $map): array
{
    $items = [];
    foreach ($map as $code => $name) {
        $code = cache_warm_clean($code);
        $name = cache_warm_clean($name);
        if ($code !== null && $name !== null) {
            $items[] = ['code' => $code, 'name' => $name];
        }
    }

    return $items;
}

function cache_warm_trains(array $routes, array $stationDict, string $date): array
{
    $items = [];
    foreach ($routes as $route) {
        if (!is_array($route)) {
            continue;
        }

        $items[] = cache_warm_route_summary($route, $stationDict, $date);
    }

    usort($items, static fn(array $a, array $b): int => strcmp((string) ($a['firstDeparture'] ?? ''), (string) ($b['firstDeparture'] ?? '')));

    return $items;
}

function cache_warm_route_summary(array $route, array $stationDict, string $date): array
{
    $stations = $route['stations'] ?? [];
    usort($stations, static fn(array $a, array $b): int => ((int) ($a['orderNumber'] ?? 0)) <=> ((int) ($b['orderNumber'] ?? 0)));

    $origin = $stations !== [] ? cache_warm_station_name($stationDict, (int) ($stations[0]['stationId'] ?? 0)) : null;
    $last = $stations !== [] ? $stations[count($stations) - 1] : null;
    $destination = $last !== null ? cache_warm_station_name($stationDict, (int) ($last['stationId'] ?? 0)) : null;
    $number = cache_warm_route_number($route);
    $category = cache_warm_clean($route['commercialCategorySymbol'] ?? null);
    $name = cache_warm_clean($route['name'] ?? null);
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
        'carrierCode' => cache_warm_clean($route['carrierCode'] ?? null),
        'origin' => $origin,
        'destination' => $destination,
        'stationCount' => count($stations),
        'firstDeparture' => $stations !== [] ? cache_warm_planned_datetime($date, $stations[0]['departureTime'] ?? $stations[0]['arrivalTime'] ?? null, $stations[0]['departureDay'] ?? $stations[0]['arrivalDay'] ?? 0) : null,
        'lastArrival' => $last !== null ? cache_warm_planned_datetime($date, $last['arrivalTime'] ?? $last['departureTime'] ?? null, $last['arrivalDay'] ?? $last['departureDay'] ?? 0) : null,
    ];
}

function cache_warm_route_number(array $route): ?string
{
    foreach (['nationalNumber', 'internationalDepartureNumber', 'internationalArrivalNumber'] as $key) {
        $value = cache_warm_clean($route[$key] ?? null);
        if ($value !== null) {
            return $value;
        }
    }

    foreach (($route['stations'] ?? []) as $station) {
        foreach (['departureTrainNumber', 'arrivalTrainNumber'] as $key) {
            $value = cache_warm_clean($station[$key] ?? null);
            if ($value !== null) {
                return $value;
            }
        }
    }

    return null;
}

function cache_warm_station_name(array $dictionary, int $stationId): ?string
{
    $entry = $dictionary[(string) $stationId] ?? null;
    if (is_array($entry)) {
        return cache_warm_clean($entry['name'] ?? null);
    }

    return is_string($entry) ? cache_warm_clean($entry) : null;
}

function cache_warm_planned_datetime(string $date, $timeValue, $dayOffset = 0): ?string
{
    if ($timeValue === null || $timeValue === '') {
        return null;
    }

    $parsed = cache_warm_parse_time((string) $timeValue);
    if ($parsed === null) {
        return null;
    }

    $minutes = ((int) $dayOffset * 24 * 60) + ($parsed['days'] * 24 * 60) + ($parsed['hours'] * 60) + $parsed['minutes'];
    $base = new DateTimeImmutable($date . ' 00:00:00', new DateTimeZone('Europe/Warsaw'));

    return $base->modify('+' . $minutes . ' minutes')->format(DateTimeInterface::ATOM);
}

function cache_warm_parse_time(string $value): ?array
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

function cache_warm_today(): string
{
    return (new DateTimeImmutable('now', new DateTimeZone('Europe/Warsaw')))->format('Y-m-d');
}

function cache_warm_clean($value): ?string
{
    if ($value === null) {
        return null;
    }

    $string = trim((string) $value);

    return $string === '' ? null : $string;
}

function cache_warm_output(array $payload, bool $isCli): void
{
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    echo $isCli ? PHP_EOL : '';
    exit;
}
