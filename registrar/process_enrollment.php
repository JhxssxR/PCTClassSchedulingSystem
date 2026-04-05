<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is a registrar/admin
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'registrar'], true)) {
    header('Location: ../auth/login.php');
    exit();
}

function redirect_with_message(string $type, string $message): void {
    $_SESSION[$type] = $message;
    header('Location: manage_enrollments.php');
    exit();
}

function get_status_enum_values(PDO $conn): array {
    $stmt = $conn->prepare("SHOW COLUMNS FROM enrollments LIKE 'status'");
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $type = (string)($row['Type'] ?? '');

    $allowed = [];
    if (preg_match("/^enum\((.*)\)$/i", $type, $m)) {
        $vals = str_getcsv($m[1], ',', "'");
        foreach ($vals as $v) {
            $allowed[strtolower(trim($v))] = true;
        }
    }
    return $allowed;
}

function map_active_status(array $allowed): string {
    // Some DBs use 'approved' where UI expects 'enrolled'
    if (isset($allowed['enrolled'])) return 'enrolled';
    if (isset($allowed['approved'])) return 'approved';
    return 'enrolled';
}

function normalize_target_status(string $requested, string $active_db, array $allowed = []): string {
    $requested = strtolower(trim($requested));
    if ($requested === 'active' || $requested === 'enrolled') return $active_db;
    if ($requested === 'pending') {
        if (empty($allowed) || isset($allowed['pending'])) return 'pending';
        return $active_db;
    }
    if ($requested === 'dropped') {
        if (empty($allowed) || isset($allowed['dropped'])) return 'dropped';
        if (isset($allowed['rejected'])) return 'rejected';
        return $active_db;
    }
    if ($requested === 'rejected') {
        if (empty($allowed) || isset($allowed['rejected'])) return 'rejected';
        if (isset($allowed['dropped'])) return 'dropped';
        return $active_db;
    }
    if (empty($allowed) || isset($allowed['pending'])) return 'pending';
    return $active_db;
}

$allowed = get_status_enum_values($conn);
$active_db = map_active_status($allowed);

$action = (string)($_POST['action'] ?? '');

