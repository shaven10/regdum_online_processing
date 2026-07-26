-- Registrar compliance verification tables and statuses
USE regdum_credentials;

ALTER TABLE requests MODIFY status ENUM(
    'submitted','under_review','needs_revision','requirements_verified',
    'payment_verified','processing','ready_for_pickup','shipped','completed','rejected'
) DEFAULT 'submitted';

CREATE TABLE IF NOT EXISTS document_requirements (
    id TINYINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    document_type_id TINYINT UNSIGNED NULL,
    requirement_name VARCHAR(255) NOT NULL,
    description TEXT,
    is_required TINYINT(1) DEFAULT 1,
    sort_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (document_type_id) REFERENCES document_types(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS request_compliance (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    request_id INT UNSIGNED NOT NULL,
    requirement_id TINYINT UNSIGNED NOT NULL,
    is_met TINYINT(1) DEFAULT 0,
    verified_by INT UNSIGNED NULL,
    verified_at DATETIME NULL,
    remarks TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (request_id) REFERENCES requests(id) ON DELETE CASCADE,
    FOREIGN KEY (requirement_id) REFERENCES document_requirements(id) ON DELETE CASCADE,
    FOREIGN KEY (verified_by) REFERENCES users(id) ON DELETE SET NULL,
    UNIQUE KEY uk_request_requirement (request_id, requirement_id)
);

CREATE TABLE IF NOT EXISTS request_compliance_summary (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    request_id INT UNSIGNED NOT NULL UNIQUE,
    compliance_status ENUM('pending','compliant','non_compliant','needs_revision') DEFAULT 'pending',
    verified_by INT UNSIGNED NULL,
    verified_at DATETIME NULL,
    remarks TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (request_id) REFERENCES requests(id) ON DELETE CASCADE,
    FOREIGN KEY (verified_by) REFERENCES users(id) ON DELETE SET NULL
);
