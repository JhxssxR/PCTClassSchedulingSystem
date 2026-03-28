<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is an admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'super_admin') {
    header('Location: ../auth/login.php');
    exit();
}

function schedule_end_expr(PDO $conn, string $alias = ''): string {
    $stmt = $conn->prepare('DESCRIBE schedules');
    $stmt->execute();
    $cols = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $cols[$row['Field']] = true;
    }

    $p = $alias !== '' ? ($alias . '.') : '';
    if (isset($cols['end_time'])) {
        return $p . 'end_time';
    }
    if (isset($cols['duration_minutes'])) {
        return "ADDTIME({$p}start_time, SEC_TO_TIME({$p}duration_minutes * 60))";
    }
    return "ADDTIME({$p}start_time, SEC_TO_TIME(120 * 60))";
}

function table_columns(PDO $conn, string $table): array {
    $stmt = $conn->prepare("DESCRIBE `{$table}`");
    $stmt->execute();
    $cols = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $cols[$row['Field']] = true;
    }
    return $cols;
}

function enrollment_status_map(PDO $conn): array {
    // Some schemas use 'approved' instead of 'enrolled'
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

    $active = isset($allowed['enrolled']) ? 'enrolled' : (isset($allowed['approved']) ? 'approved' : 'enrolled');
    $pending = isset($allowed['pending']) ? 'pending' : 'pending';
    $rejected = isset($allowed['rejected']) ? 'rejected' : 'rejected';
    $dropped = isset($allowed['dropped']) ? 'dropped' : 'dropped';

    return [
        'active' => $active,
        'pending' => $pending,
        'rejected' => $rejected,
        'dropped' => $dropped,
        'allowed' => $allowed,
    ];
}

