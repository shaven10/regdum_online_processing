<?php
/**
 * Seed sample active enrolled student records.
 * Usage: php scripts/seed-students.php
 */
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/student-seed.php';

$result = seedSampleStudents(true);

echo "Sample student seed complete.\n";
echo 'Created: ' . $result['created'] . "\n";
echo 'Skipped: ' . $result['skipped'] . "\n";
echo 'Failed:  ' . $result['failed'] . "\n";

if ($result['errors'] !== []) {
    echo "\nErrors:\n";
    foreach ($result['errors'] as $error) {
        echo '- ' . $error . "\n";
    }
}

if ($result['students'] !== []) {
    echo "\nStudents:\n";
    foreach ($result['students'] as $student) {
        echo sprintf(
            "- [%s] %s | ID: %s | Email: %s | Password: %s\n",
            strtoupper((string) $student['status']),
            $student['name'],
            $student['student_id'],
            $student['email'],
            $student['password']
        );
    }
}

exit($result['failed'] > 0 ? 1 : 0);
