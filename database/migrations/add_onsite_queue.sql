-- Onsite queuing / priority numbers for registrar windows
-- Safe to run once; tracked in schema_migrations by install.php

CREATE TABLE IF NOT EXISTS queue_windows (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    window_number SMALLINT UNSIGNED NOT NULL,
    name VARCHAR(80) NOT NULL,
    assigned_user_id INT UNSIGNED NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_queue_windows_number (window_number),
    KEY idx_queue_windows_user (assigned_user_id),
    KEY idx_queue_windows_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS queue_daily_counters (
    queue_date DATE NOT NULL PRIMARY KEY,
    last_number INT UNSIGNED NOT NULL DEFAULT 0,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS queue_tickets (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    queue_date DATE NOT NULL,
    ticket_number INT UNSIGNED NOT NULL,
    ticket_code VARCHAR(20) NOT NULL,
    service_type VARCHAR(40) NOT NULL DEFAULT 'document_processing',
    status VARCHAR(20) NOT NULL DEFAULT 'waiting',
    window_id INT UNSIGNED NULL,
    request_id INT UNSIGNED NULL,
    requestor_name VARCHAR(160) NULL,
    student_id VARCHAR(50) NULL,
    request_number VARCHAR(40) NULL,
    notes VARCHAR(255) NULL,
    called_at DATETIME NULL,
    served_at DATETIME NULL,
    completed_at DATETIME NULL,
    called_by INT UNSIGNED NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_queue_tickets_day_number (queue_date, ticket_number),
    KEY idx_queue_tickets_day_status (queue_date, status),
    KEY idx_queue_tickets_window (window_id, status),
    KEY idx_queue_tickets_code (ticket_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
