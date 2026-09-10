-- Add structured majors under academic programs (e.g. BSED → English, Mathematics)
CREATE TABLE IF NOT EXISTS academic_majors (
    id SMALLINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    program_id TINYINT UNSIGNED NOT NULL,
    code VARCHAR(30) NOT NULL,
    name VARCHAR(150) NOT NULL,
    description TEXT,
    is_active TINYINT(1) DEFAULT 1,
    sort_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_academic_majors_program_code (program_id, code),
    KEY idx_academic_majors_program (program_id),
    CONSTRAINT fk_academic_majors_program
        FOREIGN KEY (program_id) REFERENCES academic_programs(id)
        ON DELETE CASCADE
);

SET @major_id_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'student_profiles'
      AND COLUMN_NAME = 'major_id'
);
SET @sql := IF(
    @major_id_exists = 0,
    'ALTER TABLE student_profiles ADD COLUMN major_id SMALLINT UNSIGNED NULL AFTER major',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

INSERT INTO academic_majors (program_id, code, name, description, sort_order, is_active)
SELECT ap.id, seed.code, seed.name, seed.description, seed.sort_order, 1
FROM academic_programs ap
INNER JOIN (
    SELECT 'ENGLISH' AS code, 'English' AS name,
           'Bachelor of Secondary Education major in English' AS description, 1 AS sort_order
    UNION ALL
    SELECT 'MATHEMATICS', 'Mathematics',
           'Bachelor of Secondary Education major in Mathematics', 2
) AS seed
WHERE ap.code = 'BSED'
  AND NOT EXISTS (
      SELECT 1 FROM academic_majors am
      WHERE am.program_id = ap.id AND am.code = seed.code
  );
