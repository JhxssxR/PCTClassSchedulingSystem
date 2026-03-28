<?php
require 'config/database.php';

// One-time migration script:
// - Adds `subject_id` (nullable) to schedules if missing
// - Adds `year_level` (nullable) to schedules if missing
//
// Safe to run multiple times.

try {
    $cols_stmt = $conn->prepare('DESCRIBE schedules');
    $cols_stmt->execute();
    $cols = [];
    foreach ($cols_stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $cols[$r['Field']] = true;
    }

    if (!isset($cols['subject_id'])) {
        $conn->exec('ALTER TABLE schedules ADD COLUMN subject_id INT NULL AFTER course_id');
    }

    if (!isset($cols['year_level'])) {
        $conn->exec('ALTER TABLE schedules ADD COLUMN year_level TINYINT NULL AFTER academic_year');
    }

    header('Content-Type: text/plain; charset=UTF-8');
    echo "SUCCESS: schedules.subject_id and schedules.year_level are ready (nullable).\n";
} catch (Throwable $e) {
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'ERROR: ' . $e->getMessage() . "\n";
}