function normalize_status_for_db(array $map, string $status): string {
    $s = strtolower(trim($status));
    if ($s === 'enrolled' || $s === 'active') {
        return $map['active'];
    }
    if ($s === 'pending') {
        return isset($map['allowed']['pending']) ? 'pending' : $map['active'];
    }
    if ($s === 'dropped') {
        if (isset($map['allowed']['dropped'])) return 'dropped';
        if (isset($map['allowed']['rejected'])) return 'rejected';
        return $map['active'];
    }
    if ($s === 'rejected') {
        if (isset($map['allowed']['rejected'])) return 'rejected';
        if (isset($map['allowed']['dropped'])) return 'dropped';
        return $map['active'];
    }
    return isset($map['allowed']['pending']) ? 'pending' : $map['active'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $status_map = enrollment_status_map($conn);
    $active_status = $status_map['active'];
    $enroll_cols = table_columns($conn, 'enrollments');

    try {
        switch ($action) {
            case 'add':
                // Validate required fields
                if (empty($_POST['student_id']) || empty($_POST['schedule_id'])) {
                    throw new Exception('All fields are required');
                }

                $initial_status = normalize_status_for_db($status_map, (string)($_POST['status'] ?? 'pending'));

                // Check if student is already enrolled in the schedule
                $stmt = $conn->prepare("
                    SELECT COUNT(*) FROM enrollments 
                    WHERE student_id = ? AND schedule_id = ? AND status = ?
                ");
                $stmt->execute([$_POST['student_id'], $_POST['schedule_id'], $active_status]);
                if ($stmt->fetchColumn() > 0) {
                    throw new Exception('Student is already enrolled in this schedule');
                }

                // Check for schedule conflicts
                $end_expr_s = schedule_end_expr($conn, 's');
                $end_expr_new = schedule_end_expr($conn, '');
                $stmt = $conn->prepare("
                    SELECT s.day_of_week, s.start_time, {$end_expr_s} AS end_time
                    FROM schedules s
                    JOIN enrollments e ON s.id = e.schedule_id
                    WHERE e.student_id = ? AND e.status = 'enrolled'
                    AND s.id != ?
                ");
                $stmt = $conn->prepare("
                    SELECT s.day_of_week, s.start_time, {$end_expr_s} AS end_time
                    FROM schedules s
                    JOIN enrollments e ON s.id = e.schedule_id
                    WHERE e.student_id = ? AND e.status = ?
                    AND s.id != ?
                ");
                $stmt->execute([$_POST['student_id'], $active_status, $_POST['schedule_id']]);
                $existing_schedules = $stmt->fetchAll(PDO::FETCH_ASSOC);

                $stmt = $conn->prepare("
                    SELECT day_of_week, start_time, {$end_expr_new} AS end_time
                    FROM schedules
                    WHERE id = ?
                ");
                $stmt->execute([$_POST['schedule_id']]);
                $new_schedule = $stmt->fetch(PDO::FETCH_ASSOC);

                foreach ($existing_schedules as $schedule) {
                    if ($schedule['day_of_week'] === $new_schedule['day_of_week']) {
                        if (
                            ($new_schedule['start_time'] <= $schedule['start_time'] && $new_schedule['end_time'] > $schedule['start_time']) ||
                            ($new_schedule['start_time'] < $schedule['end_time'] && $new_schedule['end_time'] >= $schedule['end_time']) ||
                            ($new_schedule['start_time'] >= $schedule['start_time'] && $new_schedule['end_time'] <= $schedule['end_time'])
                        ) {
                            throw new Exception('Student has a schedule conflict at this time');
                        }
                    }
                }

                // Add new enrollment
                $insert_cols = ['student_id', 'schedule_id', 'status'];
                $insert_vals = [$_POST['student_id'], $_POST['schedule_id'], $initial_status];
                $insert_sql_vals = ['?', '?', '?'];

                // Set whichever timestamp columns exist; some schemas prefer enrolled_at.
                if (isset($enroll_cols['enrolled_at'])) {
                    $insert_cols[] = 'enrolled_at';
                    $insert_sql_vals[] = 'NOW()';
                }
                if (isset($enroll_cols['enrollment_date'])) {
                    $insert_cols[] = 'enrollment_date';
                    $insert_sql_vals[] = 'NOW()';
                }
                if (isset($enroll_cols['created_at'])) {
                    $insert_cols[] = 'created_at';
                    $insert_sql_vals[] = 'NOW()';
                }

                $sql = 'INSERT INTO enrollments (' . implode(', ', $insert_cols) . ') VALUES (' . implode(', ', $insert_sql_vals) . ')';
                $stmt = $conn->prepare($sql);
                $stmt->execute($insert_vals);

                $_SESSION['success'] = 'Enrollment added successfully';
                break;

            case 'update_status':
                // Validate required fields
                if (empty($_POST['enrollment_id']) || empty($_POST['status'])) {
                    throw new Exception('Enrollment ID and status are required');
                }

                // Validate status
                $valid_statuses = ['active', 'enrolled', 'pending', 'rejected', 'dropped'];
                if (!in_array($_POST['status'], $valid_statuses, true)) {
                    throw new Exception('Invalid status');
                }

                $new_status = normalize_status_for_db($status_map, (string)$_POST['status']);

                $set_parts = ['status = ?'];
                $exec = [$new_status];

                if (isset($enroll_cols['updated_at'])) {
                    $set_parts[] = 'updated_at = NOW()';
                }
                if (isset($enroll_cols['enrolled_at'])) {
                    $set_parts[] = 'enrolled_at = CASE WHEN ? = ? THEN NOW() ELSE enrolled_at END';
                    $exec[] = $new_status;
                    $exec[] = $active_status;
                } elseif (isset($enroll_cols['enrollment_date'])) {
                    // Best-effort: if schema uses enrollment_date as the "approved/enrolled" timestamp
                    $set_parts[] = 'enrollment_date = CASE WHEN ? = ? THEN NOW() ELSE enrollment_date END';
                    $exec[] = $new_status;
                    $exec[] = $active_status;
                }

                $exec[] = $_POST['enrollment_id'];
                $stmt = $conn->prepare('UPDATE enrollments SET ' . implode(', ', $set_parts) . ' WHERE id = ?');
                $stmt->execute($exec);

                $_SESSION['success'] = 'Enrollment status updated successfully';
                break;


            default:
                throw new Exception('Invalid action');
        }

        header('Location: enrollments.php');
        exit();

    } catch (Exception $e) {
        $_SESSION['error'] = $e->getMessage();
        header('Location: enrollments.php');
        exit();
    }
} else {
    header('Location: enrollments.php');
    exit();
} 