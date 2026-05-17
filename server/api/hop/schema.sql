CREATE TABLE IF NOT EXISTS hop_collection_runs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  observation_date DATE NOT NULL,
  started_at DATETIME NOT NULL,
  finished_at DATETIME NULL,
  status ENUM('running', 'success', 'failed') NOT NULL DEFAULT 'running',
  source_generated_at DATETIME NULL,
  pages_fetched INT UNSIGNED NOT NULL DEFAULT 0,
  trains_seen INT UNSIGNED NOT NULL DEFAULT 0,
  train_runs_upserted INT UNSIGNED NOT NULL DEFAULT 0,
  station_observations_upserted INT UNSIGNED NOT NULL DEFAULT 0,
  error_message TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_hop_collection_runs_date (observation_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS hop_stations (
  station_id INT UNSIGNED NOT NULL,
  name VARCHAR(255) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (station_id),
  KEY idx_hop_stations_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS hop_train_runs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  operating_date DATE NOT NULL,
  schedule_id INT UNSIGNED NOT NULL,
  order_id INT UNSIGNED NOT NULL,
  train_order_id INT UNSIGNED NOT NULL DEFAULT 0,
  service_key CHAR(40) NOT NULL,
  label VARCHAR(255) NOT NULL,
  train_number VARCHAR(64) NULL,
  category VARCHAR(32) NULL,
  train_name VARCHAR(255) NULL,
  carrier_code VARCHAR(64) NULL,
  origin_station_id INT UNSIGNED NULL,
  destination_station_id INT UNSIGNED NULL,
  origin_name VARCHAR(255) NULL,
  destination_name VARCHAR(255) NULL,
  train_status VARCHAR(8) NULL,
  station_count INT UNSIGNED NOT NULL DEFAULT 0,
  first_departure DATETIME NULL,
  last_arrival DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_hop_train_run (operating_date, schedule_id, order_id, train_order_id),
  KEY idx_hop_train_service_date (service_key, operating_date),
  KEY idx_hop_train_number_date (train_number, operating_date),
  KEY idx_hop_train_label (label),
  CONSTRAINT fk_hop_train_origin
    FOREIGN KEY (origin_station_id) REFERENCES hop_stations (station_id)
    ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT fk_hop_train_destination
    FOREIGN KEY (destination_station_id) REFERENCES hop_stations (station_id)
    ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS hop_station_observations (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  observation_date DATE NOT NULL,
  run_id BIGINT UNSIGNED NULL,
  train_run_id BIGINT UNSIGNED NOT NULL,
  station_id INT UNSIGNED NOT NULL,
  sequence_number INT UNSIGNED NOT NULL DEFAULT 0,
  planned_arrival DATETIME NULL,
  planned_departure DATETIME NULL,
  actual_arrival DATETIME NULL,
  actual_departure DATETIME NULL,
  arrival_delay_minutes INT NULL,
  departure_delay_minutes INT NULL,
  max_delay_minutes INT NULL,
  is_confirmed TINYINT(1) NOT NULL DEFAULT 0,
  is_cancelled TINYINT(1) NOT NULL DEFAULT 0,
  observed_at DATETIME NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_hop_station_observation (observation_date, train_run_id, station_id, sequence_number),
  KEY idx_hop_obs_station_date (station_id, observation_date),
  KEY idx_hop_obs_train_date (train_run_id, observation_date),
  KEY idx_hop_obs_delay (max_delay_minutes),
  CONSTRAINT fk_hop_obs_run
    FOREIGN KEY (run_id) REFERENCES hop_collection_runs (id)
    ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT fk_hop_obs_train
    FOREIGN KEY (train_run_id) REFERENCES hop_train_runs (id)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_hop_obs_station
    FOREIGN KEY (station_id) REFERENCES hop_stations (station_id)
    ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS hop_train_searches (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  service_key CHAR(40) NOT NULL,
  searched_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_hop_searches_date_service (searched_at, service_key),
  KEY idx_hop_searches_service (service_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
