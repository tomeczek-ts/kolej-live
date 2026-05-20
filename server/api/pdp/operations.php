<?php

declare(strict_types=1);

/**
 * GET /api/v1/operations
 *
 * Pobiera dane live dla pociagow powiazanych z jedna stacja.
 * Parametry wysylane do PDP:
 * - stations: ID stacji, dla ktorej budujemy tablice.
 * - fullRoutes=true: potrzebne, zeby znalezc live stop konkretnego pociagu na tej stacji.
 * - withPlanned=true: dolacza planowe postoje, co ulatwia sparowanie operacji live z rozkladem.
 * - page=1/pageSize=5000: pobieramy duza pierwsza strone, bo tablica stacji moze miec wiele pozycji w ciagu dnia.
 *
 * Cache: 18 s. To endpoint realtime, wiec TTL jest celowo krotki.
 */
function pdp_operations_for_station(PdpClient $client, int $stationId): array
{
    return $client->get('/api/v1/operations', [
        'stations' => (string) $stationId,
        'fullRoutes' => true,
        'withPlanned' => true,
        'page' => 1,
        'pageSize' => 5000,
    ], function_exists('business_cache_ttl') ? business_cache_ttl('stationOperations', 18) : 18);
}

/**
 * GET /api/v1/operations
 *
 * Pobiera stronicowana liste operacji dla calej sieci, bez filtra `stations`.
 * Uzywane przez dzienny kolektor /hop, ktory na koniec dnia zapisuje historyczne opoznienia per pociag i stacja.
 * Parametry:
 * - page/pageSize: standardowa paginacja PDP, pageSize ograniczamy do max 5000.
 * - fullRoutes=true: zwraca pelne listy stacji w operacji pociagu.
 * - withPlanned=true: dolacza planowe czasy oraz arrivalDelayMinutes/departureDelayMinutes.
 *
 * Cache: 0 s. Kolektor ma pobierac finalny stan dnia, a nie przypadkowo stary cache realtime.
 */
function pdp_operations_all(PdpClient $client, int $page = 1, int $pageSize = 5000, int $cacheTtlSeconds = 0): array
{
    return $client->get('/api/v1/operations', [
        'page' => max(1, $page),
        'pageSize' => min(max($pageSize, 1), 5000),
        'fullRoutes' => true,
        'withPlanned' => true,
    ], $cacheTtlSeconds);
}

/**
 * GET /api/v1/operations/train/{scheduleId}/{orderId}/{operatingDate}
 *
 * Pobiera dane live dla konkretnego uruchomienia pociagu.
 * scheduleId identyfikuje trase planowa, orderId identyfikuje uruchomienie operacyjne, a operatingDate dzien kursu.
 *
 * Cache: 12 s, bo widok trasy powinien szybko pokazywac zmiany opoznien i potwierdzen.
 */
function pdp_operation_train(PdpClient $client, int $scheduleId, int $orderId, string $operatingDate): array
{
    return $client->get(
        '/api/v1/operations/train/' . rawurlencode((string) $scheduleId) . '/' . rawurlencode((string) $orderId) . '/' . rawurlencode($operatingDate),
        [],
        function_exists('business_cache_ttl') ? business_cache_ttl('trainOperation', 12) : 12
    );
}

/**
 * GET /api/v1/operations/statistics?date={date}
 *
 * Pobiera agregaty statusow pociagow dla wybranego dnia: lacznie, w trasie, zakonczone, odwolane.
 * W UI zasila pasek statystyk w prawym gornym rogu.
 *
 * Cache: 45 s, bo to agregat do orientacji, a nie szczegolowa informacja o pojedynczym pociagu.
 */
function pdp_operation_statistics(PdpClient $client, string $date): array
{
    return $client->get(
        '/api/v1/operations/statistics',
        ['date' => $date],
        function_exists('business_cache_ttl') ? business_cache_ttl('statistics', 45) : 45
    );
}
