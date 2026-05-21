<?php

declare(strict_types=1);

header('X-Robots-Tag: noindex, nofollow, noarchive, nosnippet', true);
header('Cache-Control: no-store, private', true);

function wiwiwi_api_root(): string
{
    $documentRoot = isset($_SERVER['DOCUMENT_ROOT']) ? rtrim((string) $_SERVER['DOCUMENT_ROOT'], '/\\') : '';
    $candidates = array_filter([
        $documentRoot !== '' ? $documentRoot . '/api' : null,
        __DIR__ . '/api',
        __DIR__ . '/../api',
        __DIR__ . '/../server/api',
    ]);

    foreach ($candidates as $candidate) {
        if (is_file($candidate . '/config.php')) {
            return $candidate;
        }
    }

    throw new RuntimeException('Brakuje katalogu API strony produkcyjnej.');
}

function wiwiwi_e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function wiwiwi_api_require(string $relativePath): void
{
    $path = wiwiwi_api_root() . '/' . ltrim(str_replace('\\', '/', $relativePath), '/');
    if (!is_file($path)) {
        throw new RuntimeException('Brakuje pliku API: ' . $relativePath);
    }

    require_once $path;
}

function wiwiwi_config_value_from_file(string $key, string $relativePath = 'config.local.php'): string
{
    $path = wiwiwi_api_root() . '/' . ltrim(str_replace('\\', '/', $relativePath), '/');
    if (!is_file($path)) {
        return '';
    }

    $config = app_config_load_array($path);

    return is_array($config) && isset($config[$key]) ? (string) $config[$key] : '';
}

function wiwiwi_token_is_valid(string $token): bool
{
    if ($token === '') {
        return false;
    }

    $allowedTokens = array_filter([
        wiwiwi_config_value_from_file('WIWIWI_TOKEN'),
        wiwiwi_config_value_from_file('CACHE_WARM_TOKEN'),
        defined('HOP_COLLECT_TOKEN') ? (string) HOP_COLLECT_TOKEN : '',
    ], static fn(string $value): bool => $value !== '');

    foreach ($allowedTokens as $allowedToken) {
        if (hash_equals($allowedToken, $token)) {
            return true;
        }
    }

    return false;
}

function wiwiwi_date_param(string $name, string $default): string
{
    $value = isset($_GET[$name]) && !is_array($_GET[$name]) ? (string) $_GET[$name] : '';

    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : $default;
}

function wiwiwi_number($value): string
{
    return number_format((float) $value, 0, ',', ' ');
}

function wiwiwi_percent($value, $limit): string
{
    $limit = (float) $limit;
    if ($limit <= 0) {
        return '-';
    }

    return number_format(((float) $value / $limit) * 100, 1, ',', ' ') . '%';
}

function wiwiwi_public_error(Throwable $exception): string
{
    if ((int) $exception->getCode() === 403) {
        return 'Brak dostępu.';
    }

    return str_replace(['PDP API', 'PDP'], 'PKP PLK', $exception->getMessage());
}

$today = (new DateTimeImmutable('now', new DateTimeZone('Europe/Warsaw')))->format('Y-m-d');
$from = wiwiwi_date_param('from', (new DateTimeImmutable($today))->modify('-30 days')->format('Y-m-d'));
$to = wiwiwi_date_param('to', $today);
$error = null;
$info = [];
$usage = [];

