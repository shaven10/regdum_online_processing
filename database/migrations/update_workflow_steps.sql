-- Four-step document request workflow
-- 1. Student submits online
-- 2. Registrar sets requirements and confirms request
-- 3. Student completes requirements → Registrar re-evaluates → payment allowed
-- 4. Payment verified → Registrar assigns staff and release date → processing

ALTER TABLE requests MODIFY status ENUM(
    'submitted','under_review','awaiting_requirements','requirements_submitted',
    'needs_revision','requirements_verified','payment_verified','processing',
    'ready_for_pickup','shipped','completed','rejected'
) DEFAULT 'submitted';

ALTER TABLE requests
    ADD COLUMN IF NOT EXISTS release_date DATE NULL AFTER pickup_time,
    ADD COLUMN IF NOT EXISTS release_time TIME NULL AFTER release_date;

CREATE TABLE IF NOT EXISTS request_assigned_requirements (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    request_id INT UNSIGNED NOT NULL,
    requirement_name VARCHAR(255) NOT NULL,
    description TEXT,
    requires_upload TINYINT(1) DEFAULT 1,
    document_id INT UNSIGNED NULL,
    is_met TINYINT(1) DEFAULT 0,
    verified_by INT UNSIGNED NULL,
    verified_at DATETIME NULL,
    sort_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (request_id) REFERENCES requests(id) ON DELETE CASCADE,
    FOREIGN KEY (document_id) REFERENCES request_documents(id) ON DELETE SET NULL,
    FOREIGN KEY (verified_by) REFERENCES users(id) ON DELETE SET NULL
);
