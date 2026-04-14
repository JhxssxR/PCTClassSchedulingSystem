<?php

require_once __DIR__ . '/activity_log.php';

function enrollment_table_columns(PDO $conn): array {
    $stmt = $conn->prepare('DESCRIBE enrollments');
    $stmt->execute();
    $cols = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $cols[$row['Field']] = true;
    }
    return $cols;
}

function enrollment_schedule_end_expr(array $schedule_cols, string $alias = ''): string {
    $prefix = $alias !== '' ? ($alias . '.') : '';
    if (isset($schedule_cols['end_time'])) {
        return $prefix . 'end_time';
    }
    if (isset($schedule_cols['duration_minutes'])) {
        return "ADDTIME({$prefix}start_time, SEC_TO_TIME({$prefix}duration_minutes * 60))";
    }
    return "ADDTIME({$prefix}start_time, SEC_TO_TIME(120 * 60))";
}

function enrollment_status_map_common(PDO $conn): array {
    $stmt = $conn->prepare("SHOW COLUMNS FROM enrollments LIKE 'status'");
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $type = (string)($row['Type'] ?? '');

    $allowed = [];
    if (preg_match("/^enum\((.*)\)$/i", $type, $match)) {
        $values = str_getcsv($match[1], ',', "'");
        foreach ($values as $value) {
            $allowed[strtolower(trim($value))] = true;
        }
    }

    $active = isset($allowed['enrolled']) ? 'enrolled' : (isset($allowed['approved']) ? 'approved' : 'pending');

    return [
        'active' => $active,
        'allowed' => $allowed,
        'supports_pending' => empty($allowed) || isset($allowed['pending']),
        'supports_dropped' => empty($allowed) || isset($allowed['dropped']),
        'supports_rejected' => empty($allowed) || isset($allowed['rejected']),
    ];
}

function enrollment_normalize_status(PDO $conn, string $status): string {
    $status = strtolower(trim($status));
    $map = enrollment_status_map_common($conn);
    $allowed = $map['allowed'];

    if ($status === 'active') {
        return $map['active'];
    }

    if ($status === 'rejected' && !isset($allowed['rejected']) && isset($allowed['dropped'])) {
        return 'dropped';
    }

    if ($status !== '' && (empty($allowed) || isset($allowed[$status]))) {
        return $status;
    }

    return $map['active'];
}

function enrollment_table_exists(PDO $conn, string $table): bool {
    $stmt = $conn->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
    $stmt->execute([$table]);
    return ((int)$stmt->fetchColumn()) > 0;
}