try {
    wiwiwi_api_require('AppConfig.php');
    wiwiwi_api_require('config.php');
    wiwiwi_api_require('hop/Config.php');
    wiwiwi_api_require('PdpClient.php');

    $token = isset($_GET['rp3']) && !is_array($_GET['rp3']) ? (string) $_GET['rp3'] : '';
    if (!wiwiwi_token_is_valid($token)) {
        http_response_code(403);
        throw new RuntimeException('Brak dostępu.', 403);
    }

    if (PDP_API_KEY === '' || strpos(PDP_API_KEY, 'WSTAW_') === 0) {
        throw new RuntimeException('Brak klucza dostępu do danych PKP PLK.');
    }

    $productionApiKey = wiwiwi_config_value_from_file('PDP_API_KEY');
    if ($productionApiKey === '' || strpos($productionApiKey, 'WSTAW_') === 0) {
        throw new RuntimeException('Brak klucza dostępu do danych PKP PLK w produkcyjnym api/config.local.php.');
    }

    $client = new PdpClient(PDP_API_BASE_URL, $productionApiKey, PDP_CACHE_DIR);
    $infoPayload = $client->get('/api/v1/apikey/info', [], 60);
    $usagePayload = $client->get('/api/v1/apikey/usage', [
        'fromDate' => $from . 'T00:00:00',
        'toDate' => $to . 'T23:59:59',
    ], 60);

    $info = is_array($infoPayload['data'] ?? null) ? $infoPayload['data'] : [];
    $usage = is_array($usagePayload['data'] ?? null) ? $usagePayload['data'] : [];
} catch (Throwable $exception) {
    $error = wiwiwi_public_error($exception);
}

$dailyUsage = is_array($usage['dailyUsage'] ?? null) ? $usage['dailyUsage'] : [];
$topEndpoints = is_array($usage['topEndpoints'] ?? null) ? $usage['topEndpoints'] : [];
$todayUsage = 0;
$todayErrors = 0;

foreach ($dailyUsage as $row) {
    if ((string) ($row['date'] ?? '') === $today) {
        $todayUsage = (int) ($row['requestCount'] ?? 0);
        $todayErrors = (int) ($row['errorCount'] ?? 0);
        break;
    }
}

