<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is an admin
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? null) !== 'super_admin') {
    header('Location: ../auth/login.php');
    exit();
}

function table_columns(PDO $conn, string $table): array {
    $stmt = $conn->prepare("DESCRIBE {$table}");
    $stmt->execute();
    $cols = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $cols[$row['Field']] = true;
    }
    return $cols;
}

function default_academic_year(): string {
    $y = (int)date('Y');
    return $y . '-' . ($y + 1);
}

function normalize_status(string $status): string {
    $status = strtolower(trim($status));
    // Support common enums across different schema versions.
    $allowed = ['active', 'inactive', 'cancelled', 'completed'];
    if (!in_array($status, $allowed, true)) {
        return 'active';
    }
    return $status;
}

function normalize_year_level($year_level): int {
    $y = (int)$year_level;
    return ($y >= 1 && $y <= 4) ? $y : 0;
}

function add_minutes_to_time(string $start_time, int $minutes): string {
    $t = strtotime('1970-01-01 ' . $start_time);
    if ($t === false) {
        return $start_time;
    }
    return date('H:i:s', $t + ($minutes * 60));
}

function record_exists(PDO $conn, string $table, int $id): bool {
    if ($id <= 0) {
        return false;
    }
    if (!in_array($table, ['courses', 'users', 'classrooms', 'subjects', 'schedules'], true)) {
        return false;
    }
    $stmt = $conn->prepare("SELECT 1 FROM {$table} WHERE id = ? LIMIT 1");
    $stmt->execute([$id]);
    return (bool)$stmt->fetchColumn();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: classes.php');
    exit();
}

$action = $_POST['action'] ?? '';

