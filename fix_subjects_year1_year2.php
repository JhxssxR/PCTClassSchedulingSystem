<?php
require 'config/database.php';

// One-time seeder:
// - Ensures `subjects.year_level` exists
// - Inserts Year 1 & Year 2 subjects (safe to run multiple times)
//
// Behavior:
// - If a subject with the same code already exists, it updates the name + year_level.

header('Content-Type: text/plain; charset=UTF-8');

function ensure_year_level_column(PDO $conn): void {
    $col_exists_stmt = $conn->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'subjects' AND column_name = 'year_level'");
    $col_exists_stmt->execute();
    $has_year_level = ((int)$col_exists_stmt->fetchColumn() > 0);
    if (!$has_year_level) {
        $conn->exec("ALTER TABLE subjects ADD COLUMN year_level TINYINT NULL AFTER subject_name");
    }
}

function normalize_code(string $code): string {
    $code = trim($code);
    $code = preg_replace('/\s+/', ' ', $code);
    return $code;
}

try {
    ensure_year_level_column($conn);

    // Ensure subject_code is unique-ish for safe upsert.
    // If the index can't be created (because of duplicates), we fall back to insert-ignore.
    $upsert_supported = true;
    try {
        $conn->exec("ALTER TABLE subjects ADD UNIQUE KEY uq_subject_code (subject_code)");
    } catch (Throwable $e) {
        // Index may already exist OR duplicates prevent adding.
        // We'll detect whether it exists; otherwise, degrade gracefully.
        $idx_stmt = $conn->prepare("SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'subjects' AND index_name = 'uq_subject_code'");
        $idx_stmt->execute();
        $has_idx = ((int)$idx_stmt->fetchColumn() > 0);
        if (!$has_idx) {
            $upsert_supported = false;
        }
    }

    $subjects = [
        // Year 1 - 1st Semester
        ['GE1', 'Understanding the Self', 1],
        ['GE2', 'Reading Philippine History', 1],
        ['GE3', 'Contemporary World', 1],
        ['CC101', 'Introduction to Computing', 1],
        ['CC102', 'Fundamentals of Programming 1', 1],
        ['NSTP 1', 'National Service Training Program', 1],
        ['PATHFit1', 'Movement Competency Training', 1],

        // Year 1 - 2nd Semester
        ['GE4', 'Mathematics in the Modern World', 1],
        ['GE5', 'Purposive Communication', 1],
        ['GE6', 'Art Appreciation', 1],
        ['CC103', 'Intermediate Programming', 1],
        ['MS101', 'Discrete Mathematics', 1],
        ['HCI101', 'Human Computer Interaction', 1],
        ['NSTP 2', 'National Service Training Program', 1],
        ['PATHFit2', 'Exercise-based Fitness Activities', 1],

        // Year 2 - 1st Semester
        ['GE7', 'Science Technology and Society', 2],
        ['CC104', 'Data Structure & Algorithms', 2],
        ['IPT101', 'Integrative Programming Technologies', 2],
        ['MS102', 'Quantitative Methods (inc. Modeling/Sim)', 2],
        ['IOT', 'Internet of Things', 2],
        ['ML', 'Machine Learning', 2],
        ['Elec 1', 'Elective 1 (Integrative Programming Tech 2)', 2],
        ['PATHFit3', 'Martial Arts', 2],

        // Year 2 - 2nd Semester
        ['GE8', 'Ethics', 2],
        ['CC105', 'Information Management', 2],
        ['IAS101', 'Information Assurance & Security', 2],
        ['NET101', 'Networking', 2],
        ['ID', 'Introduction to Data Science', 2],
        ['AMP', 'Advance Mobile Programming', 2],
        ['Elec 2', 'Elective 2 (Platform Technologies 1)', 2],
        ['PATHFit4', 'Group Exercises, Aerobics, Yoga', 2],
    ];

    if ($upsert_supported) {
        $stmt = $conn->prepare("INSERT INTO subjects (subject_code, subject_name, year_level) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE subject_name = VALUES(subject_name), year_level = VALUES(year_level)");
    } else {
        $stmt = $conn->prepare("INSERT IGNORE INTO subjects (subject_code, subject_name, year_level) VALUES (?, ?, ?)");
    }

    $inserted_or_updated = 0;
    foreach ($subjects as $row) {
        [$code, $name, $year] = $row;
        $code = normalize_code($code);
        $name = trim($name);
        $year = (int)$year;
        if ($code === '' || $name === '' || $year < 1 || $year > 4) {
            continue;
        }
        $stmt->execute([$code, $name, $year]);
        $inserted_or_updated++;
    }

    echo "SUCCESS: Seeded Year 1 & 2 subjects. Rows processed: {$inserted_or_updated}.\n";
    if (!$upsert_supported) {
        echo "NOTE: Could not enable unique subject_code upsert; used INSERT IGNORE (existing rows were not updated).\n";
        echo "      If you want updates-by-code, remove duplicate subject codes then rerun this script.\n";
    }
} catch (Throwable $e) {
    echo 'ERROR: ' . $e->getMessage() . "\n";
}
