-- Online clearance integration
USE regdum_credentials;

CREATE TABLE IF NOT EXISTS clearance_departments (
    id TINYINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(50) NOT NULL UNIQUE,
    name VARCHAR(150) NOT NULL,
    icon VARCHAR(50) DEFAULT 'fa-check',
    sort_order INT DEFAULT 0,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

INSERT IGNORE INTO clearance_departments (code, name, icon, sort_order) VALUES
('guidance', 'Guidance Office', 'fa-hands-helping', 1),
('library', 'Library', 'fa-book', 2),
('student_affairs', 'Student Affairs', 'fa-users', 3),
('program_chair', 'Program Chair', 'fa-chalkboard-teacher', 4),
('campus_director', 'Campus Director', 'fa-user-tie', 5);

CREATE TABLE IF NOT EXISTS request_clearances (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    request_id INT UNSIGNED NOT NULL,
    department_id TINYINT UNSIGNED NOT NULL,
    status ENUM('pending','cleared','on_hold') DEFAULT 'pending',
    cleared_by INT UNSIGNED NULL,
    cleared_at DATETIME NULL,
    remarks TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (request_id) REFERENCES requests(id) ON DELETE CASCADE,
    FOREIGN KEY (department_id) REFERENCES clearance_departments(id) ON DELETE CASCADE,
    FOREIGN KEY (cleared_by) REFERENCES users(id) ON DELETE SET NULL,
    UNIQUE KEY uk_request_department (request_id, department_id)
);

INSERT IGNORE INTO roles (name, description) VALUES
('clearance_officer', 'Clearance Signing Officer');

ALTER TABLE users ADD COLUMN IF NOT EXISTS clearance_department_id TINYINT UNSIGNED NULL AFTER role_id;