$dailyLimit = (int) ($info['dailyRateLimit'] ?? 0);
$hourlyLimit = (int) ($info['hourlyRateLimit'] ?? 0);
?>
<!doctype html>
<html lang="pl">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow, noarchive, nosnippet">
  <title>Raport użycia klucza PKP PLK | kolej.live</title>
  <style>
    :root { color-scheme: light; font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; --bg: #f6f7f8; --surface: #fff; --ink: #111317; --muted: #667085; --line: #dce1e6; --red: #c7222a; --green: #087a54; --amber: #b7791f; }
    * { box-sizing: border-box; }
    body { margin: 0; background: var(--bg); color: var(--ink); }
    main { width: min(1180px, calc(100% - 28px)); margin: 0 auto; padding: 28px 0 46px; }
    header { display: flex; justify-content: space-between; gap: 16px; align-items: center; margin-bottom: 18px; }
    h1 { margin: 0; font-size: clamp(28px, 4vw, 42px); line-height: 1; }
    p { margin: 4px 0 0; color: var(--muted); line-height: 1.45; }
    a { color: var(--red); font-weight: 800; text-decoration: none; }
    form { display: flex; flex-wrap: wrap; gap: 10px; align-items: end; padding: 14px; margin-bottom: 16px; background: var(--surface); border: 1px solid var(--line); border-radius: 8px; }
    label { display: grid; gap: 6px; color: var(--muted); font-size: 12px; font-weight: 800; text-transform: uppercase; }
    input, button { min-height: 40px; font: inherit; border-radius: 8px; }
    input { padding: 0 10px; border: 1px solid var(--line); color: var(--ink); background: var(--surface); }
    button { padding: 0 14px; border: 0; color: #fff; background: var(--red); font-weight: 800; cursor: pointer; }
    .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); gap: 12px; margin-bottom: 16px; }
    .card, .panel { padding: 14px; background: var(--surface); border: 1px solid var(--line); border-radius: 8px; }
    .card span { display: block; color: var(--muted); font-size: 12px; font-weight: 800; text-transform: uppercase; }
    .card strong { display: block; margin-top: 4px; font-size: 24px; }
    .ok { color: var(--green); }
    .warn { color: var(--amber); }
    .bad { color: var(--red); }
    .panel { margin-top: 16px; overflow: auto; }
    h2 { margin: 0 0 12px; font-size: 20px; }
    table { width: 100%; border-collapse: collapse; min-width: 720px; }
    th, td { padding: 10px 12px; border-bottom: 1px solid var(--line); text-align: left; vertical-align: top; }
    th { color: var(--muted); font-size: 12px; text-transform: uppercase; }
    code { color: var(--red); overflow-wrap: anywhere; white-space: normal; }
    .error { padding: 14px; color: var(--red); background: #fff3f4; border: 1px solid #f4b6ba; border-radius: 8px; }
  </style>
</head>
<body>
  <main>
    <header>
      <div>
        <h1>Raport użycia klucza PKP PLK</h1>
        <p>Ręczna kontrola limitów i najcięższych endpointów używanych przez kolej.live.</p>
      </div>
      <a href="/">kolej.live</a>
    </header>

    <form method="get">
      <input type="hidden" name="rp3" value="<?= wiwiwi_e(isset($_GET['rp3']) && !is_array($_GET['rp3']) ? (string) $_GET['rp3'] : '') ?>">
      <label>Od <input type="date" name="from" value="<?= wiwiwi_e($from) ?>"></label>
      <label>Do <input type="date" name="to" value="<?= wiwiwi_e($to) ?>"></label>
      <button type="submit">Odśwież</button>
    </form>

    <?php if ($error !== null): ?>
      <div class="error"><?= wiwiwi_e($error) ?></div>
    <?php else: ?>
      <section class="grid" aria-label="Podsumowanie użycia">
        <div class="card"><span>Dziś</span><strong class="<?= $dailyLimit > 0 && $todayUsage / max(1, $dailyLimit) > .8 ? 'bad' : 'ok' ?>"><?= wiwiwi_e(wiwiwi_number($todayUsage)) ?></strong></div>
        <div class="card"><span>Limit dzienny</span><strong><?= wiwiwi_e(wiwiwi_number($dailyLimit)) ?></strong><p><?= wiwiwi_e(wiwiwi_percent($todayUsage, $dailyLimit)) ?> wykorzystania</p></div>
        <div class="card"><span>Limit godzinowy</span><strong><?= wiwiwi_e(wiwiwi_number($hourlyLimit)) ?></strong></div>
        <div class="card"><span>Błędy dziś</span><strong class="<?= $todayErrors > 0 ? 'warn' : 'ok' ?>"><?= wiwiwi_e(wiwiwi_number($todayErrors)) ?></strong></div>
        <div class="card"><span>Łącznie w okresie</span><strong><?= wiwiwi_e(wiwiwi_number($usage['totalRequests'] ?? 0)) ?></strong></div>
        <div class="card"><span>Ostatnie użycie</span><strong><?= wiwiwi_e($info['lastUsedAt'] ?? '-') ?></strong></div>
      </section>

      <section class="panel">
        <h2>Użycie dzienne</h2>
        <table>
          <thead><tr><th>Data</th><th>Zapytania</th><th>Błędy</th><th>Śr. czas odpowiedzi</th><th>Limit dzienny</th></tr></thead>
          <tbody>
            <?php foreach ($dailyUsage as $row): ?>
              <tr>
                <td><?= wiwiwi_e($row['date'] ?? '-') ?></td>
                <td><?= wiwiwi_e(wiwiwi_number($row['requestCount'] ?? 0)) ?></td>
                <td><?= wiwiwi_e(wiwiwi_number($row['errorCount'] ?? 0)) ?></td>
                <td><?= wiwiwi_e(number_format((float) ($row['averageResponseTime'] ?? 0), 0, ',', ' ')) ?> ms</td>
                <td><?= wiwiwi_e(wiwiwi_percent($row['requestCount'] ?? 0, $dailyLimit)) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </section>

      <section class="panel">
        <h2>Najczęściej używane endpointy</h2>
        <table>
          <thead><tr><th>Endpoint</th><th>Zapytania</th></tr></thead>
          <tbody>
            <?php foreach ($topEndpoints as $row): ?>
              <tr>
                <td><code><?= wiwiwi_e($row['endpoint'] ?? '-') ?></code></td>
                <td><?= wiwiwi_e(wiwiwi_number($row['requestCount'] ?? 0)) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </section>
    <?php endif; ?>
  </main>
</body>
</html>
