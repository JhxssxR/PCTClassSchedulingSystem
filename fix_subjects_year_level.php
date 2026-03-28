<?php
require 'config/database.php';

// One-time migration script:
// - Adds `year_level` column to `subjects` if missing
// - Defaults all existing subjects to Year 1 (so filtering works immediately)
//
// Safe to run multiple times.

try {
    // Add column if missing
    $col_exists_stmt = $conn->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'subjects' AND column_name = 'year_level'");
    $col_exists_stmt->execute();
    $has_year_level = ((int)$col_exists_stmt->fetchColumn() > 0);

    if (!$has_year_level) {
        $conn->exec("ALTER TABLE subjects ADD COLUMN year_level TINYINT NULL AFTER subject_name");
    }

    // Default existing rows to Year 1 when unset
    $conn->exec("UPDATE subjects SET year_level = 1 WHERE year_level IS NULL OR year_level = 0");

    header('Content-Type: text/plain; charset=UTF-8');
    echo "SUCCESS: subjects.year_level is ready. Defaulted existing subjects to Year 1.\n";
} catch (Throwable $e) {
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'ERROR: ' . $e->getMessage() . "\n";
}
