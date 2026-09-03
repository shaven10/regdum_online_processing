-- Onsite walk-in credential requests created by registrar
-- Safe to run once; tracked in schema_migrations by install.php

ALTER TABLE requests
    ADD COLUMN IF NOT EXISTS request_channel ENUM('online','onsite') NOT NULL DEFAULT 'online' AFTER notes;

ALTER TABLE requests
    ADD COLUMN IF NOT EXISTS created_by INT UNSIGNED NULL AFTER request_channel;
