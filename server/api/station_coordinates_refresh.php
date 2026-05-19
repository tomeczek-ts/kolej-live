<?php

declare(strict_types=1);

require __DIR__ . '/config.php';
require __DIR__ . '/PdpClient.php';
require __DIR__ . '/lib/DataFiles.php';
require __DIR__ . '/lib/StationCoordinates.php';
require __DIR__ . '/pdp/stations.php';
require __DIR__ . '/hop/Config.php';

if (function_exists('set_time_limit')) {
    set_time_limit(300);
}

$isCli = PHP_SAPI === 'cli';
if (!$isCli) {
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');

    $token = isset($_GET['token']) && !is_array($_GET['token']) ? (string) $_GET['token'] : '';
    if (HOP_COLLECT_TOKEN === '' || !hash_equals(HOP_COLLECT_TOKEN, $token)) {
        http_response_code(403);
        echo json_encode(['error' => 'station_coordinates_refresh_forbidden'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

$summary = [
    'ok' => false,
    'generatedAt' => gmdate(DATE_ATOM),
    'source' => 'OpenStreetMap contributors via Overpass API',
    'files' => [],
    'pdpStations' => 0,
    'publicPdpStations' => 0,
    'osmElements' => 0,
    'osmNames' => 0,
    'matched' => 0,
    'unmatched' => 0,
    'unmatchedSample' => [],
];

try {
    if (PDP_API_KEY === '' || strpos(PDP_API_KEY, 'WSTAW_') === 0) {
        throw new RuntimeException('Missing PDP API key.');
    }

    $client = new PdpClient(PDP_API_BASE_URL, PDP_API_KEY, PDP_CACHE_DIR);

    // PDP jest źródłem kanonicznych ID i nazw stacji używanych później w aplikacji.
    $pdpRows = pdp_stations_all($client)['stations'] ?? [];
    $pdpStations = station_coordinates_refresh_public_pdp_stations(is_array($pdpRows) ? $pdpRows : []);
    $summary['pdpStations'] = is_array($pdpRows) ? count($pdpRows) : 0;
    $summary['publicPdpStations'] = count($pdpStations);

    // Overpass/OSM jest źródłem współrzędnych. Pobieramy obiekty kolejowe z Polski
    // i dopasowujemy je po znormalizowanych nazwach do słownika PDP.
    $osm = station_coordinates_refresh_fetch_overpass();
    $elements = is_array($osm['elements'] ?? null) ? $osm['elements'] : [];
    $summary['osmElements'] = count($elements);
    $osmIndex = station_coordinates_refresh_osm_index($elements);
    $summary['osmNames'] = count($osmIndex);

    $fallbackIndex = station_coordinates_refresh_fallback_index();
    $items = [];
    $unmatched = [];

    foreach ($pdpStations as $station) {
        $match = station_coordinates_refresh_match_by_name($station['name'], $osmIndex);
        $source = 'osm';

        if ($match === null) {
            $match = station_coordinates_refresh_match_by_name($station['name'], $fallbackIndex);
            $source = 'fallback';
        }

        if ($match === null) {
            $unmatched[] = $station['name'];
            continue;
        }

        $items[] = [
            'id' => $station['id'],
            'name' => $station['name'],
            'latitude' => $match['latitude'],
            'longitude' => $match['longitude'],
            'source' => $source,
            'sourceName' => $match['name'] ?? $station['name'],
            'sourceType' => $match['type'] ?? null,
            'sourceId' => $match['id'] ?? null,
        ];
    }

    usort($items, static fn(array $a, array $b): int => strcmp($a['name'], $b['name']));

    data_write_json('station-coordinates.json', [
        'generatedAt' => gmdate(DATE_ATOM),
        'source' => 'OpenStreetMap contributors via Overpass API',
        'license' => 'ODbL',
        'count' => count($items),
        'pdpStations' => $summary['pdpStations'],
        'publicPdpStations' => $summary['publicPdpStations'],
        'osmElements' => $summary['osmElements'],
        'osmNames' => $summary['osmNames'],
        'unmatchedCount' => count($unmatched),
        'unmatchedSample' => array_slice($unmatched, 0, 50),
        'items' => array_values($items),
    ]);

    data_write_txt('station-coordinates.txt', array_map(static function (array $item): string {
        return $item['id'] . "\t"
            . $item['name'] . "\t"
            . $item['latitude'] . "\t"
            . $item['longitude'] . "\t"
            . ($item['sourceName'] ?? '') . "\t"
            . ($item['source'] ?? '');
    }, $items));

    $summary['ok'] = true;
    $summary['matched'] = count($items);
    $summary['unmatched'] = count($unmatched);
    $summary['unmatchedSample'] = array_slice($unmatched, 0, 30);
    $summary['files'] = [
        'station-coordinates.json' => count($items),
        'station-coordinates.txt' => count($items),
    ];
} catch (Throwable $exception) {
    $summary['ok'] = false;
    $summary['error'] = $exception->getMessage();
    if (!$isCli) {
        http_response_code(500);
    }
}

echo json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
if ($isCli) {
    echo PHP_EOL;
}

function station_coordinates_refresh_public_pdp_stations(array $rows): array
{
    $items = [];
    foreach ($rows as $row) {
        if (!is_array($row) || !isset($row['id'], $row['name'])) {
            continue;
        }

        $name = trim((string) $row['name']);
        if (!station_coordinates_refresh_public_station_name($name)) {
            continue;
        }

        $items[] = ['id' => (int) $row['id'], 'name' => $name];
    }

    return $items;
}

function station_coordinates_refresh_public_station_name(string $name): bool
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

function station_coordinates_refresh_fetch_overpass(): array
{
    $query = station_coordinates_refresh_overpass_query();
    $endpoints = [
        'https://overpass-api.de/api/interpreter',
        'https://overpass.kumi.systems/api/interpreter',
    ];
    $errors = [];

    foreach ($endpoints as $endpoint) {
        try {
            $json = station_coordinates_refresh_http_get($endpoint . '?data=' . rawurlencode($query));
            $decoded = json_decode($json, true);
            if (!is_array($decoded)) {
                throw new RuntimeException('Overpass returned invalid JSON.');
            }

            return $decoded;
        } catch (Throwable $exception) {
            $errors[] = $endpoint . ': ' . $exception->getMessage();
        }
    }

    throw new RuntimeException('Could not load Overpass data. ' . implode(' | ', $errors));
}

function station_coordinates_refresh_overpass_query(): string
{
    return <<<'OVERPASS'
[out:json][timeout:180];
area["ISO3166-1"="PL"][admin_level=2]->.searchArea;
(
  node["railway"~"^(station|halt|stop)$"](area.searchArea);
  way["railway"~"^(station|halt|stop)$"](area.searchArea);
  relation["railway"~"^(station|halt|stop)$"](area.searchArea);
  node["public_transport"="station"]["station"="train"](area.searchArea);
  way["public_transport"="station"]["station"="train"](area.searchArea);
  relation["public_transport"="station"]["station"="train"](area.searchArea);
);
out center tags;
OVERPASS;
}

function station_coordinates_refresh_http_get(string $url): string
{
    $headers = [
        'Accept: application/json',
        'User-Agent: kolej.live station coordinate refresh',
    ];

    if (function_exists('curl_init')) {
        $curl = curl_init($url);
        if ($curl === false) {
            throw new RuntimeException('Could not initialize curl.');
        }

        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 20,
            CURLOPT_TIMEOUT => 240,
            CURLOPT_HTTPHEADER => $headers,
        ]);

        $body = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_error($curl);
        curl_close($curl);

        if (!is_string($body) || $body === '' || $status < 200 || $status >= 300) {
            throw new RuntimeException('HTTP ' . $status . ($error !== '' ? ': ' . $error : ''));
        }

        return $body;
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 240,
            'header' => implode("\r\n", $headers),
            'ignore_errors' => true,
        ],
    ]);
    $body = file_get_contents($url, false, $context);
    if (!is_string($body) || $body === '') {
        throw new RuntimeException('Empty HTTP response.');
    }

    return $body;
}

