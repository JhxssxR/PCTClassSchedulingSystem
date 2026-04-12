<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is an admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'super_admin') {
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

function normalize_time_his(string $time): string {
    $time = trim($time);
    if ($time === '') {
        return '';
    }
    $ts = strtotime('1970-01-01 ' . $time);
    if ($ts === false) {
        return $time;
    }
    return date('H:i:s', $ts);
}

function normalize_date_ymd(string $date): string {
    $date = trim($date);
    if ($date === '') {
        return '';
    }
    $ts = strtotime($date);
    if ($ts === false) {
        return '';
    }
    return date('Y-m-d', $ts);
}

function normalize_selected_days($submitted_days, array $allowed_days): array {
    if (!is_array($submitted_days)) {
        $submitted_days = [$submitted_days];
    }
    $selected = [];
    foreach ($submitted_days as $raw_day) {
        $day = trim((string)$raw_day);
        if ($day !== '' && in_array($day, $allowed_days, true)) {
            $selected[$day] = $day;
        }
    }
    return array_values($selected);
}

function schedule_effective_end_time(array $row, bool $has_end_time, bool $has_duration_minutes, int $default_duration): string {
    $start_time = normalize_time_his((string)($row['start_time'] ?? ''));
    if ($start_time === '') {
        return '';
    }

    if ($has_end_time) {
        $raw_end = normalize_time_his((string)($row['end_time'] ?? ''));
        if ($raw_end !== '') {
            return $raw_end;
        }
    }

    $duration = $default_duration;
    if ($has_duration_minutes) {
        $row_duration = (int)($row['duration_minutes'] ?? 0);
        if ($row_duration > 0) {
            $duration = $row_duration;
        }
    }
    return add_minutes_to_time($start_time, $duration);
}

function find_linked_schedule_rows(PDO $conn, array $base_row, array $cols, bool $has_end_time, bool $has_duration_minutes, int $default_duration): array {
    $where = [];
    $params = [];

    if (isset($cols['course_id'])) {
        $where[] = 'COALESCE(course_id, 0) = ?';
        $params[] = (int)($base_row['course_id'] ?? 0);
    }
    if (isset($cols['subject_id'])) {
        $where[] = 'COALESCE(subject_id, 0) = ?';
        $params[] = (int)($base_row['subject_id'] ?? 0);
    }
    if (isset($cols['instructor_id'])) {
        $where[] = 'COALESCE(instructor_id, 0) = ?';
        $params[] = (int)($base_row['instructor_id'] ?? 0);
    }
    if (isset($cols['classroom_id'])) {
        $where[] = 'COALESCE(classroom_id, 0) = ?';
        $params[] = (int)($base_row['classroom_id'] ?? 0);
    }
    $where[] = "TIME_FORMAT(start_time, '%H:%i:%s') = ?";
    $params[] = normalize_time_his((string)($base_row['start_time'] ?? ''));

    if (isset($cols['max_students'])) {
        $where[] = 'COALESCE(max_students, 0) = ?';
        $params[] = (int)($base_row['max_students'] ?? 0);
    }
    if (isset($cols['semester'])) {
        $where[] = "COALESCE(semester, '') = ?";
        $params[] = (string)($base_row['semester'] ?? '');
    }
    if (isset($cols['academic_year'])) {
        $where[] = "COALESCE(academic_year, '') = ?";
        $params[] = (string)($base_row['academic_year'] ?? '');
    }
    if (isset($cols['year_level'])) {
        $where[] = "COALESCE(year_level, '') = ?";
        $params[] = (string)($base_row['year_level'] ?? '');
    }

    $sql = 'SELECT * FROM schedules';
    if (!empty($where)) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }

    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $base_end = schedule_effective_end_time($base_row, $has_end_time, $has_duration_minutes, $default_duration);
    $linked = [];
    foreach ($rows as $row) {
        $row_end = schedule_effective_end_time($row, $has_end_time, $has_duration_minutes, $default_duration);
        if ($row_end === $base_end) {
            $linked[] = $row;
        }
    }

    $base_id = (int)($base_row['id'] ?? 0);
    $found_base = false;
    foreach ($linked as $row) {
        if ((int)($row['id'] ?? 0) === $base_id) {
            $found_base = true;
            break;
        }
    }
    if (!$found_base && $base_id > 0) {
        $linked[] = $base_row;
    }

    return $linked;
}