try {
    if ($action === 'add') {
        $student_id = (int)($_POST['student_id'] ?? 0);
        $schedule_id = (int)($_POST['schedule_id'] ?? 0);
        $requested_status = (string)($_POST['status'] ?? 'pending');

        if ($student_id <= 0 || $schedule_id <= 0) {
            redirect_with_message('error', 'Please select a student and schedule.');
        }

        // Verify student exists and is a student
        $stmt = $conn->prepare("SELECT id FROM users WHERE id = ? AND role = 'student'");
        $stmt->execute([$student_id]);
        if (!$stmt->fetch()) {
            redirect_with_message('error', 'Invalid student selected.');
        }

        // Verify schedule exists and is active
        $stmt = $conn->prepare("SELECT id FROM schedules WHERE id = ? AND status = 'active'");
        $stmt->execute([$schedule_id]);
        if (!$stmt->fetch()) {
            redirect_with_message('error', 'Invalid schedule selected.');
        }

        // Prevent duplicates
        $stmt = $conn->prepare('SELECT id, status FROM enrollments WHERE student_id = ? AND schedule_id = ? LIMIT 1');
        $stmt->execute([$student_id, $schedule_id]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($existing) {
            $status = strtolower((string)($existing['status'] ?? ''));
            if ($status === 'dropped' || $status === 'rejected') {
                // Allow re-enrolling by updating record to the selected status when supported.
                $target = normalize_target_status($requested_status, $active_db, $allowed);
                $set_parts = ['status = ?'];
                $exec = [$target];
                if (isset($cols['updated_at'])) {
                    $set_parts[] = 'updated_at = NOW()';
                }
                if (isset($cols['enrolled_at'])) {
                    $set_parts[] = 'enrolled_at = CASE WHEN ? = ? THEN NOW() ELSE enrolled_at END';
                    $exec[] = $target;
                    $exec[] = $active_db;
                } elseif (isset($cols['enrollment_date'])) {
                    $set_parts[] = 'enrollment_date = CASE WHEN ? = ? THEN NOW() ELSE enrollment_date END';
                    $exec[] = $target;
                    $exec[] = $active_db;
                }
                if (isset($cols['dropped_at'])) {
                    $set_parts[] = 'dropped_at = CASE WHEN ? = ? THEN NOW() ELSE dropped_at END';
                    $exec[] = $target;
                    $exec[] = 'dropped';
                }
                if (isset($cols['rejected_at'])) {
                    $set_parts[] = 'rejected_at = CASE WHEN ? = ? THEN NOW() ELSE rejected_at END';
                    $exec[] = $target;
                    $exec[] = 'rejected';
                }
                $exec[] = (int)$existing['id'];
                $stmt = $conn->prepare('UPDATE enrollments SET ' . implode(', ', $set_parts) . ' WHERE id = ?');
                $stmt->execute($exec);
                redirect_with_message('success', 'Enrollment updated successfully.');
            }
            redirect_with_message('error', 'Student is already enrolled in this schedule.');
        }

        $initial = normalize_target_status($requested_status, $active_db, $allowed);

        // Try to set a date column if present
        $cols_stmt = $conn->prepare('DESCRIBE enrollments');
        $cols_stmt->execute();
        $cols = [];
        foreach ($cols_stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $cols[$r['Field']] = true;
        }

        if (isset($cols['enrolled_at'])) {
            $stmt = $conn->prepare('INSERT INTO enrollments (student_id, schedule_id, status, enrolled_at) VALUES (?, ?, ?, NOW())');
            $stmt->execute([$student_id, $schedule_id, $initial]);
        } elseif (isset($cols['enrollment_date'])) {
            $stmt = $conn->prepare('INSERT INTO enrollments (student_id, schedule_id, status, enrollment_date) VALUES (?, ?, ?, NOW())');
            $stmt->execute([$student_id, $schedule_id, $initial]);
        } elseif (isset($cols['created_at'])) {
            $stmt = $conn->prepare('INSERT INTO enrollments (student_id, schedule_id, status, created_at) VALUES (?, ?, ?, NOW())');
            $stmt->execute([$student_id, $schedule_id, $initial]);
        } else {
            $stmt = $conn->prepare('INSERT INTO enrollments (student_id, schedule_id, status) VALUES (?, ?, ?)');
            $stmt->execute([$student_id, $schedule_id, $initial]);
        }

        redirect_with_message('success', 'Enrollment added successfully.');
    }

    if ($action === 'update_status') {
        $enrollment_id = (int)($_POST['enrollment_id'] ?? 0);
        $requested = (string)($_POST['status'] ?? '');

        if ($enrollment_id <= 0 || $requested === '') {
            redirect_with_message('error', 'Invalid request.');
        }

        $target = normalize_target_status($requested, $active_db, $allowed);

        // Validate target is allowed when enum is available
        if (!empty($allowed)) {
            $t = strtolower($target);
            if (!isset($allowed[$t])) {
                redirect_with_message('error', 'Status is not supported by the current database schema.');
            }
        }

        // Ensure enrollment exists
        $stmt = $conn->prepare('SELECT id, status FROM enrollments WHERE id = ?');
        $stmt->execute([$enrollment_id]);
        $enrollment = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$enrollment) {
            redirect_with_message('error', 'Enrollment not found.');
        }

        // Update status, and set timestamp when transitioning to the active/enrolled status.
        $set_parts = ['status = ?'];
        $exec = [$target];

        $cols_stmt = $conn->prepare('DESCRIBE enrollments');
        $cols_stmt->execute();
        $cols = [];
        foreach ($cols_stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $cols[$r['Field']] = true;
        }

        if (isset($cols['updated_at'])) {
            $set_parts[] = 'updated_at = NOW()';
        }

        if (isset($cols['enrolled_at'])) {
            $set_parts[] = 'enrolled_at = CASE WHEN ? = ? THEN NOW() ELSE enrolled_at END';
            $exec[] = $target;
            $exec[] = $active_db;
        } elseif (isset($cols['enrollment_date'])) {
            $set_parts[] = 'enrollment_date = CASE WHEN ? = ? THEN NOW() ELSE enrollment_date END';
            $exec[] = $target;
            $exec[] = $active_db;
        }

        $exec[] = $enrollment_id;
        $stmt = $conn->prepare('UPDATE enrollments SET ' . implode(', ', $set_parts) . ' WHERE id = ?');
        $stmt->execute($exec);

        redirect_with_message('success', 'Enrollment status updated successfully.');
    }

    if ($action === 'delete') {
        $enrollment_id = (int)($_POST['enrollment_id'] ?? 0);
        if ($enrollment_id <= 0) {
            redirect_with_message('error', 'Invalid enrollment selected.');
        }

        $stmt = $conn->prepare('SELECT id, status FROM enrollments WHERE id = ? LIMIT 1');
        $stmt->execute([$enrollment_id]);
        $enrollment = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$enrollment) {
            redirect_with_message('error', 'Enrollment not found.');
        }

        $current_status = strtolower((string)($enrollment['status'] ?? ''));
        if ($current_status !== 'dropped' && $current_status !== 'rejected') {
            redirect_with_message('error', 'Only dropped enrollments can be removed.');
        }

        $stmt = $conn->prepare('DELETE FROM enrollments WHERE id = ? LIMIT 1');
        $stmt->execute([$enrollment_id]);

        redirect_with_message('success', 'Enrollment record removed successfully.');
    }


    redirect_with_message('error', 'Invalid action.');
} catch (PDOException $e) {
    redirect_with_message('error', 'Database error: ' . $e->getMessage());
}
