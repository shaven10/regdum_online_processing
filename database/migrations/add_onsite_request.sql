-- Onsite walk-in credential requests created by registrar
-- Safe to run once; app also auto-migrates via ensureOnsiteRequestSchema()
USE regdum_credentials;

-- Run these only if columns are missing (check with SHOW COLUMNS FROM requests):
ALTER TABLE requests
    ADD COLUMN request_channel ENUM('online','onsite') NOT NULL DEFAULT 'online' AFTER notes;

ALTER TABLE requests
    ADD COLUMN created_by INT UNSIGNED NULL AFTER request_channel;
