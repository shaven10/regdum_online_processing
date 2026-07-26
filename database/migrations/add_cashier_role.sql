-- Add Cashier role for existing installations
-- Run in phpMyAdmin (SQL tab) or: mysql -u root regdum_credentials < add_cashier_role.sql

USE regdum_credentials;

INSERT IGNORE INTO roles (name, description) VALUES
('cashier', 'Payment Verification Cashier');

-- Verify role was added
SELECT id, name, description FROM roles WHERE name = 'cashier';
