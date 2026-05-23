<?php

declare(strict_types=1);

function carrier_display_name(?string $carrierCode, ?string $rawName = null): ?string
{
    $code = carrier_normalize_code($carrierCode);
    $known = carrier_known_names();

    if ($code !== null && isset($known[$code])) {
        return $known[$code];
    }

    $fromCache = $code !== null ? carrier_name_from_cache($code) : null;
    if ($fromCache !== null) {
        return $fromCache;
    }

    $cleanRaw = carrier_clean_legal_suffix($rawName);
    if ($cleanRaw !== null) {
        return $cleanRaw;
    }

    return $code;
}

function carrier_known_names(): array
{
    // Passenger operators are based on UTK public passenger carrier materials.
    // The names below are intentionally user-facing, so legal suffixes are removed.
    return [
        'AR' => 'Arriva RP',
        'ARRIVA' => 'Arriva RP',
        'ARRIVARP' => 'Arriva RP',
        'IC' => 'PKP Intercity',
        'PKPIC' => 'PKP Intercity',
        'PKPINTERCITY' => 'PKP Intercity',
        'KD' => 'Koleje Dolnośląskie',
        'KDL' => 'Koleje Dolnośląskie',
        'KM' => 'Koleje Mazowieckie',
        'KMAZ' => 'Koleje Mazowieckie',
        'KML' => 'Koleje Małopolskie',
        'KMAL' => 'Koleje Małopolskie',
        'KSL' => 'Koleje Śląskie',
        'KS' => 'Koleje Śląskie',
        'KW' => 'Koleje Wielkopolskie',
        'LKA' => 'Łódzka Kolej Aglomeracyjna',
        'LKA2' => 'Łódzka Kolej Aglomeracyjna',
        'LE' => 'Leo Express',
        'LEOEXPRESS' => 'Leo Express',
        'POLREGIO' => 'POLREGIO',
        'PR' => 'POLREGIO',
        'REGIO' => 'POLREGIO',
        'RJ' => 'RegioJet',
        'REGIOJET' => 'RegioJet',
        'SKPL' => 'SKPL',
        'SKM' => 'Szybka Kolej Miejska',
        'SKMWA' => 'Szybka Kolej Miejska w Warszawie',
        'SKMT' => 'PKP SKM w Trójmieście',
        'PKPSKM' => 'PKP SKM w Trójmieście',
        'WKD' => 'Warszawska Kolej Dojazdowa',
        'UBB' => 'Usedomer Bäderbahn',
    ];
}

function carrier_name_from_cache(string $code): ?string
{
    $payload = data_read_json('carriers.json');
    $items = is_array($payload['items'] ?? null) ? $payload['items'] : [];

    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }

        if (carrier_normalize_code($item['code'] ?? null) !== $code) {
            continue;
        }

        return carrier_clean_legal_suffix($item['displayName'] ?? $item['name'] ?? null);
    }

    return null;
}

function carrier_clean_legal_suffix($value): ?string
{
    if (!is_string($value)) {
        return null;
    }

    $name = trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
    if ($name === '') {
        return null;
    }

    $name = preg_replace('/\s*\((?:daw\.?|dawniej)[^)]+\)\s*/iu', ' ', $name) ?? $name;
    $name = preg_replace('/\s+spolka\s+komandytowa\s*$/iu', '', $name) ?? $name;
    $name = preg_replace('/\s+sp\.?\s*k\.?\s*$/iu', '', $name) ?? $name;
    $name = preg_replace('/\s+sp\.?\s*j\.?\s*$/iu', '', $name) ?? $name;
    $name = preg_replace('/\s+sp\.?\s+z\s+o\.?\s*o\.?\s*$/iu', '', $name) ?? $name;
    $name = preg_replace('/\s+s\.?\s*a\.?\s*$/iu', '', $name) ?? $name;
    $name = preg_replace('/\s+a\.?\s*s\.?\s*$/iu', '', $name) ?? $name;
    $name = preg_replace('/\s+gmbh\s*(?:&\s*co\.?\s*kg)?\s*$/iu', '', $name) ?? $name;

    $name = trim(preg_replace('/\s+/u', ' ', $name) ?? $name, " \t\n\r\0\x0B.,;-");

    return $name !== '' ? $name : null;
}

function carrier_normalize_code($value): ?string
{
    if (!is_string($value) && !is_numeric($value)) {
        return null;
    }

    $code = strtoupper(trim((string) $value));
    if ($code === '') {
        return null;
    }

    $ascii = function_exists('iconv') ? iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $code) : $code;
    $code = preg_replace('/[^A-Z0-9]+/', '', (string) $ascii) ?? $code;

    return $code !== '' ? $code : null;
}
