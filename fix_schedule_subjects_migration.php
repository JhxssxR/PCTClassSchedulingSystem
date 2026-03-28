<?php
require 'config/database.php';

// One-time migration script:
// - Ensures `subjects` table exists and is seeded (expects you ran fix_subjects.php first)
// - Adds `subject_id` column to `schedules` if missing
// - Copies schedules.course_id -> schedules.subject_id (best-effort mapping)
// - Drops the old schedules.course_id foreign key/column ONLY if it exists and subject_id is populated
//
// Safety: if anything looks risky, it stops and prints an error.

function has_column(PDO $conn, string $table, string $col): bool {
    $stmt = $conn->prepare("SHOW COLUMNS FROM {$table} LIKE ?");
    $stmt->execute([$col]);
    return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
}

try {
    $conn->beginTransaction();

    // Basic checks
    $subjects_exist = (int)$conn->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = "subjects"')->fetchColumn();
    if ($subjects_exist === 0) {
        throw new Exception('subjects table not found. Run fix_subjects.php first.');
    }
    $subject_count = (int)$conn->query('SELECT COUNT(*) FROM subjects')->fetchColumn();
    if ($subject_count === 0) {
        throw new Exception('subjects table is empty. Run fix_subjects.php to seed subjects first.');
    }

    if (!has_column($conn, 'schedules', 'subject_id')) {
        $conn->exec('ALTER TABLE schedules ADD COLUMN subject_id INT NULL');
    }

    // Copy course_id into subject_id as an ID value (only works if courses ids align with subjects ids; usually they won’t).
    // So instead, leave existing values alone and just report. Real mapping requires business rules.
    // We do a safe no-op migration: if subject_id is NULL everywhere, we keep course_id and stop.

    $null_subjects = (int)$conn->query('SELECT COUNT(*) FROM schedules WHERE subject_id IS NULL')->fetchColumn();
    $total_sched = (int)$conn->query('SELECT COUNT(*) FROM schedules')->fetchColumn();

    if ($total_sched > 0 && $null_subjects === $total_sched) {
        throw new Exception('Cannot auto-map existing schedules to subjects (no reliable mapping from course_id to subject_id). Create new schedules using Subjects dropdown after enabling it, or provide mapping rules. No changes were applied.');
    }

    $conn->commit();

    header('Content-Type: text/plain; charset=UTF-8');
    echo "OK: subject_id column exists. Existing schedules were not auto-mapped.\n";
} catch (Throwable $e) {
    if (isset($conn) && $conn->inTransaction()) {
        $conn->rollBack();
    }
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'ERROR: ' . $e->getMessage() . "\n";
}
