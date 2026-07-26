-- Registrars Office — Online Credentials Processing System
-- Database Schema

CREATE DATABASE IF NOT EXISTS regdum_credentials CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE regdum_credentials;

-- Roles
CREATE TABLE roles (
    id TINYINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) NOT NULL UNIQUE,
    description VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

INSERT INTO roles (name, description) VALUES
('student', 'Student/Requester'),
('staff', 'Registrar Staff'),
('registrar', 'Registrar Officer'),
('admin', 'System Administrator'),
('cashier', 'Payment Verification Cashier'),
('clearance_officer', 'Clearance Signing Officer');

-- Users
CREATE TABLE users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    role_id TINYINT UNSIGNED NOT NULL DEFAULT 1,
    clearance_department_id TINYINT UNSIGNED NULL,
    email VARCHAR(255) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    student_id VARCHAR(50) UNIQUE,
    first_name VARCHAR(100) NOT NULL,
    last_name VARCHAR(100) NOT NULL,
    middle_name VARCHAR(100),
    phone VARCHAR(20),
    is_active TINYINT(1) DEFAULT 1,
    email_verified TINYINT(1) DEFAULT 0,
    mfa_enabled TINYINT(1) DEFAULT 0,
    mfa_secret VARCHAR(255),
    reset_token VARCHAR(255),
    reset_token_expires DATETIME,
    last_login DATETIME,
    privacy_consent_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (role_id) REFERENCES roles(id)
);