function enrollment_process_request(PDO $conn, array $options): void {
    $redirect_to = (string)($options['redirect_to'] ?? '');
    $allowed_roles = (array)($options['allowed_roles'] ?? ['super_admin', 'admin', 'registrar']);

    if (!isset($_SESSION['user_id']) || !in_array((string)($_SESSION['role'] ?? ''), $allowed_roles, true)) {
        header('Location: ../auth/login.php');
        exit();
    }

    $schedule_cols_stmt = $conn->prepare('DESCRIBE schedules');
    $schedule_cols_stmt->execute();
    $schedule_cols = [];
    foreach ($schedule_cols_stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $schedule_cols[$row['Field']] = true;
    }

    $enroll_cols = enrollment_table_columns($conn);
    $status_map = enrollment_status_map_common($conn);
    $active_db_status = $status_map['active'];

    $action = strtolower(trim((string)($_POST['action'] ?? '')));
    $redirect_target = $redirect_to !== '' ? $redirect_to : 'manage_enrollments.php';

    try {
        switch ($action) {
            case 'add': {
                $student_id = (int)($_POST['student_id'] ?? 0);
                $schedule_id = (int)($_POST['schedule_id'] ?? 0);
                $status = enrollment_normalize_status($conn, (string)($_POST['status'] ?? 'active'));

                if ($student_id <= 0 || $schedule_id <= 0) {
                    throw new Exception('Student and subject are required.');
                }

                $student_stmt = $conn->prepare("SELECT id, first_name, last_name, role FROM users WHERE id = ? AND role = 'student' LIMIT 1");
                $student_stmt->execute([$student_id]);
                $student = $student_stmt->fetch(PDO::FETCH_ASSOC);
                if (!$student) {
                    throw new Exception('Selected student is invalid.');
                }

                $schedule_stmt = $conn->prepare("SELECT s.id, s.course_id, s.instructor_id, s.classroom_id, s.day_of_week, s.start_time, " . enrollment_schedule_end_expr($schedule_cols, 's') . " AS end_time, c.course_code, c.course_name FROM schedules s JOIN courses c ON c.id = s.course_id WHERE s.id = ? LIMIT 1");
                $schedule_stmt->execute([$schedule_id]);
                $schedule = $schedule_stmt->fetch(PDO::FETCH_ASSOC);
                if (!$schedule) {
                    throw new Exception('Selected subject is invalid.');
                }

                $existing_stmt = $conn->prepare('SELECT id, status FROM enrollments WHERE student_id = ? AND schedule_id = ? LIMIT 1');
                $existing_stmt->execute([$student_id, $schedule_id]);
                $existing = $existing_stmt->fetch(PDO::FETCH_ASSOC) ?: null;

                if ($existing) {
                    $update_sql = 'UPDATE enrollments SET status = ?';
                    $params = [$status];
                    if (isset($enroll_cols['updated_at'])) {
                        $update_sql .= ', updated_at = NOW()';
                    }
                    if (isset($enroll_cols['created_by'])) {
                        $update_sql .= ', created_by = ?';
                        $params[] = (int)$_SESSION['user_id'];
                    }
                    $update_sql .= ' WHERE id = ?';
                    $params[] = (int)$existing['id'];
                    $stmt = $conn->prepare($update_sql);
                    $stmt->execute($params);

                    $_SESSION['success'] = 'Enrollment updated successfully.';
                    activity_log_write('enrollment_status_updated', (int)$_SESSION['user_id'], (string)($_SESSION['role'] ?? ''), [
                        'message' => 'Updated enrollment for ' . trim((string)($student['first_name'] ?? '') . ' ' . (string)($student['last_name'] ?? '')),
                        'student_id' => $student_id,
                        'schedule_id' => $schedule_id,
                        'status' => $status,
                        'action' => 'add_existing',
                    ]);
                    break;
                }

                $insert_cols = ['student_id', 'schedule_id', 'status'];
                $insert_vals = ['?', '?', '?'];
                $params = [$student_id, $schedule_id, $status];

                if (isset($enroll_cols['created_by'])) {
                    $insert_cols[] = 'created_by';
                    $insert_vals[] = '?';
                    $params[] = (int)$_SESSION['user_id'];
                }
                if (isset($enroll_cols['created_at'])) {
                    $insert_cols[] = 'created_at';
                    $insert_vals[] = 'NOW()';
                }
                if (isset($enroll_cols['updated_at'])) {
                    $insert_cols[] = 'updated_at';
                    $insert_vals[] = 'NOW()';
                }
                if (isset($enroll_cols['grade'])) {
                    $insert_cols[] = 'grade';
                    $insert_vals[] = 'NULL';
                }

                $sql = 'INSERT INTO enrollments (' . implode(', ', $insert_cols) . ') VALUES (' . implode(', ', $insert_vals) . ')';
                $stmt = $conn->prepare($sql);
                $stmt->execute($params);

                $_SESSION['success'] = 'Student enrolled successfully.';
                activity_log_write('enrollment_added', (int)$_SESSION['user_id'], (string)($_SESSION['role'] ?? ''), [
                    'message' => 'Added enrollment for ' . trim((string)($student['first_name'] ?? '') . ' ' . (string)($student['last_name'] ?? '')),
                    'student_id' => $student_id,
                    'schedule_id' => $schedule_id,
                    'status' => $status,
                    'course_code' => (string)($schedule['course_code'] ?? ''),
                    'course_name' => (string)($schedule['course_name'] ?? ''),
                    'action' => 'add',
                ]);
                break;
            }

            case 'update_status': {
                $enrollment_id = (int)($_POST['enrollment_id'] ?? 0);
                $status = enrollment_normalize_status($conn, (string)($_POST['status'] ?? ''));
                if ($enrollment_id <= 0) {
                    throw new Exception('Enrollment ID is required.');
                }

                $stmt = $conn->prepare('SELECT e.id, e.student_id, e.schedule_id, u.first_name, u.last_name FROM enrollments e JOIN users u ON u.id = e.student_id WHERE e.id = ? LIMIT 1');
                $stmt->execute([$enrollment_id]);
                $enrollment = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$enrollment) {
                    throw new Exception('Enrollment record not found.');
                }

                $update_sql = 'UPDATE enrollments SET status = ?';
                $params = [$status];
                if (isset($enroll_cols['updated_at'])) {
                    $update_sql .= ', updated_at = NOW()';
                }
                $update_sql .= ' WHERE id = ?';
                $params[] = $enrollment_id;
                $stmt = $conn->prepare($update_sql);
                $stmt->execute($params);

                $_SESSION['success'] = 'Enrollment status updated successfully.';
                activity_log_write('enrollment_status_updated', (int)$_SESSION['user_id'], (string)($_SESSION['role'] ?? ''), [
                    'message' => 'Updated enrollment status for ' . trim((string)($enrollment['first_name'] ?? '') . ' ' . (string)($enrollment['last_name'] ?? '')),
                    'enrollment_id' => $enrollment_id,
                    'student_id' => (int)$enrollment['student_id'],
                    'schedule_id' => (int)$enrollment['schedule_id'],
                    'status' => $status,
                    'action' => 'update_status',
                ]);
                break;
            }

            case 'delete': {
                $enrollment_id = (int)($_POST['enrollment_id'] ?? 0);
                if ($enrollment_id <= 0) {
                    throw new Exception('Enrollment ID is required.');
                }

                $stmt = $conn->prepare('SELECT e.id, e.student_id, e.schedule_id, u.first_name, u.last_name FROM enrollments e JOIN users u ON u.id = e.student_id WHERE e.id = ? LIMIT 1');
                $stmt->execute([$enrollment_id]);
                $enrollment = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$enrollment) {
                    throw new Exception('Enrollment record not found.');
                }

                $stmt = $conn->prepare('DELETE FROM enrollments WHERE id = ?');
                $stmt->execute([$enrollment_id]);

                $_SESSION['success'] = 'Enrollment deleted successfully.';
                activity_log_write('enrollment_deleted', (int)$_SESSION['user_id'], (string)($_SESSION['role'] ?? ''), [
                    'message' => 'Deleted enrollment for ' . trim((string)($enrollment['first_name'] ?? '') . ' ' . (string)($enrollment['last_name'] ?? '')),
                    'enrollment_id' => $enrollment_id,
                    'student_id' => (int)$enrollment['student_id'],
                    'schedule_id' => (int)$enrollment['schedule_id'],
                    'action' => 'delete',
                ]);
                break;
            }

            default:
                throw new Exception('Invalid action.');
        }
    } catch (Throwable $e) {
        $_SESSION['error'] = $e->getMessage();
    }

    header('Location: ' . $redirect_target);
    exit();
}