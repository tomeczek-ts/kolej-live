<?php

declare(strict_types=1);

/**
 * GET /api/v1/dictionaries/stations
 *
 * Szuka stacji po fragmencie nazwy i zwraca slownik `id + name`.
 * Parametry wysylane do PDP:
 * - search: tekst wpisany przez uzytkownika; pusty string oznacza pobranie pierwszej strony slownika.
 * - page: zawsze 1, bo autocomplete potrzebuje tylko pierwszych najlepiej dopasowanych rekordow.
 * - pageSize: limit wynikow; obcinamy go do zakresu 1..10000, zeby nie wyslac przypadkowo zbyt duzego zapytania.
 *
 * Cache: 86400 s, poniewaz slownik stacji zmienia sie rzadko i jest bezpieczny do dziennego cache.
 */
function pdp_stations_search(PdpClient $client, string $search = '', int $pageSize = 50): array
{
    return $client->get('/api/v1/dictionaries/stations', [
        'search' => $search,
        'page' => 1,
        'pageSize' => min(max($pageSize, 1), 10000),
    ], function_exists('business_cache_ttl') ? business_cache_ttl('stations', 86400) : 86400);
}

/**
 * GET /api/v1/dictionaries/stations bez filtra `search`.
 *
 * Uzywane przez cache_warm.php i jako fallback do mapowania id stacji na nazwy.
 * Pobieramy do 10000 pozycji, bo PDP zwraca slownik stronicowany.
 */
function pdp_stations_all(PdpClient $client): array
{
    return pdp_stations_search($client, '', 10000);
}
