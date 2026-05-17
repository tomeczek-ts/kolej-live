<?php

declare(strict_types=1);

function hop_start_run(PDO $pdo, string $date): int
{
    $stmt = $pdo->prepare(
        "INSERT INTO hop_collection_runs (observation_date, started_at, status, error_message)
         VALUES (:observation_date, NOW(), 'running', NULL)
         ON DUPLICATE KEY UPDATE
           id = LAST_INSERT_ID(id),
           started_at = VALUES(started_at),
           finished_at = NULL,
           status = 'running',
           error_message = NULL"
    );
    $stmt->execute(['observation_date' => $date]);

    return (int) $pdo->lastInsertId();
}

function hop_finish_run(PDO $pdo, int $runId, array $summary): void
{
    $stmt = $pdo->prepare(
        "UPDATE hop_collection_runs
         SET finished_at = NOW(),
             status = 'success',
             source_generated_at = :source_generated_at,
             pages_fetched = :pages_fetched,
             trains_seen = :trains_seen,
             train_runs_upserted = :train_runs_upserted,
             station_observations_upserted = :station_observations_upserted,
             error_message = NULL
         WHERE id = :id"
    );
    $stmt->execute([
        'id' => $runId,
        'source_generated_at' => $summary['sourceGeneratedAt'] ?? null,
        'pages_fetched' => $summary['pagesFetched'] ?? 0,
        'trains_seen' => $summary['trainsSeen'] ?? 0,
        'train_runs_upserted' => $summary['trainRunsUpserted'] ?? 0,
        'station_observations_upserted' => $summary['stationObservationsUpserted'] ?? 0,
    ]);
}

function hop_fail_run(PDO $pdo, int $runId, string $message): void
{
    $stmt = $pdo->prepare(
        "UPDATE hop_collection_runs
         SET finished_at = NOW(), status = 'failed', error_message = :error_message
         WHERE id = :id"
    );
    $stmt->execute([
        'id' => $runId,
        'error_message' => substr($message, 0, 6000),
    ]);
}

function hop_upsert_station(PDO $pdo, int $stationId, string $name): void
{
    $stmt = $pdo->prepare(
        "INSERT INTO hop_stations (station_id, name)
         VALUES (:station_id, :name)
         ON DUPLICATE KEY UPDATE name = VALUES(name), updated_at = CURRENT_TIMESTAMP"
    );
    $stmt->execute([
        'station_id' => $stationId,
        'name' => $name,
    ]);
}

function hop_upsert_train_run(PDO $pdo, array $train): int
{
    $stmt = $pdo->prepare(
        "INSERT INTO hop_train_runs (
           operating_date, schedule_id, order_id, train_order_id, service_key,
           label, train_number, category, train_name, carrier_code,
           origin_station_id, destination_station_id, origin_name, destination_name,
           train_status, station_count, first_departure, last_arrival
         ) VALUES (
           :operating_date, :schedule_id, :order_id, :train_order_id, :service_key,
           :label, :train_number, :category, :train_name, :carrier_code,
           :origin_station_id, :destination_station_id, :origin_name, :destination_name,
           :train_status, :station_count, :first_departure, :last_arrival
         )
         ON DUPLICATE KEY UPDATE
           id = LAST_INSERT_ID(id),
           service_key = VALUES(service_key),
           label = VALUES(label),
           train_number = VALUES(train_number),
           category = VALUES(category),
           train_name = VALUES(train_name),
           carrier_code = VALUES(carrier_code),
           origin_station_id = VALUES(origin_station_id),
           destination_station_id = VALUES(destination_station_id),
           origin_name = VALUES(origin_name),
           destination_name = VALUES(destination_name),
           train_status = VALUES(train_status),
           station_count = VALUES(station_count),
           first_departure = VALUES(first_departure),
           last_arrival = VALUES(last_arrival),
           updated_at = CURRENT_TIMESTAMP"
    );
    $stmt->execute($train);

    return (int) $pdo->lastInsertId();
}

function hop_upsert_station_observation(PDO $pdo, array $observation): void
{
    $stmt = $pdo->prepare(
        "INSERT INTO hop_station_observations (
           observation_date, run_id, train_run_id, station_id, sequence_number,
           planned_arrival, planned_departure, actual_arrival, actual_departure,
           arrival_delay_minutes, departure_delay_minutes, max_delay_minutes,
           is_confirmed, is_cancelled, observed_at
         ) VALUES (
           :observation_date, :run_id, :train_run_id, :station_id, :sequence_number,
           :planned_arrival, :planned_departure, :actual_arrival, :actual_departure,
           :arrival_delay_minutes, :departure_delay_minutes, :max_delay_minutes,
           :is_confirmed, :is_cancelled, NOW()
         )
         ON DUPLICATE KEY UPDATE
           run_id = VALUES(run_id),
           planned_arrival = VALUES(planned_arrival),
           planned_departure = VALUES(planned_departure),
           actual_arrival = VALUES(actual_arrival),
           actual_departure = VALUES(actual_departure),
           arrival_delay_minutes = VALUES(arrival_delay_minutes),
           departure_delay_minutes = VALUES(departure_delay_minutes),
           max_delay_minutes = VALUES(max_delay_minutes),
           is_confirmed = VALUES(is_confirmed),
           is_cancelled = VALUES(is_cancelled),
           observed_at = VALUES(observed_at),
           updated_at = CURRENT_TIMESTAMP"
    );
    $stmt->execute($observation);
}
