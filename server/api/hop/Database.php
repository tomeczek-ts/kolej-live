<?php

declare(strict_types=1);

require_once __DIR__ . '/Config.php';

function hop_pdo(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dsn = 'mysql:host=' . HOP_DB_HOST . ';dbname=' . HOP_DB_NAME . ';charset=utf8mb4';
    $pdo = new PDO($dsn, HOP_DB_USER, HOP_DB_PASSWORD, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    return $pdo;
}

function hop_mysql_datetime($value): ?string
{
    if ($value === null || $value === '') {
        return null;
    }

    try {
        return (new DateTimeImmutable((string) $value))->format('Y-m-d H:i:s');
    } catch (Throwable $exception) {
        return null;
    }
}

function hop_clean($value): ?string
{
    if ($value === null) {
        return null;
    }

    $string = trim((string) $value);

    return $string === '' ? null : $string;
}

function hop_int_or_null($value): ?int
{
    return is_numeric($value) ? (int) $value : null;
}

function hop_bool_int($value): int
{
    return !empty($value) ? 1 : 0;
}
