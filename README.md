# kolej.live

PHP + React app for checking current train status through the PKP PLK PDP API.

## Setup

1. Copy `server/api/config.example.php` to `server/api/config.local.php` and put the PDP API key in `PDP_API_KEY`.
2. Install frontend dependencies:

```bash
npm install
```

3. Build production files:

```bash
npm run build
```

4. Deploy the contents of `dist/` as the document root for `https://kolej.live`.

The build copies the PHP API facade to `dist/api/`. The browser calls `/api/index.php`, and the PHP layer sends `X-API-Key` to `https://pdp-api.plk-sa.pl`.

## Local development

```bash
npm run dev
```

The Vite dev server uses bundled demo responses if PHP is not available locally. Production mode does not use demo data.

## Languages

User-facing UI text lives in PHP dictionaries:

- `server/api/lang/pl.php`
- `server/api/lang/en.php`

Each file returns a plain PHP array in the form:

```php
return [
    'key' => 'value',
    'key2' => 'value2',
];
```

React uses those PHP files as the build-time fallback through the Vite translation loader, and production also fetches the current dictionary from `/api/index.php?action=translations&lang=pl` or `lang=en`.

Add another language by adding a PHP file in `server/api/lang/`, then adding the locale code to `src/i18n.ts`, `src/vite-env.d.ts`, and the Vite translation loader in `vite.config.ts`.

The app reads the initial language from `?lang=pl` / `?lang=en`, then from `localStorage`, then from the browser language.

## API facade and autocomplete cache

Exact PDP calls are split into small files in `server/api/pdp/`:

- `stations.php` - station dictionary calls.
- `schedules.php` - planned schedules and route calls.
- `operations.php` - real-time operations and statistics calls.
- `disruptions.php` - disruption calls.
- `dictionaries.php` - carriers, commercial categories, stop types, cities, data version.

Autocomplete reads generated files from `server/api/data/` first and falls back to PDP API when a file is missing.

Nearest-station search reads `server/api/data/station-coordinates.json`. PDP provides station IDs and names, while `api/station_coordinates_refresh.php` refreshes coordinates from OpenStreetMap/Overpass and stores matched PDP station IDs in cache. This file does not need frequent cron updates; refresh it manually a few times per year or after larger timetable/station changes.

Preferred CLI refresh:

```bash
/usr/bin/php -q /home/USER/domains/kolej.live/public_html/api/station_coordinates_refresh.php
```

If the hosting panel supports only URL calls, use the same token as `hop_collect.php`, configured as `HOP_COLLECT_TOKEN` in `server/api/hop/Config.local.php`:

```bash
curl -fsS "https://kolej.live/api/station_coordinates_refresh.php?token=VALUE_FROM_HOP_CONFIG" >/dev/null 2>&1
```

Run the cache warm script from cron:

```bash
/usr/bin/php -q /home/USER/domains/kolej.live/public_html/api/cache_warm.php >/dev/null 2>&1
```

Suggested schedule in a hosting panel: every 15 minutes. If the panel supports only URL cron, set `CACHE_WARM_TOKEN` in `server/api/config.local.php` and use:

```bash
curl -fsS "https://kolej.live/api/cache_warm.php?token=YOUR_TOKEN" >/dev/null 2>&1
```

## HOP daily delay history

The independent historical delay module lives under `/hop`.

1. Create MySQL tables in phpMyAdmin by running:

```sql
-- file: api/hop/schema.sql
```

Source file: `server/api/hop/schema.sql`.

2. Copy `server/api/hop/Config.example.php` to `server/api/hop/Config.local.php` and put database credentials there.

3. Run the daily collector from cron. Preferred CLI command:

```bash
/usr/bin/php -q /home/USER/domains/kolej.live/public_html/api/hop_collect.php >/dev/null 2>&1
```

If the hosting panel supports only URL cron and `hop.kolej.live` points to the `hop/` document root, use the wrapper from the subdomain:

```bash
curl -fsS "https://hop.kolej.live/hop_collect.php?token=VALUE_FROM_API_HOP_CONFIG" >/dev/null 2>&1
```

The production build also copies `api/` into `dist/hop/api/`, so the `hop/` folder can be uploaded as a standalone document root for the `hop.kolej.live` subdomain.

Recommended time: `23:55` Europe/Warsaw. PDP `/api/v1/operations` does not accept a historical date parameter, so the safest single run is late in the same operating day, before the API rolls fully into the next day.

For manual backfill/testing on the same day:

```bash
/usr/bin/php -q /home/USER/domains/kolej.live/public_html/api/hop_collect.php --date=2026-05-15
```

Rerunning the collector for the same date is idempotent: unique keys on `hop_collection_runs`, `hop_train_runs`, and `hop_station_observations` make the script update existing rows instead of duplicating them.
