-- Fix audit_logs NOT NULL columns that break logout/login audit inserts
ALTER TABLE audit_logs
    MODIFY old_values LONGTEXT NULL,
    MODIFY new_values LONGTEXT NULL,
    MODIFY ip_address VARCHAR(45) NULL,
    MODIFY user_agent TEXT NULL;