try {
    $cols = table_columns($conn, 'schedules');

    $has_end_time = isset($cols['end_time']);
    $has_duration_minutes = isset($cols['duration_minutes']);
    $end_time_compare_expr = $has_end_time
        ? 'end_time'
        : ($has_duration_minutes ? 'ADDTIME(start_time, SEC_TO_TIME(duration_minutes * 60))' : 'ADDTIME(start_time, SEC_TO_TIME(120 * 60))');

    switch ($action) {
        case 'add': {
            $has_subject_id = isset($cols['subject_id']);
            $required = ['course_id', 'year_level', 'instructor_id', 'classroom_id', 'day_of_week', 'start_time'];
            if ($has_subject_id) {
                $required[] = 'subject_id';
            }
            if ($has_end_time) {
                $required[] = 'end_time';
            }
            foreach ($required as $f) {
                if (empty($_POST[$f])) {
                    throw new Exception('All fields are required');
                }
            }

            $course_id = (int)$_POST['course_id'];
            $year_level = normalize_year_level($_POST['year_level']);
            $subject_id = $has_subject_id ? (int)$_POST['subject_id'] : 0;
            $instructor_id = (int)$_POST['instructor_id'];
            $classroom_id = (int)$_POST['classroom_id'];
            $day_of_week = (string)$_POST['day_of_week'];
            $start_time = (string)$_POST['start_time'];
            $end_time = (string)($_POST['end_time'] ?? '');
            if ($has_end_time) {
                if ($end_time === '') {
                    throw new Exception('End time is required');
                }
            } else {
                // Match existing behavior in other pages: default 2-hour block when end_time isn't stored.
                $end_time = add_minutes_to_time($start_time, 120);
            }
            $max_students = isset($_POST['max_students']) ? (int)$_POST['max_students'] : 30;
            $semester = trim((string)($_POST['semester'] ?? ''));
            $academic_year = trim((string)($_POST['academic_year'] ?? ''));
            $status = normalize_status((string)($_POST['status'] ?? 'active'));

            if ($semester === '') {
                $semester = '1st Semester';
            }
            if ($academic_year === '') {
                $academic_year = default_academic_year();
            }

            if (!record_exists($conn, 'courses', $course_id)) {
                throw new Exception('Selected course is invalid or no longer exists. Please refresh and select again.');
            }
            if (!record_exists($conn, 'users', $instructor_id)) {
                throw new Exception('Selected instructor is invalid or no longer exists. Please refresh and select again.');
            }
            if (!record_exists($conn, 'classrooms', $classroom_id)) {
                throw new Exception('Selected classroom is invalid or no longer exists. Please refresh and select again.');
            }
            if ($has_subject_id && !record_exists($conn, 'subjects', $subject_id)) {
                throw new Exception('Selected subject is invalid or no longer exists. Please refresh and select again.');
            }

            // Check room schedule conflict (active schedules)
            $stmt = $conn->prepare("
                SELECT COUNT(*)
                FROM schedules
                WHERE classroom_id = ?
                AND day_of_week = ?
                AND status = 'active'
                AND (
                    (start_time <= ? AND {$end_time_compare_expr} > ?) OR
                    (start_time < ? AND {$end_time_compare_expr} >= ?) OR
                    (start_time >= ? AND {$end_time_compare_expr} <= ?)
                )
            ");
            $stmt->execute([$classroom_id, $day_of_week, $start_time, $start_time, $end_time, $end_time, $start_time, $end_time]);
            if ((int)$stmt->fetchColumn() > 0) {
                throw new Exception('There is a schedule conflict in the selected room and time slot');
            }

            // Check instructor conflict
            $stmt = $conn->prepare("
                SELECT COUNT(*)
                FROM schedules
                WHERE instructor_id = ?
                AND day_of_week = ?
                AND status = 'active'
                AND (
                    (start_time <= ? AND {$end_time_compare_expr} > ?) OR
                    (start_time < ? AND {$end_time_compare_expr} >= ?) OR
                    (start_time >= ? AND {$end_time_compare_expr} <= ?)
                )
            ");
            $stmt->execute([$instructor_id, $day_of_week, $start_time, $start_time, $end_time, $end_time, $start_time, $end_time]);
            if ((int)$stmt->fetchColumn() > 0) {
                throw new Exception('The instructor has a schedule conflict at the selected time');
            }

            $fields = [
                'course_id' => $course_id,
                'instructor_id' => $instructor_id,
                'classroom_id' => $classroom_id,
                'day_of_week' => $day_of_week,
                'start_time' => $start_time,
            ];
            if ($has_subject_id) {
                $fields['subject_id'] = $subject_id;
            }
            if (isset($cols['year_level'])) {
                $fields['year_level'] = ($year_level > 0 ? $year_level : null);
            }
            if ($has_end_time) {
                $fields['end_time'] = $end_time;
            }
            if (isset($cols['max_students'])) {
                $fields['max_students'] = ($max_students > 0 ? $max_students : 30);
            }
            if (isset($cols['semester'])) {
                $fields['semester'] = $semester;
            }
            if (isset($cols['academic_year'])) {
                $fields['academic_year'] = $academic_year;
            }
            if (isset($cols['status'])) {
                $fields['status'] = $status;
            }
            if (isset($cols['created_by'])) {
                $fields['created_by'] = (int)$_SESSION['user_id'];
            }

            $col_names = array_keys($fields);
            $placeholders = array_fill(0, count($fields), '?');
            $sql = 'INSERT INTO schedules (' . implode(', ', $col_names) . ') VALUES (' . implode(', ', $placeholders) . ')';
            $stmt = $conn->prepare($sql);
            $stmt->execute(array_values($fields));

            $_SESSION['success'] = 'Class added successfully';
            break;
        }

        case 'edit': {
            if (empty($_POST['schedule_id'])) {
                throw new Exception('Schedule ID is required');
            }
            $has_subject_id = isset($cols['subject_id']);
            $required = ['course_id', 'year_level', 'instructor_id', 'classroom_id', 'day_of_week', 'start_time'];
            if ($has_subject_id) {
                $required[] = 'subject_id';
            }
            if ($has_end_time) {
                $required[] = 'end_time';
            }
            foreach ($required as $f) {
                if (empty($_POST[$f])) {
                    throw new Exception('All fields are required');
                }
            }

            $schedule_id = (int)$_POST['schedule_id'];
            $course_id = (int)$_POST['course_id'];
            $year_level = normalize_year_level($_POST['year_level']);
            $subject_id = $has_subject_id ? (int)$_POST['subject_id'] : 0;
            $instructor_id = (int)$_POST['instructor_id'];
            $classroom_id = (int)$_POST['classroom_id'];
            $day_of_week = (string)$_POST['day_of_week'];
            $start_time = (string)$_POST['start_time'];
            $end_time = (string)($_POST['end_time'] ?? '');
            if ($has_end_time) {
                if ($end_time === '') {
                    throw new Exception('End time is required');
                }
            } else {
                $end_time = add_minutes_to_time($start_time, 120);
            }
            $max_students = isset($_POST['max_students']) ? (int)$_POST['max_students'] : 30;
            $semester = trim((string)($_POST['semester'] ?? ''));
            $academic_year = trim((string)($_POST['academic_year'] ?? ''));
            $status = normalize_status((string)($_POST['status'] ?? 'active'));

            if ($semester === '') {
                $semester = '1st Semester';
            }
            if ($academic_year === '') {
                $academic_year = default_academic_year();
            }

            if (!record_exists($conn, 'schedules', $schedule_id)) {
                throw new Exception('Class record was not found. Please refresh the page and try again.');
            }
            if (!record_exists($conn, 'courses', $course_id)) {
                throw new Exception('Selected course is invalid or no longer exists. Please refresh and select again.');
            }
            if (!record_exists($conn, 'users', $instructor_id)) {
                throw new Exception('Selected instructor is invalid or no longer exists. Please refresh and select again.');
            }
            if (!record_exists($conn, 'classrooms', $classroom_id)) {
                throw new Exception('Selected classroom is invalid or no longer exists. Please refresh and select again.');
            }
            if ($has_subject_id && !record_exists($conn, 'subjects', $subject_id)) {
                throw new Exception('Selected subject is invalid or no longer exists. Please refresh and select again.');
            }

            // Check room schedule conflict (excluding current schedule)
            $stmt = $conn->prepare("
                SELECT COUNT(*)
                FROM schedules
                WHERE classroom_id = ?
                AND day_of_week = ?
                AND status = 'active'
                AND id != ?
                AND (
                    (start_time <= ? AND {$end_time_compare_expr} > ?) OR
                    (start_time < ? AND {$end_time_compare_expr} >= ?) OR
                    (start_time >= ? AND {$end_time_compare_expr} <= ?)
                )
            ");
            $stmt->execute([$classroom_id, $day_of_week, $schedule_id, $start_time, $start_time, $end_time, $end_time, $start_time, $end_time]);
            if ((int)$stmt->fetchColumn() > 0) {
                throw new Exception('There is a schedule conflict in the selected room and time slot');
            }

            // Check instructor conflict (excluding current schedule)
            $stmt = $conn->prepare("
                SELECT COUNT(*)
                FROM schedules
                WHERE instructor_id = ?
                AND day_of_week = ?
                AND status = 'active'
                AND id != ?
                AND (
                    (start_time <= ? AND {$end_time_compare_expr} > ?) OR
                    (start_time < ? AND {$end_time_compare_expr} >= ?) OR
                    (start_time >= ? AND {$end_time_compare_expr} <= ?)
                )
            ");
            $stmt->execute([$instructor_id, $day_of_week, $schedule_id, $start_time, $start_time, $end_time, $end_time, $start_time, $end_time]);
            if ((int)$stmt->fetchColumn() > 0) {
                throw new Exception('The instructor has a schedule conflict at the selected time');
            }

            $set = [
                'course_id' => $course_id,
                'instructor_id' => $instructor_id,
                'classroom_id' => $classroom_id,
                'day_of_week' => $day_of_week,
                'start_time' => $start_time,
            ];
            if ($has_subject_id) {
                $set['subject_id'] = $subject_id;
            }
            if (isset($cols['year_level'])) {
                $set['year_level'] = ($year_level > 0 ? $year_level : null);
            }
            if ($has_end_time) {
                $set['end_time'] = $end_time;
            }
            if (isset($cols['max_students'])) {
                $set['max_students'] = ($max_students > 0 ? $max_students : 30);
            }
            if (isset($cols['semester'])) {
                $set['semester'] = $semester;
            }
            if (isset($cols['academic_year'])) {
                $set['academic_year'] = $academic_year;
            }
            if (isset($cols['status'])) {
                $set['status'] = $status;
            }

            $assignments = [];
            $values = [];
            foreach ($set as $k => $v) {
                $assignments[] = "$k = ?";
                $values[] = $v;
            }
            if (isset($cols['updated_at'])) {
                $assignments[] = 'updated_at = NOW()';
            }
            $values[] = $schedule_id;

            $sql = 'UPDATE schedules SET ' . implode(', ', $assignments) . ' WHERE id = ?';
            $stmt = $conn->prepare($sql);
            $stmt->execute($values);

            $_SESSION['success'] = 'Class updated successfully';
            break;
        }

        case 'delete': {
            if (empty($_POST['schedule_id'])) {
                throw new Exception('Schedule ID is required');
            }
            $schedule_id = (int)$_POST['schedule_id'];

            $stmt = $conn->prepare("SELECT COUNT(*) FROM enrollments WHERE schedule_id = ? AND status IN ('approved', 'enrolled')");
            $stmt->execute([$schedule_id]);
            if ((int)$stmt->fetchColumn() > 0) {
                throw new Exception('Cannot delete class with active enrollments');
            }

            $stmt = $conn->prepare('DELETE FROM schedules WHERE id = ?');
            $stmt->execute([$schedule_id]);

            $_SESSION['success'] = 'Class deleted successfully';
            break;
        }

        case 'force_delete': {
            if (empty($_POST['schedule_id'])) {
                throw new Exception('Schedule ID is required');
            }
            $schedule_id = (int)$_POST['schedule_id'];

            // Force-delete is destructive: it removes enrollments for this class first.
            // Do this in a transaction to avoid leaving orphan rows.
            $conn->beginTransaction();
            try {
                $stmt = $conn->prepare('DELETE FROM enrollments WHERE schedule_id = ?');
                $stmt->execute([$schedule_id]);

                $stmt = $conn->prepare('DELETE FROM schedules WHERE id = ?');
                $stmt->execute([$schedule_id]);

                $conn->commit();
            } catch (Throwable $e) {
                if ($conn->inTransaction()) {
                    $conn->rollBack();
                }
                throw $e;
            }

            $_SESSION['success'] = 'Class deleted successfully (force delete)';
            break;
        }

        default:
            throw new Exception('Invalid action');
    }

    header('Location: classes.php');
    exit();

} catch (PDOException $e) {
    $message = $e->getMessage();
    $driverCode = is_array($e->errorInfo ?? null) ? ($e->errorInfo[1] ?? null) : null;
    if ((string)$e->getCode() === '23000' || (int)$driverCode === 1452) {
        $message = 'Unable to save class because one or more selected references are invalid. Please refresh and reselect Course, Subject, Instructor, and Classroom.';
    }
    $_SESSION['error'] = $message;
    header('Location: classes.php');
    exit();
} catch (Exception $e) {
    $_SESSION['error'] = $e->getMessage();
    header('Location: classes.php');
    exit();
}