-- Academic programs (courses)
CREATE TABLE academic_programs (
    id TINYINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(20) NOT NULL UNIQUE,
    name VARCHAR(150) NOT NULL,
    description TEXT,
    is_active TINYINT(1) DEFAULT 1,
    sort_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

INSERT INTO academic_programs (code, name, description, sort_order) VALUES
('BSIT', 'BS Information Technology', 'Information Technology program', 1),
('BSCS', 'BS Computer Science', 'Computer Science program', 2),
('BSBA', 'BS Business Administration', 'Business Administration program', 3),
('BSA', 'BS Accountancy', 'Accountancy program', 4),
('BSED', 'BS Education', 'Education program', 5),
('BSN', 'BS Nursing', 'Nursing program', 6),
('BSCPE', 'BS Computer Engineering', 'Computer Engineering program', 7),
('BSHM', 'BS Hospitality Management', 'Hospitality Management program', 8);

CREATE TABLE campuses (
    id TINYINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(20) NOT NULL UNIQUE,
    name VARCHAR(150) NOT NULL,
    description TEXT,
    is_active TINYINT(1) DEFAULT 1,
    sort_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

INSERT INTO campuses (code, name, description, sort_order) VALUES
('MAIN', 'Main Campus', 'Central registrar campus', 1),
('NORTH', 'North Campus', 'Northern extension campus', 2),
('SOUTH', 'South Campus', 'Southern extension campus', 3);

-- Student profiles
CREATE TABLE student_profiles (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL UNIQUE,
    course VARCHAR(150),
    course_id TINYINT UNSIGNED NULL,
    year_level VARCHAR(20),
    current_academic_year VARCHAR(20) NULL,
    current_semester ENUM('1st_semester','2nd_semester','summer') NULL,
    section VARCHAR(50),
    birth_date DATE,
    valid_id_path VARCHAR(255) NULL,
    valid_id_original_name VARCHAR(255) NULL,
    address TEXT,
    city VARCHAR(100),
    province VARCHAR(100),
    postal_code VARCHAR(10),
    emergency_contact VARCHAR(100),
    emergency_phone VARCHAR(20),
    enrollment_status ENUM('enrolled','graduated','inactive') DEFAULT 'enrolled',
    graduation_date DATE,
    origin_campus_id TINYINT UNSIGNED NULL,
    year_graduated SMALLINT UNSIGNED NULL,
    last_school_year VARCHAR(20) NULL,
    employment_status ENUM('employed','self_employed','unemployed','seeking_employment','further_studies') NULL,
    employer_name VARCHAR(200) NULL,
    job_title VARCHAR(150) NULL,
    employer_address TEXT NULL,
    employment_start_date DATE NULL,
    avatar VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Document types
CREATE TABLE document_types (
    id TINYINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    code VARCHAR(20) NOT NULL UNIQUE,
    description TEXT,
    base_fee DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    per_copy_fee DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    processing_days INT DEFAULT 3,
    requires_upload TINYINT(1) DEFAULT 0,
    requires_documentary_stamp TINYINT(1) NOT NULL DEFAULT 0,
    fee_per_set TINYINT(1) NOT NULL DEFAULT 0,
    requires_term_info TINYINT(1) NOT NULL DEFAULT 0,
    requires_soa_info TINYINT(1) NOT NULL DEFAULT 0,
    requires_auth_document_type TINYINT(1) NOT NULL DEFAULT 0,
    is_active TINYINT(1) DEFAULT 1,
    requirements_required TINYINT(1) NOT NULL DEFAULT 1,
    second_copy_requirements_required TINYINT(1) NOT NULL DEFAULT 1,
    assignment_office VARCHAR(30) NOT NULL DEFAULT 'registrar',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

INSERT INTO document_types (name, code, description, base_fee, per_copy_fee, processing_days, requires_upload, requires_term_info, requires_soa_info, fee_per_set, requires_auth_document_type, assignment_office) VALUES
('Transcript of Records (TOR)', 'TOR', 'Official academic transcript', 150.00, 50.00, 5, 1, 0, 0, 0, 0, 'registrar'),
('Diploma', 'DIPLOMA', 'Official diploma copy', 200.00, 0.00, 7, 1, 0, 0, 0, 0, 'registrar'),
('Certificate of Enrollment', 'COE', 'Proof of current enrollment', 50.00, 25.00, 2, 0, 1, 0, 0, 0, 'registrar'),
('Certificate of Grades', 'COGR', 'Official certificate of grades for a specific school year and semester', 75.00, 25.00, 3, 0, 1, 0, 0, 0, 'registrar'),
('Statement of Account', 'SOA', 'Official statement of account for a specific school year and semester', 75.00, 25.00, 3, 0, 1, 1, 0, 0, 'cashier'),
('Certificate of Graduation', 'COG', 'Proof of graduation', 100.00, 25.00, 3, 0, 0, 0, 0, 0, 'registrar'),
('Good Moral Certificate', 'GMC', 'Certificate of good moral character', 75.00, 25.00, 3, 0, 0, 0, 0, 0, 'guidance'),
('Authentication/Certified True Copy', 'CTC', 'Certified true copy of documents', 100.00, 50.00, 3, 1, 0, 0, 1, 1, 'registrar'),
('Other Academic Records', 'OTHER', 'Other academic documents', 75.00, 25.00, 5, 1, 0, 0, 0, 0, 'registrar');

CREATE TABLE document_type_enrollment_rules (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    document_type_id TINYINT UNSIGNED NOT NULL,
    enrollment_status ENUM('enrolled','graduated','inactive') NOT NULL,
    is_allowed TINYINT(1) NOT NULL DEFAULT 1,
    max_copies TINYINT UNSIGNED NOT NULL DEFAULT 10,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_doc_enrollment (document_type_id, enrollment_status),
    FOREIGN KEY (document_type_id) REFERENCES document_types(id) ON DELETE CASCADE
);

-- Credential requests
CREATE TABLE requests (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    request_number VARCHAR(20) NOT NULL UNIQUE,
    user_id INT UNSIGNED NOT NULL,
    document_type_id TINYINT UNSIGNED NULL,
    purpose ENUM('employment','scholarship','transfer','further_studies','personal','legal','other') NOT NULL,
    purpose_other VARCHAR(255),
    copy_request_type ENUM('first_request','second_copy') NOT NULL DEFAULT 'first_request',
    request_school_year VARCHAR(20),
    request_semester VARCHAR(30),
    request_soa_assessment_scope VARCHAR(30),
    request_soa_remarks VARCHAR(255),
    authentication_document_type VARCHAR(50),
    copies INT NOT NULL DEFAULT 1,
    status ENUM('submitted','under_review','awaiting_requirements','requirements_submitted','needs_revision','requirements_verified','payment_verified','processing','ready_for_pickup','shipped','completed','rejected') DEFAULT 'submitted',
    delivery_method ENUM('pickup','courier','authorized_representative') NULL DEFAULT NULL,
    pickup_date DATE,
    pickup_time TIME,
    release_date DATE,
    release_time TIME,
    delivery_address TEXT,
    delivery_city VARCHAR(100),
    delivery_province VARCHAR(100),
    delivery_postal_code VARCHAR(10),
    courier_tracking VARCHAR(100),
    total_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    assigned_to INT UNSIGNED,
    rejection_reason TEXT,
    verification_code VARCHAR(64),
    qr_code_path VARCHAR(255),
    pdf_path VARCHAR(255),
    digital_signature VARCHAR(255),
    notes TEXT,
    completed_at DATETIME,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (document_type_id) REFERENCES document_types(id),
    FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE request_items (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    request_id INT UNSIGNED NOT NULL,
    document_type_id TINYINT UNSIGNED NOT NULL,
    copies INT NOT NULL DEFAULT 1,
    request_school_year VARCHAR(20) NULL,
    request_semester VARCHAR(30) NULL,
    request_soa_assessment_scope VARCHAR(30) NULL,
    request_soa_remarks VARCHAR(255) NULL,
    item_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    item_status ENUM('pending_assignment','processing','ready_for_pickup','completed') DEFAULT 'pending_assignment',
    assigned_to INT UNSIGNED NULL,
    release_date DATE NULL,
    release_time TIME NULL,
    pickup_date DATE NULL,
    pickup_time TIME NULL,
    verification_code VARCHAR(64) NULL,
    qr_code_path VARCHAR(255) NULL,
    pdf_path VARCHAR(255) NULL,
    sort_order INT NOT NULL DEFAULT 0,
    completed_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (request_id) REFERENCES requests(id) ON DELETE CASCADE,
    FOREIGN KEY (document_type_id) REFERENCES document_types(id),
    FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE request_authentication_items (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    request_id INT UNSIGNED NOT NULL,
    request_item_id INT UNSIGNED NULL,
    auth_document_type VARCHAR(50) NOT NULL,
    sets TINYINT UNSIGNED NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (request_id) REFERENCES requests(id) ON DELETE CASCADE,
    FOREIGN KEY (request_item_id) REFERENCES request_items(id) ON DELETE CASCADE,
    UNIQUE KEY uk_request_auth_doc (request_id, auth_document_type)
);

-- Request uploaded documents
CREATE TABLE request_documents (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    request_id INT UNSIGNED NOT NULL,
    document_category VARCHAR(50) NULL,
    file_name VARCHAR(255) NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    file_type VARCHAR(50),
    file_size INT,
    uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (request_id) REFERENCES requests(id) ON DELETE CASCADE
);

-- Request status history
CREATE TABLE request_status_history (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    request_id INT UNSIGNED NOT NULL,
    old_status VARCHAR(50),
    new_status VARCHAR(50) NOT NULL,
    changed_by INT UNSIGNED,
    remarks TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (request_id) REFERENCES requests(id) ON DELETE CASCADE,
    FOREIGN KEY (changed_by) REFERENCES users(id) ON DELETE SET NULL
);

-- Payments
CREATE TABLE payments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    request_id INT UNSIGNED NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    payment_method VARCHAR(30) NOT NULL,
    reference_number VARCHAR(100),
    or_number VARCHAR(50),
    payment_date DATE,
    receipt_path VARCHAR(255),
    status ENUM('pending','verified','rejected','refunded') DEFAULT 'pending',
    verified_by INT UNSIGNED,
    verified_at DATETIME,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (request_id) REFERENCES requests(id) ON DELETE CASCADE,
    FOREIGN KEY (verified_by) REFERENCES users(id) ON DELETE SET NULL
);

-- Notifications
CREATE TABLE notifications (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    title VARCHAR(255) NOT NULL,
    message TEXT NOT NULL,
    type ENUM('info','success','warning','error') DEFAULT 'info',
    link VARCHAR(255),
    is_read TINYINT(1) DEFAULT 0,
    sent_email TINYINT(1) DEFAULT 0,
    sent_sms TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Audit logs
CREATE TABLE audit_logs (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED,
    action VARCHAR(100) NOT NULL,
    entity_type VARCHAR(50),
    entity_id INT UNSIGNED,
    old_values JSON,
    new_values JSON,
    ip_address VARCHAR(45),
    user_agent TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
);

-- Appointments
CREATE TABLE appointments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    request_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    appointment_date DATE NOT NULL,
    appointment_time TIME NOT NULL,
    status ENUM('scheduled','confirmed','completed','cancelled','no_show') DEFAULT 'scheduled',
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (request_id) REFERENCES requests(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id)
);

-- FAQs
CREATE TABLE faqs (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    category VARCHAR(100) DEFAULT 'General',
    question TEXT NOT NULL,
    answer TEXT NOT NULL,
    sort_order INT DEFAULT 0,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

INSERT INTO faqs (category, question, answer, sort_order) VALUES
('General', 'How long does it take to process my request?', 'Processing time varies by document type. TOR takes 5 business days, while Certificate of Enrollment takes 2 business days.', 1),
('General', 'What payment methods are accepted?', 'We accept GCash, bank transfer, and on-site payment at the cashier. For on-site payment, the app generates a 6-digit reference code for the cashier to locate your request.', 2),
('Documents', 'What documents do I need to upload?', 'For TOR and Diploma requests, you need to upload a valid ID and authorization letter if applicable.', 3),
('Pickup', 'How do I schedule a pickup appointment?', 'Once your request status is "Ready for Pickup", you can schedule an appointment from your request details page.', 4);

-- Feedback
CREATE TABLE feedback (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED,
    request_id INT UNSIGNED,
    rating TINYINT UNSIGNED NOT NULL CHECK (rating BETWEEN 1 AND 5),
    comment TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (request_id) REFERENCES requests(id) ON DELETE SET NULL
);

-- Chat / Help desk
CREATE TABLE chat_messages (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    staff_id INT UNSIGNED,
    message TEXT NOT NULL,
    is_staff TINYINT(1) DEFAULT 0,
    is_read TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (staff_id) REFERENCES users(id) ON DELETE SET NULL
);

-- Document requirement templates
CREATE TABLE document_requirements (
    id TINYINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    document_type_id TINYINT UNSIGNED NULL,
    requirement_name VARCHAR(255) NOT NULL,
    description TEXT,
    is_required TINYINT(1) DEFAULT 1,
    sort_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (document_type_id) REFERENCES document_types(id) ON DELETE CASCADE
);

-- Per-request requirements assigned by Registrar
CREATE TABLE request_assigned_requirements (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    request_id INT UNSIGNED NOT NULL,
    request_item_id INT UNSIGNED NULL,
    requirement_code VARCHAR(50) NULL,
    subcategory_code VARCHAR(50) NULL,
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
    FOREIGN KEY (request_item_id) REFERENCES request_items(id) ON DELETE CASCADE,
    FOREIGN KEY (document_id) REFERENCES request_documents(id) ON DELETE SET NULL,
    FOREIGN KEY (verified_by) REFERENCES users(id) ON DELETE SET NULL
);

-- Admin-managed requirement catalog
CREATE TABLE requirement_definitions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(50) NOT NULL,
    name VARCHAR(255) NOT NULL,
    description TEXT NULL,
    requires_upload TINYINT(1) NOT NULL DEFAULT 1,
    is_optional TINYINT(1) NOT NULL DEFAULT 0,
    is_system TINYINT(1) NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_requirement_code (code)
);

INSERT INTO requirement_definitions (code, name, description, requires_upload, is_optional, is_system, is_active, sort_order) VALUES
('online_clearance', 'Online Clearance', 'Complete online clearance from all offices: Guidance, Library, Student Affairs, Program Chair, and Campus Director.', 0, 0, 1, 1, 1),
('thesis_distribution_list', 'Thesis Distribution List', 'Upload the thesis distribution list document.', 1, 0, 1, 1, 2),
('final_clearance', 'Final Clearance', 'Upload your final clearance document from the Registrar or relevant office.', 1, 0, 1, 1, 3),
('other_enrollment_requirements', 'Other Enrollment Requirements', 'Upload any other enrollment-related documents required for your request.', 1, 0, 1, 1, 4),
('affidavit_second_copy', 'Affidavit of 2nd copy or loss', 'Required for 2nd request of documents — upload affidavit of second copy or loss.', 1, 1, 1, 1, 5);

CREATE TABLE requirement_subcategories (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    requirement_code VARCHAR(50) NOT NULL,
    code VARCHAR(50) NOT NULL,
    name VARCHAR(255) NOT NULL,
    description TEXT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_req_subcode (requirement_code, code),
    KEY idx_req_sub_parent (requirement_code)
);

INSERT INTO requirement_subcategories (requirement_code, code, name, description, is_active, sort_order) VALUES
('other_enrollment_requirements', 'hs_card', 'HS Card', 'Upload a clear copy of your High School Card.', 1, 1),
('other_enrollment_requirements', 'live_birth_psa_photocopy', 'Live Birth PSA Photocopy', 'Upload a photocopy of your PSA Live Birth Certificate.', 1, 2),
('other_enrollment_requirements', 'f137a', 'F137A', 'Upload your Form 137-A (Secondary Student Permanent Record).', 1, 3);

-- Default requirements per credential type + request type (first / second copy)
CREATE TABLE document_type_requirement_defaults (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    document_type_id TINYINT UNSIGNED NOT NULL,
    requirement_code VARCHAR(50) NOT NULL,
    copy_request_type ENUM('first_request','second_copy') NOT NULL DEFAULT 'first_request',
    is_enabled TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (document_type_id) REFERENCES document_types(id) ON DELETE CASCADE,
    UNIQUE KEY uk_doc_type_requirement_copy (document_type_id, requirement_code, copy_request_type)
);

CREATE TABLE app_settings (
    setting_key VARCHAR(100) NOT NULL PRIMARY KEY,
    setting_value TEXT,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

INSERT INTO app_settings (setting_key, setting_value) VALUES
('auto_apply_requirement_defaults', '1');

-- Per-request compliance checklist
CREATE TABLE request_compliance (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    request_id INT UNSIGNED NOT NULL,
    requirement_id TINYINT UNSIGNED NOT NULL,
    is_met TINYINT(1) DEFAULT 0,
    verified_by INT UNSIGNED,
    verified_at DATETIME,
    remarks TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (request_id) REFERENCES requests(id) ON DELETE CASCADE,
    FOREIGN KEY (requirement_id) REFERENCES document_requirements(id) ON DELETE CASCADE,
    FOREIGN KEY (verified_by) REFERENCES users(id) ON DELETE SET NULL,
    UNIQUE KEY uk_request_requirement (request_id, requirement_id)
);

-- Overall compliance decision per request
CREATE TABLE request_compliance_summary (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    request_id INT UNSIGNED NOT NULL UNIQUE,
    compliance_status ENUM('pending','compliant','non_compliant','needs_revision') DEFAULT 'pending',
    verified_by INT UNSIGNED,
    verified_at DATETIME,
    remarks TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (request_id) REFERENCES requests(id) ON DELETE CASCADE,
    FOREIGN KEY (verified_by) REFERENCES users(id) ON DELETE SET NULL
);

-- Online clearance offices
CREATE TABLE clearance_departments (
    id TINYINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(50) NOT NULL UNIQUE,
    name VARCHAR(150) NOT NULL,
    icon VARCHAR(50) DEFAULT 'fa-check',
    sort_order INT DEFAULT 0,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

INSERT INTO clearance_departments (code, name, icon, sort_order) VALUES
('guidance', 'Guidance Office', 'fa-hands-helping', 1),
('library', 'Library', 'fa-book', 2),
('student_affairs', 'Student Affairs', 'fa-users', 3),
('program_chair', 'Program Chair', 'fa-chalkboard-teacher', 4),
('campus_director', 'Campus Director', 'fa-user-tie', 5);

CREATE TABLE request_clearances (
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

-- Default accounts are created by install.php with secure password hashes.
-- Admin: admin@regdum.edu.ph / Admin@123
-- Staff: staff@regdum.edu.ph / Staff@123
-- Cashier: cashier@regdum.edu.ph / Cashier@123
-- Registrar: registrar@regdum.edu.ph / Registrar@123

-- Indexes
CREATE INDEX idx_requests_user ON requests(user_id);
CREATE INDEX idx_requests_status ON requests(status);
CREATE INDEX idx_requests_number ON requests(request_number);
CREATE INDEX idx_notifications_user ON notifications(user_id, is_read);
CREATE INDEX idx_audit_user ON audit_logs(user_id);
CREATE INDEX idx_payments_request ON payments(request_id);
