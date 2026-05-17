<?php

declare(strict_types=1);

/**
 * GET /api/v1/dictionaries/carriers
 *
 * Slownik przewoznikow: kod przewoznika + pelna nazwa.
 * Uzywany w autouzupelnianiu oraz przez cache_warm.php do pliku `data/carriers.json`.
 *
 * Cache: 86400 s, bo slownik przewoznikow zmienia sie rzadko.
 */
function pdp_carriers(PdpClient $client): array
{
    return $client->get('/api/v1/dictionaries/carriers', [], 86400);
}

/**
 * GET /api/v1/dictionaries/commercial-categories
 *
 * Slownik kategorii handlowych, np. IC/EIC/R, z powiazaniem na przewoznika i kategorie predkosci.
 * Uzywany w autouzupelnianiu, zeby wyszukiwarka rozumiala nie tylko stacje i numery pociagow.
 *
 * Cache: 86400 s, bo kategorie handlowe sa danymi slownikowymi.
 */
function pdp_commercial_categories(PdpClient $client): array
{
    return $client->get('/api/v1/dictionaries/commercial-categories', [], 86400);
}

/**
 * GET /api/v1/dictionaries/stop-types
 *
 * Slownik typow postoju. Trafia do cache plikowego, zeby latwo porownac techniczne ID z opisem PDP.
 *
 * Cache: 86400 s, bo opis typu postoju nie jest informacja realtime.
 */
function pdp_stop_types(PdpClient $client): array
{
    return $client->get('/api/v1/dictionaries/stop-types', [], 86400);
}

/**
 * GET /api/v1/dictionaries/cities
 *
 * Slownik miast/agregacji stacji. PDP zwraca nazwe miasta, liczbe stacji i liste stationIds.
 * W autouzupelnianiu pozwala znalezc np. miasto zamiast konkretnej stacji.
 *
 * Cache: 86400 s, bo lista miast wynika ze slownika stacji.
 */
function pdp_cities(PdpClient $client, string $search = ''): array
{
    return $client->get('/api/v1/dictionaries/cities', ['search' => $search], 86400);
}

/**
 * GET /api/v1/data-version
 *
 * Lekki endpoint diagnostyczny PDP. Pozwala sprawdzic, jaka wersja danych jest obecnie publikowana przez API.
 *
 * Cache: 60 s, zeby wersja danych byla aktualna podczas diagnostyki.
 */
function pdp_data_version(PdpClient $client): array
{
    return $client->get('/api/v1/data-version', [], 60);
}
