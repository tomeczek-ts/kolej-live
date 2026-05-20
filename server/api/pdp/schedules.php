<?php

declare(strict_types=1);

/**
 * GET /api/v1/schedules
 *
 * Pobiera planowy rozklad dla jednej stacji w konkretnym dniu.
 * Parametry wysylane do PDP:
 * - dateFrom/dateTo: ten sam dzien, bo ekran pokazuje tablice dla wybranej daty.
 * - stations: pojedyncze ID stacji z PDP.
 * - fullRoute=true: potrzebne do pokazania relacji, stacji poczatkowej/koncowej oraz wyboru pociagu.
 * - dictionaries=true: potrzebne do mapowania ID stacji z trasy na czytelne nazwy.
 *
 * Cache: 45 s. Rozklad planowy jest stabilny, ale krotki TTL pozwala szybko lapac korekty publikowane przez PDP.
 */
function pdp_schedules_for_station(PdpClient $client, int $stationId, string $date): array
{
    return $client->get('/api/v1/schedules', [
        'dateFrom' => $date,
        'dateTo' => $date,
        'stations' => (string) $stationId,
        'fullRoute' => true,
        'dictionaries' => true,
    ], function_exists('business_cache_ttl') ? business_cache_ttl('stationSchedules', 45) : 45);
}

/**
 * GET /api/v1/schedules
 *
 * Pobiera planowe trasy dla calego dnia bez filtra stacji.
 * To jest najciezsze zapytanie w aplikacji, dlatego normalnie powinno byc odpalane przez cache_warm.php.
 * Uzywamy go jako fallback dla wyszukiwania pociagow, gdy brakuje pliku `data/trains-YYYY-MM-DD.json`.
 *
 * Cache: 120 s, zeby ograniczyc koszt fallbacku i nie powtarzac ciezkiego zapytania przy kazdym wpisaniu tekstu.
 */
function pdp_schedules_for_day(PdpClient $client, string $date): array
{
    return $client->get('/api/v1/schedules', [
        'dateFrom' => $date,
        'dateTo' => $date,
        'fullRoute' => true,
        'dictionaries' => true,
    ], function_exists('business_cache_ttl') ? business_cache_ttl('daySchedules', 120) : 120);
}

/**
 * GET /api/v1/schedules/route/{scheduleId}/{orderId}
 *
 * Pobiera szczegoly planowej trasy jednego pociagu.
 * scheduleId i orderId pochodza z wynikow `/api/v1/schedules` albo z cache pliku `trains-YYYY-MM-DD.json`.
 *
 * Cache: 300 s, bo plan trasy pojedynczego pociagu nie wymaga sekundowego odswiezania.
 */
function pdp_schedule_route(PdpClient $client, int $scheduleId, int $orderId): array
{
    return $client->get(
        '/api/v1/schedules/route/' . rawurlencode((string) $scheduleId) . '/' . rawurlencode((string) $orderId),
        [],
        function_exists('business_cache_ttl') ? business_cache_ttl('route', 300) : 300
    );
}

/**
 * GET /api/v1/schedules/routes/{date}
 *
 * Pobiera liste identyfikatorow tras dla dnia. Funkcja zostaje osobno, bo endpoint jest przydatny do diagnostyki
 * i ewentualnego lekkiego sprawdzenia, czy dzien ma dostepne rozklady bez pobierania pelnych tras.
 *
 * Cache: 3600 s, bo lista identyfikatorow jest mniej dynamiczna niz operacje live.
 */
function pdp_route_ids(PdpClient $client, string $date): array
{
    return $client->get('/api/v1/schedules/routes/' . rawurlencode($date), [], 3600);
}
