<?php

declare(strict_types=1);

final class PdpApiException extends RuntimeException
{
    private int $statusCode;
    private $payload;

    public function __construct(string $message, int $statusCode = 500, $payload = null)
    {
        parent::__construct($message);
        $this->statusCode = $statusCode;
        $this->payload = $payload;
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }

    public function payload()
    {
        return $this->payload;
    }
}

final class PdpClient
{
    private string $baseUrl;
    private string $apiKey;
    private string $cacheDir;

    public function __construct(string $baseUrl, string $apiKey, string $cacheDir)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->apiKey = $apiKey;
        $this->cacheDir = $cacheDir;
    }

    public function get(string $path, array $query = [], int $ttlSeconds = 20): array
    {
        // Kazde zapytanie PDP przechodzi przez to miejsce:
        // 1. usuwamy puste parametry, zeby nie wysylac do PDP np. `stations=` bez wartosci,
        // 2. budujemy pelny URL na podstawie PDP_API_BASE_URL i sciezki z pliku `pdp/*.php`,
        // 3. sprawdzamy lokalny cache po URL, dzieki czemu identyczne zapytania nie zuzywaja limitu API,
        // 4. gdy cache jest pusty albo wygasl, wysylamy GET z naglowkiem `X-API-Key`.
        $query = $this->cleanQuery($query);
        $url = $this->buildUrl($path, $query);
        $cacheKey = sha1($url);

        if ($ttlSeconds > 0) {
            $cached = $this->readCache($cacheKey);
            if ($cached !== null) {
                return $cached;
            }
        }

        $payload = $this->requestJson($url);

        if ($ttlSeconds > 0) {
            $this->writeCache($cacheKey, $payload, $ttlSeconds);
        }

        return $payload;
    }

    private function buildUrl(string $path, array $query): string
    {
        $url = $this->baseUrl . '/' . ltrim($path, '/');
        if ($query !== []) {
            $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }

        return $url;
    }

    private function cleanQuery(array $query): array
    {
        $clean = [];

        foreach ($query as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            if (is_bool($value)) {
                $clean[$key] = $value ? 'true' : 'false';
                continue;
            }

            if (is_array($value)) {
                $value = implode(',', array_filter($value, static fn($part) => $part !== null && $part !== ''));
            }

            $clean[$key] = $value;
        }

        return $clean;
    }

    private function requestJson(string $url): array
    {
        // PDP wymaga klucza w naglowku X-API-Key. Klucz nigdy nie trafia do Reacta ani do odpowiedzi JSON.
        // User-Agent identyfikuje aplikacje po stronie PDP i ulatwia diagnostyke po logach dostawcy API.
        $headers = [
            'Accept: application/json',
            'X-API-Key: ' . $this->apiKey,
            'User-Agent: kolej.live/0.1',
        ];

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_CONNECTTIMEOUT => 8,
                CURLOPT_TIMEOUT => PDP_HTTP_TIMEOUT_SECONDS,
            ]);

            $body = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            if ($body === false) {
                $this->logApiError('transport_error', $url, 502, [
                    'curlError' => $error,
                ]);
                throw new PdpApiException('Nie udalo sie polaczyc z PDP API: ' . $error, 502);
            }
        } else {
            $context = stream_context_create([
                'http' => [
                    'method' => 'GET',
                    'header' => implode("\r\n", $headers),
                    'timeout' => PDP_HTTP_TIMEOUT_SECONDS,
                    'ignore_errors' => true,
                ],
            ]);

            $body = file_get_contents($url, false, $context);
            $status = 0;

            if (isset($http_response_header) && is_array($http_response_header)) {
                foreach ($http_response_header as $headerLine) {
                    if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $headerLine, $matches)) {
                        $status = (int) $matches[1];
                        break;
                    }
                }
            }

            if ($body === false) {
                $this->logApiError('transport_error', $url, 502, [
                    'streamError' => error_get_last(),
                ]);
                throw new PdpApiException('Nie udalo sie polaczyc z PDP API.', 502);
            }
        }

        $decoded = json_decode((string) $body, true);

        if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
            $payload = [
                'status' => $status,
                'jsonError' => json_last_error_msg(),
                'bodySnippet' => substr((string) $body, 0, 1200),
            ];
            $this->logApiError('invalid_json', $url, 502, $payload);
            throw new PdpApiException('PDP API zwrocilo niepoprawny JSON.', 502, [
                'status' => $status,
                'jsonError' => json_last_error_msg(),
            ]);
        }

        if ($status >= 400) {
            $this->logApiError('http_error', $url, $status, $decoded);
            throw new PdpApiException('PDP API zwrocilo blad HTTP ' . $status . '.', $status, $decoded);
        }

        return is_array($decoded) ? $decoded : [];
    }

    private function logApiError(string $reason, string $url, int $status, $payload = null): void
    {
        $file = $this->errorLogFile();
        if ($file === '') {
            return;
        }

        $dir = dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        if (!is_dir($dir) || !is_writable($dir)) {
            error_log('[pdp_api_error] ' . $reason . ' HTTP ' . $status . ' ' . $url);
            return;
        }

        $this->protectLogDirectory($dir);

        $entry = [
            'time' => (new DateTimeImmutable('now', new DateTimeZone('Europe/Warsaw')))->format('Y-m-d H:i:s'),
            'reason' => $reason,
            'status' => $status,
            'url' => $url,
            'payload' => $this->redactLogValue($payload),
        ];
        $line = json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR) . PHP_EOL;

        @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
    }

    private function errorLogFile(): string
    {
        if (defined('PDP_ERROR_LOG_FILE') && is_string(PDP_ERROR_LOG_FILE)) {
            return PDP_ERROR_LOG_FILE;
        }

        return $this->cacheDir . '/logs/pdp_api_errors.log';
    }

    private function protectLogDirectory(string $dir): void
    {
        $htaccess = $dir . '/.htaccess';
        if (!is_file($htaccess)) {
            @file_put_contents($htaccess, "Require all denied\nDeny from all\n");
        }

        $index = $dir . '/index.html';
        if (!is_file($index)) {
            @file_put_contents($index, '');
        }
    }

    private function redactLogValue($value)
    {
        if (is_array($value)) {
            $redacted = [];
            foreach ($value as $key => $item) {
                if (preg_match('/api[_-]?key|authorization|password|passwd|secret|token/i', (string) $key)) {
                    $redacted[$key] = '[redacted]';
                    continue;
                }

                $redacted[$key] = $this->redactLogValue($item);
            }

            return $redacted;
        }

        if (is_object($value)) {
            return '[object ' . get_class($value) . ']';
        }

        if (is_string($value) && strlen($value) > 1200) {
            return substr($value, 0, 1200) . '...[truncated]';
        }

        return $value;
    }

    private function readCache(string $key): ?array
    {
        $file = $this->cacheDir . '/' . $key . '.json';
        if (!is_file($file)) {
            return null;
        }

        $raw = file_get_contents($file);
        if ($raw === false) {
            return null;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || !isset($decoded['expiresAt'], $decoded['payload'])) {
            return null;
        }

        if ((int) $decoded['expiresAt'] < time()) {
            @unlink($file);
            return null;
        }

        return is_array($decoded['payload']) ? $decoded['payload'] : null;
    }

    private function writeCache(string $key, array $payload, int $ttlSeconds): void
    {
        if (!is_dir($this->cacheDir)) {
            @mkdir($this->cacheDir, 0775, true);
        }

        if (!is_dir($this->cacheDir) || !is_writable($this->cacheDir)) {
            return;
        }

        $file = $this->cacheDir . '/' . $key . '.json';
        $data = [
            'expiresAt' => time() + $ttlSeconds,
            'payload' => $payload,
        ];

        @file_put_contents($file, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
