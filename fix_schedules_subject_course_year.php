<?php
require 'config/database.php';

// One-time migration script:
// - Adds `subject_id` (nullable) to schedules if missing
// - Adds `year_level` (nullable) to schedules if missing
//
// Safe to run multiple times.

try {
    $cols = [];
    foreach (get_table_columns($conn, 'schedules') as $r) {
        $col_name = $r['Field'] ?? $r['column_name'] ?? '';
        if ($col_name !== '') {
            $cols[$col_name] = true;
        }
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