function fetch_active_enrollment_counts(PDO $conn, array $schedule_ids): array {
    $counts = [];
    foreach ($schedule_ids as $sid) {
        $counts[(int)$sid] = 0;
    }
    if (empty($schedule_ids)) {
        return $counts;
    }

    $placeholders = implode(',', array_fill(0, count($schedule_ids), '?'));
    $sql = "
        SELECT schedule_id, COUNT(*) AS c
        FROM enrollments
        WHERE schedule_id IN ($placeholders)
          AND LOWER(COALESCE(status, '')) IN ('approved', 'enrolled', 'active')
        GROUP BY schedule_id
    ";
    $stmt = $conn->prepare($sql);
    $stmt->execute(array_values($schedule_ids));
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $counts[(int)($row['schedule_id'] ?? 0)] = (int)($row['c'] ?? 0);
    }

    return $counts;
}

function ensure_schedule_end_time_column(PDO $conn, array &$cols): void {
    if (isset($cols['end_time'])) {
        return;
    }

    try {
        $conn->exec("ALTER TABLE schedules ADD COLUMN end_time TIME NULL AFTER start_time");
        $cols['end_time'] = true;
        $conn->exec("UPDATE schedules SET end_time = ADDTIME(start_time, SEC_TO_TIME(120 * 60)) WHERE end_time IS NULL OR end_time = '00:00:00'");
    } catch (Throwable $e) {
        // Ignore if schema cannot be altered; code will fall back to default duration behavior.
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: schedules.php');
    exit();
}

$action = $_POST['action'] ?? '';

$cols = table_columns($conn, 'schedules');
ensure_schedule_end_time_column($conn, $cols);
$has_end_time = isset($cols['end_time']);
$has_duration_minutes = isset($cols['duration_minutes']);
$has_start_date = isset($cols['start_date']);
$has_end_date = isset($cols['end_date']);
$default_duration = get_setting_int($conn, 'default_class_duration', 120);
if ($default_duration < 30) $default_duration = 120;

$end_time_compare_expr = $has_end_time
    ? 'end_time'
    : ($has_duration_minutes ? 'ADDTIME(start_time, SEC_TO_TIME(duration_minutes * 60))' : 'ADDTIME(start_time, SEC_TO_TIME(120 * 60))');
$allowed_days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];