function station_coordinates_refresh_osm_index(array $elements): array
{
    $index = [];
    foreach ($elements as $element) {
        if (!is_array($element)) {
            continue;
        }

        $coordinates = station_coordinates_refresh_osm_coordinates($element);
        if ($coordinates === null) {
            continue;
        }

        $tags = is_array($element['tags'] ?? null) ? $element['tags'] : [];
        $names = station_coordinates_refresh_osm_names($tags);
        foreach ($names as $name) {
            foreach (station_coordinates_name_keys($name) as $key) {
                $candidate = [
                    'id' => (string) ($element['id'] ?? ''),
                    'type' => (string) ($element['type'] ?? ''),
                    'name' => $name,
                    'latitude' => $coordinates['latitude'],
                    'longitude' => $coordinates['longitude'],
                    'priority' => station_coordinates_refresh_osm_priority($element),
                ];

                if (!isset($index[$key]) || $candidate['priority'] < $index[$key]['priority']) {
                    $index[$key] = $candidate;
                }
            }
        }
    }

    return $index;
}

function station_coordinates_refresh_osm_coordinates(array $element): ?array
{
    $lat = station_coordinates_float($element['lat'] ?? $element['center']['lat'] ?? null);
    $lon = station_coordinates_float($element['lon'] ?? $element['center']['lon'] ?? null);

    if ($lat === null || $lon === null || !station_coordinates_are_valid($lat, $lon)) {
        return null;
    }

    return ['latitude' => $lat, 'longitude' => $lon];
}

function station_coordinates_refresh_osm_names(array $tags): array
{
    $fields = ['name', 'name:pl', 'official_name', 'alt_name', 'old_name', 'short_name', 'uic_name'];
    $names = [];

    foreach ($fields as $field) {
        $value = trim((string) ($tags[$field] ?? ''));
        if ($value === '') {
            continue;
        }

        foreach (preg_split('/[;\/]/u', $value) ?: [] as $part) {
            $name = trim($part);
            if ($name !== '') {
                $names[$name] = true;
            }
        }
    }

    return array_keys($names);
}

function station_coordinates_refresh_osm_priority(array $element): int
{
    $tags = is_array($element['tags'] ?? null) ? $element['tags'] : [];
    $railway = (string) ($tags['railway'] ?? '');

    if ($railway === 'station') {
        return 10;
    }
    if ($railway === 'halt') {
        return 20;
    }
    if (($tags['public_transport'] ?? null) === 'station') {
        return 30;
    }

    return 40;
}

function station_coordinates_refresh_fallback_index(): array
{
    $index = [];
    foreach (station_coordinates_fallbacks() as $fallback) {
        foreach (station_coordinates_name_keys((string) $fallback['name']) as $key) {
            $index[$key] = [
                'id' => null,
                'type' => 'fallback',
                'name' => (string) $fallback['name'],
                'latitude' => (float) $fallback['latitude'],
                'longitude' => (float) $fallback['longitude'],
                'priority' => 100,
            ];
        }
    }

    return $index;
}

function station_coordinates_refresh_match_by_name(string $name, array $index): ?array
{
    foreach (station_coordinates_name_keys($name) as $key) {
        if (isset($index[$key])) {
            return $index[$key];
        }
    }

    return null;
}
