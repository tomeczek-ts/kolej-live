<?php

declare(strict_types=1);

/**
 * GET /api/v1/disruptions
 *
 * Pobiera utrudnienia dla dnia i opcjonalnie dla konkretnej stacji.
 * Parametry wysylane do PDP:
 * - dateFrom/dateTo: ten sam dzien, zeby UI nie mieszal komunikatow z innych dat.
 * - stations: ID stacji; gdy null, pobieramy utrudnienia globalne dla calej sieci.
 * - dictionaries=true: PDP dolacza slownik typow utrudnien i stacji. Dzieki temu UI pokazuje opis zamiast kodu
 *   technicznego typu `utr_55`.
 *
 * Cache: 60 s, bo komunikaty sa dynamiczne, ale nie wymagaja odswiezania przy kazdym nacisnieciu zakladki.
 */
function pdp_disruptions(PdpClient $client, string $date, ?int $stationId = null): array
{
    return $client->get('/api/v1/disruptions', [
        'dateFrom' => $date,
        'dateTo' => $date,
        'stations' => $stationId !== null ? (string) $stationId : null,
        'dictionaries' => true,
    ], 60);
}