try {
    switch ($action) {
        case 'add': {
            $required_fields = ['instructor_id', 'start_time', 'classroom_id'];
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

            $start_time = normalize_time_his((string)$_POST['start_time']);
            $end_time = normalize_time_his((string)($_POST['end_time'] ?? ''));
            $instructor_id = (int)$_POST['instructor_id'];
            $classroom_id = (int)$_POST['classroom_id'];
            $course_id = $has_course_id ? (int)($_POST['course_id'] ?? 0) : 0;
            $subject_id = $has_subject_id ? (int)($_POST['subject_id'] ?? 0) : 0;
            $start_date = $has_start_date ? normalize_date_ymd((string)($_POST['start_date'] ?? '')) : '';
            $end_date = $has_end_date ? normalize_date_ymd((string)($_POST['end_date'] ?? '')) : '';

            $selected_days = normalize_selected_days($_POST['day_of_week'] ?? [], $allowed_days);
            if (empty($selected_days)) {
                throw new Exception('Please select at least one valid day.');
            }

            if ($has_start_date && $start_date === '') {
                $start_date = date('Y-m-d');
            }
            if ($has_end_date && $end_date === '') {
                $base_start = $start_date !== '' ? $start_date : date('Y-m-d');
                $end_date = date('Y-m-d', strtotime($base_start . ' +17 days'));
            }
            if ($start_date !== '' && $end_date !== '' && strtotime($start_date) > strtotime($end_date)) {
                throw new Exception('End date must be on or after start date.');
            }

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

            $conflicts = [];
            foreach ($selected_days as $day_of_week) {
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
                    $day_of_week,
                    $start_time,
                    $start_time,
                    $end_time,
                    $end_time,
                    $start_time,
                    $end_time
                ]);
                if ((int)$stmt->fetchColumn() > 0) {
                    $conflicts[] = 'Room conflict on ' . $day_of_week . '.';
                }

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
                    $day_of_week,
                    $start_time,
                    $start_time,
                    $end_time,
                    $end_time,
                    $start_time,
                    $end_time
                ]);
                if ((int)$stmt->fetchColumn() > 0) {
                    $conflicts[] = 'Instructor conflict on ' . $day_of_week . '.';
                }
            }

            if (!empty($conflicts)) {
                throw new Exception("Unable to add schedule for selected days:\n" . implode("\n", $conflicts));
            }

            $conn->beginTransaction();
            $created_days = [];
            foreach ($selected_days as $day_of_week) {
                $fields = [
                    'instructor_id' => $instructor_id,
                    'classroom_id' => $classroom_id,
                    'day_of_week' => $day_of_week,
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
                if ($has_start_date) {
                    $fields['start_date'] = $start_date;
                }
                if ($has_end_date) {
                    $fields['end_date'] = $end_date;
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
                $created_days[] = $day_of_week;
            }
            $conn->commit();

            if (count($created_days) === 1) {
                $_SESSION['success'] = 'Schedule added successfully.';
            } else {
                $_SESSION['success'] = 'Schedules added successfully for ' . count($created_days) . ' days: ' . implode(', ', $created_days) . '.';
            }
            break;
        }

        case 'edit': {
            if (empty($_POST['schedule_id'])) {
                throw new Exception('Schedule ID is required.');
            }

            $required_fields = ['instructor_id', 'start_time', 'classroom_id', 'status'];
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

            $schedule_id = (int)$_POST['schedule_id'];
            $start_time = normalize_time_his((string)$_POST['start_time']);
            $end_time = normalize_time_his((string)($_POST['end_time'] ?? ''));
            $instructor_id = (int)$_POST['instructor_id'];
            $classroom_id = (int)$_POST['classroom_id'];
            $course_id = $has_course_id ? (int)($_POST['course_id'] ?? 0) : 0;
            $subject_id = $has_subject_id ? (int)($_POST['subject_id'] ?? 0) : 0;
            $start_date = $has_start_date ? normalize_date_ymd((string)($_POST['start_date'] ?? '')) : '';
            $end_date = $has_end_date ? normalize_date_ymd((string)($_POST['end_date'] ?? '')) : '';
            $selected_days = normalize_selected_days($_POST['day_of_week'] ?? [], $allowed_days);
            $target_status = isset($cols['status']) ? (string)$_POST['status'] : 'active';

            if (empty($selected_days)) {
                throw new Exception('Please select at least one valid day.');
            }

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

            $stmt = $conn->prepare('SELECT * FROM schedules WHERE id = ? LIMIT 1');
            $stmt->execute([$schedule_id]);
            $base_row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$base_row) {
                throw new Exception('Schedule record was not found. Please refresh the page and try again.');
            }

            if ($has_start_date && $start_date === '') {
                $start_date = normalize_date_ymd((string)($base_row['start_date'] ?? ''));
                if ($start_date === '') {
                    $start_date = date('Y-m-d');
                }
            }
            if ($has_end_date && $end_date === '') {
                $end_date = normalize_date_ymd((string)($base_row['end_date'] ?? ''));
                if ($end_date === '') {
                    $base_start = $start_date !== '' ? $start_date : date('Y-m-d');
                    $end_date = date('Y-m-d', strtotime($base_start . ' +17 days'));
                }
            }
            if ($start_date !== '' && $end_date !== '' && strtotime($start_date) > strtotime($end_date)) {
                throw new Exception('End date must be on or after start date.');
            }

            $linked_rows = find_linked_schedule_rows($conn, $base_row, $cols, $has_end_time, $has_duration_minutes, $default_duration);
            $linked_ids = [];
            foreach ($linked_rows as $row) {
                $sid = (int)($row['id'] ?? 0);
                if ($sid > 0) {
                    $linked_ids[$sid] = $sid;
                }
            }
            if (empty($linked_ids)) {
                $linked_ids[$schedule_id] = $schedule_id;
            }

            $enrollment_counts = fetch_active_enrollment_counts($conn, array_values($linked_ids));

            $selected_day_set = array_fill_keys($selected_days, true);
            $rows_by_day = [];
            foreach ($linked_rows as $row) {
                $day = (string)($row['day_of_week'] ?? '');
                if (!isset($rows_by_day[$day])) {
                    $rows_by_day[$day] = [];
                }
                $rows_by_day[$day][] = $row;
            }

            $rows_to_update_by_day = [];
            $rows_to_remove = [];

            foreach ($rows_by_day as $day => $rows_for_day) {
                usort($rows_for_day, function ($a, $b) use ($enrollment_counts) {
                    $ca = $enrollment_counts[(int)($a['id'] ?? 0)] ?? 0;
                    $cb = $enrollment_counts[(int)($b['id'] ?? 0)] ?? 0;
                    if ($ca === $cb) {
                        return ((int)($a['id'] ?? 0) <=> (int)($b['id'] ?? 0));
                    }
                    return $cb <=> $ca;
                });

                if (isset($selected_day_set[$day])) {
                    $rows_to_update_by_day[$day] = $rows_for_day[0];
                    for ($i = 1; $i < count($rows_for_day); $i++) {
                        $rows_to_remove[] = $rows_for_day[$i];
                    }
                } else {
                    foreach ($rows_for_day as $row) {
                        $rows_to_remove[] = $row;
                    }
                }
            }

            foreach ($rows_to_remove as $row) {
                $sid = (int)($row['id'] ?? 0);
                $day = (string)($row['day_of_week'] ?? '');
                if (($enrollment_counts[$sid] ?? 0) > 0) {
                    throw new Exception('Cannot remove ' . $day . ' because students are currently enrolled in that day schedule.');
                }
            }

            $exclude_ids = array_values($linked_ids);
            $check_conflicts = (strtolower($target_status) === 'active');

            if ($check_conflicts) {
                foreach ($selected_days as $day_of_week) {
                    $exclude_clause = '';
                    $exclude_params = [];
                    if (!empty($exclude_ids)) {
                        $exclude_clause = ' AND id NOT IN (' . implode(',', array_fill(0, count($exclude_ids), '?')) . ')';
                        $exclude_params = $exclude_ids;
                    }

                    $room_sql = "
                        SELECT COUNT(*)
                        FROM schedules
                        WHERE classroom_id = ?
                          AND day_of_week = ?
                          AND status = 'active'
                          $exclude_clause
                          AND (
                              (start_time <= ? AND {$end_time_compare_expr} > ?) OR
                              (start_time < ? AND {$end_time_compare_expr} >= ?) OR
                              (start_time >= ? AND {$end_time_compare_expr} <= ?)
                          )
                    ";
                    $room_params = array_merge([
                        $classroom_id,
                        $day_of_week,
                        $start_time,
                        $start_time,
                        $end_time,
                        $end_time,
                        $start_time,
                        $end_time
                    ], $exclude_params);
                    $stmt = $conn->prepare($room_sql);
                    $stmt->execute($room_params);
                    if ((int)$stmt->fetchColumn() > 0) {
                        throw new Exception('There is a room conflict on ' . $day_of_week . '.');
                    }

                    $ins_sql = "
                        SELECT COUNT(*)
                        FROM schedules
                        WHERE instructor_id = ?
                          AND day_of_week = ?
                          AND status = 'active'
                          $exclude_clause
                          AND (
                              (start_time <= ? AND {$end_time_compare_expr} > ?) OR
                              (start_time < ? AND {$end_time_compare_expr} >= ?) OR
                              (start_time >= ? AND {$end_time_compare_expr} <= ?)
                          )
                    ";
                    $ins_params = array_merge([
                        $instructor_id,
                        $day_of_week,
                        $start_time,
                        $start_time,
                        $end_time,
                        $end_time,
                        $start_time,
                        $end_time
                    ], $exclude_params);
                    $stmt = $conn->prepare($ins_sql);
                    $stmt->execute($ins_params);
                    if ((int)$stmt->fetchColumn() > 0) {
                        throw new Exception('The instructor has a schedule conflict on ' . $day_of_week . '.');
                    }
                }
            }

            $base_update_set = [
                'instructor_id' => $instructor_id,
                'classroom_id' => $classroom_id,
                'start_time' => $start_time,
            ];
            if ($has_course_id) {
                $base_update_set['course_id'] = $course_id;
            }
            if ($has_subject_id) {
                $base_update_set['subject_id'] = $subject_id;
            }
            if ($has_end_time) {
                $base_update_set['end_time'] = $end_time;
            } elseif ($has_duration_minutes) {
                $base_update_set['duration_minutes'] = $default_duration;
            }
            if ($has_start_date) {
                $base_update_set['start_date'] = $start_date;
            }
            if ($has_end_date) {
                $base_update_set['end_date'] = $end_date;
            }
            if (isset($cols['status'])) {
                $base_update_set['status'] = $target_status;
            }

            $conn->beginTransaction();

            foreach ($selected_days as $day_of_week) {
                if (isset($rows_to_update_by_day[$day_of_week])) {
                    $row_id = (int)($rows_to_update_by_day[$day_of_week]['id'] ?? 0);
                    if ($row_id <= 0) {
                        continue;
                    }

                    $set = $base_update_set;
                    $set['day_of_week'] = $day_of_week;

                    $assignments = [];
                    $values = [];
                    foreach ($set as $k => $v) {
                        $assignments[] = $k . ' = ?';
                        $values[] = $v;
                    }
                    if (isset($cols['updated_at'])) {
                        $assignments[] = 'updated_at = NOW()';
                    } elseif (isset($cols['created_at'])) {
                        // Fallback event timestamp for schemas without updated_at.
                        $assignments[] = 'created_at = NOW()';
                    }

                    $values[] = $row_id;
                    $sql = 'UPDATE schedules SET ' . implode(', ', $assignments) . ' WHERE id = ?';
                    $stmt = $conn->prepare($sql);
                    $stmt->execute($values);
                } else {
                    $fields = $base_update_set;
                    $fields['day_of_week'] = $day_of_week;
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
                }
            }

            foreach ($rows_to_remove as $row) {
                $row_id = (int)($row['id'] ?? 0);
                if ($row_id <= 0) {
                    continue;
                }
                $stmt = $conn->prepare('DELETE FROM schedules WHERE id = ? LIMIT 1');
                $stmt->execute([$row_id]);
            }

            $conn->commit();

            $_SESSION['success'] = 'Schedule updated successfully for ' . count($selected_days) . ' day(s): ' . implode(', ', $selected_days) . '.';
            break;
        }

        case 'delete': {
            if (empty($_POST['schedule_id'])) {
                throw new Exception('Schedule ID is required.');
            }

            $stmt = $conn->prepare("SELECT COUNT(*) FROM enrollments WHERE schedule_id = ? AND LOWER(COALESCE(status, '')) IN ('approved', 'enrolled', 'active')");
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
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    $message = $e->getMessage();
    $driverCode = is_array($e->errorInfo ?? null) ? ($e->errorInfo[1] ?? null) : null;
    if ((string)$e->getCode() === '23000' || (int)$driverCode === 1452) {
        $message = 'Unable to save schedule because one or more selected references are invalid. Please refresh and reselect Course, Subject, Instructor, and Classroom.';
    }
    $_SESSION['error'] = $message;
    header('Location: schedules.php');
    exit();
} catch (Exception $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    $_SESSION['error'] = $e->getMessage();
    header('Location: schedules.php');
    exit();
}
