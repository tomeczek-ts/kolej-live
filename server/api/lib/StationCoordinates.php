<?php

declare(strict_types=1);

/**
 * PDP currently exposes station dictionaries as id + name only. The helper below
 * still understands common coordinate shapes, so the API can start using native
 * PDP coordinates automatically if they appear in the dictionary later.
 */
function station_coordinates_from_row(array $row): ?array
{
    $lat = station_coordinates_pick_float($row, [
        ['latitude'],
        ['lat'],
        ['geoLatitude'],
        ['location', 'latitude'],
        ['location', 'lat'],
        ['geoLocation', 'latitude'],
        ['geoLocation', 'lat'],
        ['position', 'latitude'],
        ['position', 'lat'],
    ]);
    $lon = station_coordinates_pick_float($row, [
        ['longitude'],
        ['lon'],
        ['lng'],
        ['geoLongitude'],
        ['location', 'longitude'],
        ['location', 'lon'],
        ['location', 'lng'],
        ['geoLocation', 'longitude'],
        ['geoLocation', 'lon'],
        ['geoLocation', 'lng'],
        ['position', 'longitude'],
        ['position', 'lon'],
        ['position', 'lng'],
    ]);

    if (($lat === null || $lon === null) && isset($row['coordinates']) && is_array($row['coordinates'])) {
        $coords = $row['coordinates'];
        if (station_coordinates_array_is_list($coords) && isset($coords[0], $coords[1])) {
            $lon = station_coordinates_float($coords[0]);
            $lat = station_coordinates_float($coords[1]);
        } else {
            $lat = $lat ?? station_coordinates_pick_float($coords, [['latitude'], ['lat']]);
            $lon = $lon ?? station_coordinates_pick_float($coords, [['longitude'], ['lon'], ['lng']]);
        }
    }

    if ($lat === null || $lon === null || !station_coordinates_are_valid($lat, $lon)) {
        return null;
    }

    return ['latitude' => $lat, 'longitude' => $lon];
}

function station_coordinates_pick_float(array $row, array $paths): ?float
{
    foreach ($paths as $path) {
        $value = station_coordinates_path_value($row, $path);
        $float = station_coordinates_float($value);
        if ($float !== null) {
            return $float;
        }
    }

    return null;
}

function station_coordinates_path_value(array $row, array $path)
{
    $value = $row;
    foreach ($path as $key) {
        if (!is_array($value) || !array_key_exists($key, $value)) {
            return null;
        }
        $value = $value[$key];
    }

    return $value;
}

function station_coordinates_float($value): ?float
{
    if ($value === null || $value === '') {
        return null;
    }

    if (is_string($value)) {
        $value = str_replace(',', '.', trim($value));
    }

    return is_numeric($value) ? (float) $value : null;
}

function station_coordinates_are_valid(float $lat, float $lon): bool
{
    return $lat >= -90.0 && $lat <= 90.0 && $lon >= -180.0 && $lon <= 180.0;
}

function station_coordinates_normalize_name(string $name): string
{
    $lower = function_exists('mb_strtolower') ? mb_strtolower($name, 'UTF-8') : strtolower($name);
    $ascii = function_exists('iconv') ? iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $lower) : $lower;

    return trim(preg_replace('/[^a-z0-9]+/', ' ', (string) $ascii) ?? $lower);
}

function station_coordinates_array_is_list(array $value): bool
{
    $expected = 0;
    foreach ($value as $key => $_) {
        if ($key !== $expected) {
            return false;
        }
        $expected++;
    }

    return true;
}

/**
 * Fallback coordinates for public passenger stations. They are used only because
 * PDP does not publish coordinates in /api/v1/dictionaries/stations.
 */
