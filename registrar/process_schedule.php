<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is a registrar/admin
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'registrar'], true)) {
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

function add_minutes_to_time(string $start_time, int $minutes): string {
    $t = strtotime('1970-01-01 ' . $start_time);
    if ($t === false) {
        return $start_time;
    }
    return date('H:i:s', $t + ($minutes * 60));
}

function get_setting_int(PDO $conn, string $key, int $default): int {
    try {
        $stmt = $conn->prepare('SELECT `value` FROM settings WHERE `key` = ? LIMIT 1');
        $stmt->execute([$key]);
        $v = $stmt->fetchColumn();
        if ($v === false || $v === null) return $default;
        $i = (int)$v;
        return $i > 0 ? $i : $default;
    } catch (Exception $e) {
        return $default;
    }
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
    header('Location: schedules.php');
    exit();
}

$action = $_POST['action'] ?? '';

$cols = table_columns($conn, 'schedules');
$has_end_time = isset($cols['end_time']);
$has_duration_minutes = isset($cols['duration_minutes']);
$default_duration = get_setting_int($conn, 'default_class_duration', 120);
if ($default_duration < 30) $default_duration = 120;

$end_time_compare_expr = $has_end_time
    ? 'end_time'
    : ($has_duration_minutes ? 'ADDTIME(start_time, SEC_TO_TIME(duration_minutes * 60))' : 'ADDTIME(start_time, SEC_TO_TIME(120 * 60))');

try {
    switch ($action) {
        case 'add': {
            $required_fields = ['instructor_id', 'day_of_week', 'start_time', 'classroom_id'];
            if ($has_end_time) {
                $required_fields[] = 'end_time';
            }
            $has_course_id = isset($cols['course_id']);
            $has_subject_id = isset($cols['subject_id']);

            if ($has_course_id) {
                $required_fields[] = 'course_id';
            }
            if ($has_subject_id) {
                $required_fields[] = 'subject_id';
            } elseif (!$has_course_id) {
                $required_fields[] = 'course_id';
            }
            foreach ($required_fields as $field) {
                if (empty($_POST[$field])) {
                    throw new Exception('All fields are required.');
                }
            }

            $start_time = (string)$_POST['start_time'];
            $end_time = (string)($_POST['end_time'] ?? '');
            $instructor_id = (int)$_POST['instructor_id'];
            $classroom_id = (int)$_POST['classroom_id'];
            $course_id = $has_course_id ? (int)($_POST['course_id'] ?? 0) : 0;
            $subject_id = $has_subject_id ? (int)($_POST['subject_id'] ?? 0) : 0;
            if ($has_end_time) {
                if ($end_time === '') {
                    throw new Exception('End time is required.');
                }
            } else {
                $end_time = add_minutes_to_time($start_time, $default_duration);
            }

            if ($course_id > 0 && !record_exists($conn, 'courses', $course_id)) {
                throw new Exception('Selected course is invalid or no longer exists. Please refresh and select again.');
            }
            if ($subject_id > 0 && !record_exists($conn, 'subjects', $subject_id)) {
                throw new Exception('Selected subject is invalid or no longer exists. Please refresh and select again.');
            }
            if (!record_exists($conn, 'users', $instructor_id)) {
                throw new Exception('Selected instructor is invalid or no longer exists. Please refresh and select again.');
            }
            if (!record_exists($conn, 'classrooms', $classroom_id)) {
                throw new Exception('Selected classroom is invalid or no longer exists. Please refresh and select again.');
            }

            // Check for schedule conflicts in room
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
            $stmt->execute([
                $classroom_id,
                $_POST['day_of_week'],
                $start_time,
                $start_time,
                $end_time,
                $end_time,
                $start_time,
                $end_time
            ]);
            if ((int)$stmt->fetchColumn() > 0) {
                throw new Exception('There is a schedule conflict in the selected room and time slot.');
            }

            // Check instructor availability
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
            $stmt->execute([
                $instructor_id,
                $_POST['day_of_week'],
                $start_time,
                $start_time,
                $end_time,
                $end_time,
                $start_time,
                $end_time
            ]);
            if ((int)$stmt->fetchColumn() > 0) {
                throw new Exception('The instructor has a schedule conflict at the selected time.');
            }

            $fields = [
                'instructor_id' => $instructor_id,
                'classroom_id' => $classroom_id,
                'day_of_week' => (string)$_POST['day_of_week'],
                'start_time' => $start_time,
            ];
            if ($has_course_id) {
                $fields['course_id'] = $course_id;
            }
            if ($has_subject_id) {
                $fields['subject_id'] = $subject_id;
            }
            if ($has_end_time) {
                $fields['end_time'] = $end_time;
            } elseif ($has_duration_minutes) {
                $fields['duration_minutes'] = $default_duration;
            }
            if (isset($cols['status'])) {
                $fields['status'] = 'active';
            }
            if (isset($cols['created_at'])) {
                $fields['created_at'] = date('Y-m-d H:i:s');
            }
            if (isset($cols['created_by'])) {
                $fields['created_by'] = (int)$_SESSION['user_id'];
            }

            $col_names = array_keys($fields);
            $placeholders = array_fill(0, count($fields), '?');
            $sql = 'INSERT INTO schedules (' . implode(', ', $col_names) . ') VALUES (' . implode(', ', $placeholders) . ')';
            $stmt = $conn->prepare($sql);
            $stmt->execute(array_values($fields));

            $_SESSION['success'] = 'Schedule added successfully.';
            break;
        }

        case 'edit': {
            if (empty($_POST['schedule_id'])) {
                throw new Exception('Schedule ID is required.');
            }

            $required_fields = ['instructor_id', 'day_of_week', 'start_time', 'classroom_id', 'status'];
            if ($has_end_time) {
                $required_fields[] = 'end_time';
            }
            $has_course_id = isset($cols['course_id']);
            $has_subject_id = isset($cols['subject_id']);

            if ($has_course_id) {
                $required_fields[] = 'course_id';
            }
            if ($has_subject_id) {
                $required_fields[] = 'subject_id';
            } elseif (!$has_course_id) {
                $required_fields[] = 'course_id';
            }
            foreach ($required_fields as $field) {
                if (empty($_POST[$field])) {
                    throw new Exception('All fields are required.');
                }
            }

            $start_time = (string)$_POST['start_time'];
            $end_time = (string)($_POST['end_time'] ?? '');
            $schedule_id = (int)$_POST['schedule_id'];
            $instructor_id = (int)$_POST['instructor_id'];
            $classroom_id = (int)$_POST['classroom_id'];
            $course_id = $has_course_id ? (int)($_POST['course_id'] ?? 0) : 0;
            $subject_id = $has_subject_id ? (int)($_POST['subject_id'] ?? 0) : 0;
            if ($has_end_time) {
                if ($end_time === '') {
                    throw new Exception('End time is required.');
                }
            } else {
                $end_time = add_minutes_to_time($start_time, $default_duration);
            }

            if (!record_exists($conn, 'schedules', $schedule_id)) {
                throw new Exception('Schedule record was not found. Please refresh the page and try again.');
            }
            if ($course_id > 0 && !record_exists($conn, 'courses', $course_id)) {
                throw new Exception('Selected course is invalid or no longer exists. Please refresh and select again.');
            }
            if ($subject_id > 0 && !record_exists($conn, 'subjects', $subject_id)) {
                throw new Exception('Selected subject is invalid or no longer exists. Please refresh and select again.');
            }
            if (!record_exists($conn, 'users', $instructor_id)) {
                throw new Exception('Selected instructor is invalid or no longer exists. Please refresh and select again.');
            }
            if (!record_exists($conn, 'classrooms', $classroom_id)) {
                throw new Exception('Selected classroom is invalid or no longer exists. Please refresh and select again.');
            }

            // Room conflicts (exclude current)
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
            $stmt->execute([
                $classroom_id,
                $_POST['day_of_week'],
                $schedule_id,
                $start_time,
                $start_time,
                $end_time,
                $end_time,
                $start_time,
                $end_time
            ]);
            if ((int)$stmt->fetchColumn() > 0) {
                throw new Exception('There is a schedule conflict in the selected room and time slot.');
            }

            // Instructor conflicts (exclude current)
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
            $stmt->execute([
                $instructor_id,
                $_POST['day_of_week'],
                $schedule_id,
                $start_time,
                $start_time,
                $end_time,
                $end_time,
                $start_time,
                $end_time
            ]);
            if ((int)$stmt->fetchColumn() > 0) {
                throw new Exception('The instructor has a schedule conflict at the selected time.');
            }

            $set = [
                'instructor_id' => $instructor_id,
                'classroom_id' => $classroom_id,
                'day_of_week' => (string)$_POST['day_of_week'],
                'start_time' => $start_time,
            ];
            if ($has_course_id) {
                $set['course_id'] = $course_id;
            }
            if ($has_subject_id) {
                $set['subject_id'] = $subject_id;
            }
            if ($has_end_time) {
                $set['end_time'] = $end_time;
            } elseif ($has_duration_minutes) {
                $set['duration_minutes'] = $default_duration;
            }
            if (isset($cols['status'])) {
                $set['status'] = (string)$_POST['status'];
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

            $_SESSION['success'] = 'Schedule updated successfully.';
            break;
        }

        case 'delete': {
            if (empty($_POST['schedule_id'])) {
                throw new Exception('Schedule ID is required.');
            }

            $stmt = $conn->prepare("SELECT COUNT(*) FROM enrollments WHERE schedule_id = ? AND status = 'enrolled'");
            $stmt->execute([$_POST['schedule_id']]);
            if ((int)$stmt->fetchColumn() > 0) {
                throw new Exception('Cannot delete schedule with active enrollments.');
            }

            $stmt = $conn->prepare('DELETE FROM schedules WHERE id = ?');
            $stmt->execute([$_POST['schedule_id']]);

            $_SESSION['success'] = 'Schedule deleted successfully.';
            break;
        }

        default:
            throw new Exception('Invalid action.');
    }

    header('Location: schedules.php');
    exit();

} catch (PDOException $e) {
    $message = $e->getMessage();
    $driverCode = is_array($e->errorInfo ?? null) ? ($e->errorInfo[1] ?? null) : null;
    if ((string)$e->getCode() === '23000' || (int)$driverCode === 1452) {
        $message = 'Unable to save schedule because one or more selected references are invalid. Please refresh and reselect Course, Subject, Instructor, and Classroom.';
    }
    $_SESSION['error'] = $message;
    header('Location: schedules.php');
    exit();
} catch (Exception $e) {
    $_SESSION['error'] = $e->getMessage();
    header('Location: schedules.php');
    exit();
}