function station_coordinates_fallbacks(): array
{
    return [
        ['name' => 'Warszawa Centralna', 'latitude' => 52.22879, 'longitude' => 21.00304],
        ['name' => 'Warszawa Zachodnia', 'latitude' => 52.21930, 'longitude' => 20.96550],
        ['name' => 'Warszawa Wschodnia', 'latitude' => 52.25143, 'longitude' => 21.05258],
        ['name' => 'Kraków Główny', 'latitude' => 50.06729, 'longitude' => 19.94761],
        ['name' => 'Poznań Główny', 'latitude' => 52.40278, 'longitude' => 16.91122],
        ['name' => 'Gdańsk Główny', 'latitude' => 54.35560, 'longitude' => 18.64637],
        ['name' => 'Wrocław Główny', 'latitude' => 51.09809, 'longitude' => 17.03657],
        ['name' => 'Katowice', 'latitude' => 50.25702, 'longitude' => 19.01732],
        ['name' => 'Łódź Fabryczna', 'latitude' => 51.76873, 'longitude' => 19.46761],
        ['name' => 'Łódź Kaliska', 'latitude' => 51.75634, 'longitude' => 19.42922],
        ['name' => 'Szczecin Główny', 'latitude' => 53.41977, 'longitude' => 14.55064],
        ['name' => 'Bydgoszcz Główna', 'latitude' => 53.13468, 'longitude' => 17.99003],
        ['name' => 'Lublin Główny', 'latitude' => 51.23182, 'longitude' => 22.56858],
        ['name' => 'Białystok', 'latitude' => 53.13302, 'longitude' => 23.14578],
        ['name' => 'Rzeszów Główny', 'latitude' => 50.04130, 'longitude' => 22.00067],
        ['name' => 'Olsztyn Główny', 'latitude' => 53.77936, 'longitude' => 20.49429],
        ['name' => 'Toruń Główny', 'latitude' => 53.01071, 'longitude' => 18.61420],
        ['name' => 'Kielce', 'latitude' => 50.87691, 'longitude' => 20.61884],
        ['name' => 'Opole Główne', 'latitude' => 50.66158, 'longitude' => 17.92436],
        ['name' => 'Gdynia Główna', 'latitude' => 54.51891, 'longitude' => 18.53054],
        ['name' => 'Sopot', 'latitude' => 54.44158, 'longitude' => 18.56122],
        ['name' => 'Częstochowa', 'latitude' => 50.80778, 'longitude' => 19.12053],
        ['name' => 'Radom Główny', 'latitude' => 51.39939, 'longitude' => 21.15836],
        ['name' => 'Zielona Góra Główna', 'latitude' => 51.93902, 'longitude' => 15.50543],
        ['name' => 'Gorzów Wielkopolski', 'latitude' => 52.73679, 'longitude' => 15.22879],
        ['name' => 'Koszalin', 'latitude' => 54.19052, 'longitude' => 16.18191],
        ['name' => 'Kołobrzeg', 'latitude' => 54.18100, 'longitude' => 15.57463],
        ['name' => 'Słupsk', 'latitude' => 54.46428, 'longitude' => 17.02857],
        ['name' => 'Elbląg', 'latitude' => 54.16072, 'longitude' => 19.40471],
        ['name' => 'Ełk', 'latitude' => 53.82554, 'longitude' => 22.34612],
        ['name' => 'Suwałki', 'latitude' => 54.09900, 'longitude' => 22.93440],
        ['name' => 'Przemyśl Główny', 'latitude' => 49.78279, 'longitude' => 22.76763],
        ['name' => 'Zakopane', 'latitude' => 49.29916, 'longitude' => 19.95784],
        ['name' => 'Tarnów', 'latitude' => 50.01211, 'longitude' => 20.98658],
        ['name' => 'Nowy Sącz', 'latitude' => 49.62413, 'longitude' => 20.70073],
        ['name' => 'Gliwice', 'latitude' => 50.29682, 'longitude' => 18.66902],
        ['name' => 'Zabrze', 'latitude' => 50.30664, 'longitude' => 18.78627],
        ['name' => 'Tychy', 'latitude' => 50.13218, 'longitude' => 18.98612],
        ['name' => 'Bielsko-Biała Główna', 'latitude' => 49.82224, 'longitude' => 19.04803],
        ['name' => 'Rybnik', 'latitude' => 50.09209, 'longitude' => 18.54137],
        ['name' => 'Jelenia Góra', 'latitude' => 50.90372, 'longitude' => 15.73903],
        ['name' => 'Legnica', 'latitude' => 51.20702, 'longitude' => 16.16190],
        ['name' => 'Wałbrzych Miasto', 'latitude' => 50.77039, 'longitude' => 16.28439],
        ['name' => 'Kalisz', 'latitude' => 51.75929, 'longitude' => 18.08808],
        ['name' => 'Piła Główna', 'latitude' => 53.15038, 'longitude' => 16.73792],
        ['name' => 'Leszno', 'latitude' => 51.84423, 'longitude' => 16.57442],
        ['name' => 'Gniezno', 'latitude' => 52.53531, 'longitude' => 17.59564],
        ['name' => 'Ostrów Wielkopolski', 'latitude' => 51.64942, 'longitude' => 17.81609],
        ['name' => 'Inowrocław', 'latitude' => 52.79644, 'longitude' => 18.26018],
        ['name' => 'Kutno', 'latitude' => 52.23057, 'longitude' => 19.36149],
        ['name' => 'Skierniewice', 'latitude' => 51.95942, 'longitude' => 20.14887],
        ['name' => 'Siedlce', 'latitude' => 52.16774, 'longitude' => 22.28920],
        ['name' => 'Chełm', 'latitude' => 51.13988, 'longitude' => 23.47152],
        ['name' => 'Zamość', 'latitude' => 50.72301, 'longitude' => 23.25122],
        ['name' => 'Biała Podlaska', 'latitude' => 52.03267, 'longitude' => 23.11557],
        ['name' => 'Iława Główna', 'latitude' => 53.59654, 'longitude' => 19.56807],
        ['name' => 'Malbork', 'latitude' => 54.03591, 'longitude' => 19.02569],
        ['name' => 'Tczew', 'latitude' => 54.09227, 'longitude' => 18.78942],
        ['name' => 'Grudziądz', 'latitude' => 53.48671, 'longitude' => 18.75327],
        ['name' => 'Włocławek', 'latitude' => 52.65783, 'longitude' => 19.06863],
        ['name' => 'Płock', 'latitude' => 52.54627, 'longitude' => 19.69692],
        ['name' => 'Konin', 'latitude' => 52.22439, 'longitude' => 18.25180],
        ['name' => 'Sieradz', 'latitude' => 51.59621, 'longitude' => 18.73002],
        ['name' => 'Tomaszów Mazowiecki', 'latitude' => 51.53182, 'longitude' => 20.00818],
        ['name' => 'Piotrków Trybunalski', 'latitude' => 51.40513, 'longitude' => 19.69621],
        ['name' => 'Dęblin', 'latitude' => 51.56270, 'longitude' => 21.85483],
        ['name' => 'Puławy Miasto', 'latitude' => 51.41633, 'longitude' => 21.96842],
        ['name' => 'Kędzierzyn-Koźle', 'latitude' => 50.34403, 'longitude' => 18.20732],
        ['name' => 'Nysa', 'latitude' => 50.47402, 'longitude' => 17.33241],
        ['name' => 'Racibórz', 'latitude' => 50.09168, 'longitude' => 18.21902],
        ['name' => 'Cieszyn', 'latitude' => 49.74922, 'longitude' => 18.63202],
        ['name' => 'Ustroń', 'latitude' => 49.72058, 'longitude' => 18.81208],
        ['name' => 'Wisła Uzdrowisko', 'latitude' => 49.65520, 'longitude' => 18.85869],
        ['name' => 'Hel', 'latitude' => 54.60641, 'longitude' => 18.80179],
        ['name' => 'Władysławowo', 'latitude' => 54.79051, 'longitude' => 18.40288],
        ['name' => 'Lębork', 'latitude' => 54.53942, 'longitude' => 17.74673],
    ];
}
