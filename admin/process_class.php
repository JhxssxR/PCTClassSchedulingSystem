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

function get_setting_int(PDO $conn, string $key, int $default): int {
    try {
        $stmt = $conn->prepare('SELECT `value` FROM settings WHERE `key` = ? LIMIT 1');
        $stmt->execute([$key]);
        $v = $stmt->fetchColumn();
        if ($v === false || $v === null) return $default;
        $i = (int)$v;
        return $i > 0 ? $i : $default;
    } catch (Throwable $e) {
        return $default;
    }
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

    foreach (['course_id', 'subject_id', 'instructor_id', 'classroom_id', 'max_students'] as $f) {
        if (isset($cols[$f])) {
            $where[] = "COALESCE($f, 0) = ?";
            $params[] = (int)($base_row[$f] ?? 0);
        }
    }
    foreach (['status', 'semester', 'academic_year', 'year_level'] as $f) {
        if (isset($cols[$f])) {
            $where[] = "COALESCE($f, '') = ?";
            $params[] = (string)($base_row[$f] ?? '');
        }
    }

    $where[] = "TIME_FORMAT(start_time, '%H:%i:%s') = ?";
    $params[] = normalize_time_his((string)($base_row['start_time'] ?? ''));

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

function ensure_schedule_updated_at_column(PDO $conn, array &$cols): void {
    if (isset($cols['updated_at'])) {
        return;
    }

    try {
        $conn->exec("ALTER TABLE schedules ADD COLUMN updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at");
        $cols['updated_at'] = true;
    } catch (Throwable $e) {
        // Ignore if schema cannot be altered (permissions/old MySQL), we'll continue without updated_at.
    }
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

function is_instructor_user(PDO $conn, int $user_id): bool {
    if ($user_id <= 0) {
        return false;
    }
    $stmt = $conn->prepare("SELECT 1 FROM users WHERE id = ? AND role = 'instructor' LIMIT 1");
    $stmt->execute([$user_id]);
    return (bool)$stmt->fetchColumn();
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
    ensure_schedule_updated_at_column($conn, $cols);
    ensure_schedule_end_time_column($conn, $cols);

    $has_end_time = isset($cols['end_time']);
    $has_duration_minutes = isset($cols['duration_minutes']);
    $default_duration = get_setting_int($conn, 'default_class_duration', 120);
    if ($default_duration < 30) {
        $default_duration = 120;
    }
    $allowed_days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
    $end_time_compare_expr = $has_end_time
        ? 'end_time'
        : ($has_duration_minutes ? 'ADDTIME(start_time, SEC_TO_TIME(duration_minutes * 60))' : 'ADDTIME(start_time, SEC_TO_TIME(120 * 60))');

    switch ($action) {
        case 'add': {
            $has_subject_id = isset($cols['subject_id']);
            $required = ['course_id', 'year_level', 'instructor_id', 'classroom_id', 'start_time'];
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
            $selected_days = normalize_selected_days($_POST['day_of_week'] ?? [], $allowed_days);
            if (empty($selected_days)) {
                throw new Exception('Please select at least one valid day');
            }
            $start_time = normalize_time_his((string)$_POST['start_time']);
            $end_time = normalize_time_his((string)($_POST['end_time'] ?? ''));
            if ($has_end_time) {
                if ($end_time === '') {
                    throw new Exception('End time is required');
                }
            } else {
                $end_time = add_minutes_to_time($start_time, $default_duration);
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
            if (!is_instructor_user($conn, $instructor_id)) {
                throw new Exception('Selected user is not an instructor. Please refresh and select a valid instructor.');
            }
            if (!record_exists($conn, 'classrooms', $classroom_id)) {
                throw new Exception('Selected classroom is invalid or no longer exists. Please refresh and select again.');
            }
            if ($has_subject_id && !record_exists($conn, 'subjects', $subject_id)) {
                throw new Exception('Selected subject is invalid or no longer exists. Please refresh and select again.');
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
                $stmt->execute([$classroom_id, $day_of_week, $start_time, $start_time, $end_time, $end_time, $start_time, $end_time]);
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
                $stmt->execute([$instructor_id, $day_of_week, $start_time, $start_time, $end_time, $end_time, $start_time, $end_time]);
                if ((int)$stmt->fetchColumn() > 0) {
                    $conflicts[] = 'Instructor conflict on ' . $day_of_week . '.';
                }
            }

            if (!empty($conflicts)) {
                throw new Exception("Unable to add class for selected days:\n" . implode("\n", $conflicts));
            }

            $conn->beginTransaction();
            $created_days = [];
            foreach ($selected_days as $day_of_week) {
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
                } elseif ($has_duration_minutes) {
                    $fields['duration_minutes'] = $default_duration;
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
                $created_days[] = $day_of_week;
            }
            $conn->commit();

            $_SESSION['success'] = count($created_days) === 1
                ? 'Class added successfully'
                : ('Classes added successfully for ' . count($created_days) . ' days: ' . implode(', ', $created_days) . '.');
            break;
        }

        case 'edit': {
            if (empty($_POST['schedule_id'])) {
                throw new Exception('Schedule ID is required');
            }
            $has_subject_id = isset($cols['subject_id']);
            $required = ['course_id', 'year_level', 'instructor_id', 'classroom_id', 'start_time'];
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
            $selected_days = normalize_selected_days($_POST['day_of_week'] ?? [], $allowed_days);
            if (empty($selected_days)) {
                throw new Exception('Please select at least one valid day');
            }
            $start_time = normalize_time_his((string)$_POST['start_time']);
            $end_time = normalize_time_his((string)($_POST['end_time'] ?? ''));
            if ($has_end_time) {
                if ($end_time === '') {
                    throw new Exception('End time is required');
                }
            } else {
                $end_time = add_minutes_to_time($start_time, $default_duration);
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
            $stmt = $conn->prepare('SELECT * FROM schedules WHERE id = ?');
            $stmt->execute([$schedule_id]);
            $current = $stmt->fetch();
            if (!$current) {
                throw new Exception('Class record was not found. Please refresh the page and try again.');
            }
            if (!record_exists($conn, 'courses', $course_id)) {
                throw new Exception('Selected course is invalid or no longer exists. Please refresh and select again.');
            }
            if (!record_exists($conn, 'users', $instructor_id)) {
                throw new Exception('Selected instructor is invalid or no longer exists. Please refresh and select again.');
            }
            if (!is_instructor_user($conn, $instructor_id)) {
                throw new Exception('Selected user is not an instructor. Please refresh and select a valid instructor.');
            }
            if (!record_exists($conn, 'classrooms', $classroom_id)) {
                throw new Exception('Selected classroom is invalid or no longer exists. Please refresh and select again.');
            }
            if ($has_subject_id && !record_exists($conn, 'subjects', $subject_id)) {
                throw new Exception('Selected subject is invalid or no longer exists. Please refresh and select again.');
            }

            $current_start_time = normalize_time_his((string)($current['start_time'] ?? ''));
            $current_end_time = schedule_effective_end_time($current, $has_end_time, $has_duration_minutes, $default_duration);
            if ($current_start_time === '' || $current_end_time === '') {
                throw new Exception('Current class time is invalid. Please contact the administrator.');
            }

            $linked_rows = find_linked_schedule_rows(
                $conn,
                [
                    'course_id' => (int)$current['course_id'],
                    'subject_id' => $has_subject_id ? (int)($current['subject_id'] ?? 0) : 0,
                    'year_level' => normalize_year_level($current['year_level'] ?? null),
                    'instructor_id' => (int)$current['instructor_id'],
                    'classroom_id' => (int)$current['classroom_id'],
                    'start_time' => $current_start_time,
                    'end_time' => $current_end_time,
                    'semester' => isset($cols['semester']) ? trim((string)($current['semester'] ?? '')) : null,
                    'academic_year' => isset($cols['academic_year']) ? trim((string)($current['academic_year'] ?? '')) : null,
                    'status' => isset($cols['status']) ? (string)($current['status'] ?? 'active') : 'active',
                ],
                $cols,
                $has_end_time,
                $has_duration_minutes,
                $default_duration
            );

            $linked_ids = [];
            $linked_by_day = [];
            foreach ($linked_rows as $row) {
                $row_id = (int)$row['id'];
                $linked_ids[] = $row_id;
                $row_day = (string)($row['day_of_week'] ?? '');
                if ($row_day !== '') {
                    if (!isset($linked_by_day[$row_day])) {
                        $linked_by_day[$row_day] = [];
                    }
                    $linked_by_day[$row_day][] = $row;
                }
            }
            if (empty($linked_ids)) {
                $linked_ids[] = $schedule_id;
            }
            $linked_ids = array_values(array_unique(array_map('intval', $linked_ids)));

            $fallback_days = $selected_days;
            if (!empty($fallback_days)) {
                $fallback_where = ['course_id = ?'];
                $fallback_params = [(int)($current['course_id'] ?? 0)];

                if ($has_subject_id) {
                    $fallback_where[] = 'COALESCE(subject_id, 0) = ?';
                    $fallback_params[] = (int)($current['subject_id'] ?? 0);
                }
                if (isset($cols['year_level'])) {
                    $current_year_level = normalize_year_level($current['year_level'] ?? null);
                    if ($current_year_level > 0) {
                        $fallback_where[] = '(COALESCE(year_level, 0) = ? OR COALESCE(year_level, 0) = 0)';
                        $fallback_params[] = $current_year_level;
                    }
                }
                if (isset($cols['semester'])) {
                    $current_semester = trim((string)($current['semester'] ?? ''));
                    if ($current_semester !== '') {
                        $fallback_where[] = "(COALESCE(semester, '') = ? OR COALESCE(semester, '') = '')";
                        $fallback_params[] = $current_semester;
                    }
                }
                if (isset($cols['academic_year'])) {
                    $current_academic_year = trim((string)($current['academic_year'] ?? ''));
                    if ($current_academic_year !== '') {
                        $fallback_where[] = "(COALESCE(academic_year, '') = ? OR COALESCE(academic_year, '') = '')";
                        $fallback_params[] = $current_academic_year;
                    }
                }

                $fallback_where[] = "TIME_FORMAT(start_time, '%H:%i:%s') = ?";
                $fallback_params[] = $current_start_time;

                if (!empty($linked_ids)) {
                    $fallback_where[] = 'id NOT IN (' . implode(', ', array_fill(0, count($linked_ids), '?')) . ')';
                    $fallback_params = array_merge($fallback_params, $linked_ids);
                }

                $fallback_where[] = 'day_of_week IN (' . implode(', ', array_fill(0, count($fallback_days), '?')) . ')';
                $fallback_params = array_merge($fallback_params, $fallback_days);

                $fallback_sql = 'SELECT * FROM schedules WHERE ' . implode(' AND ', $fallback_where);
                $fallback_stmt = $conn->prepare($fallback_sql);
                $fallback_stmt->execute($fallback_params);
                $fallback_rows = $fallback_stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

                $fallback_by_day = [];
                foreach ($fallback_rows as $fallback_row) {
                    $fallback_end = schedule_effective_end_time($fallback_row, $has_end_time, $has_duration_minutes, $default_duration);
                    if ($fallback_end !== $current_end_time) {
                        continue;
                    }

                    $fallback_day = (string)($fallback_row['day_of_week'] ?? '');
                    if ($fallback_day === '') {
                        continue;
                    }

                    if (!isset($fallback_by_day[$fallback_day])) {
                        $fallback_by_day[$fallback_day] = [];
                    }
                    $fallback_by_day[$fallback_day][] = $fallback_row;
                }

                foreach ($fallback_days as $fallback_day_name) {
                    if (!isset($fallback_by_day[$fallback_day_name])) {
                        continue;
                    }

                    if (!isset($linked_by_day[$fallback_day_name])) {
                        $linked_by_day[$fallback_day_name] = [];
                    }

                    $existing_day_ids = array_map(static function ($r) {
                        return (int)($r['id'] ?? 0);
                    }, $linked_by_day[$fallback_day_name]);

                    foreach ($fallback_by_day[$fallback_day_name] as $fallback_row) {
                        $fallback_id = (int)($fallback_row['id'] ?? 0);
                        if ($fallback_id <= 0 || in_array($fallback_id, $existing_day_ids, true)) {
                            continue;
                        }

                        $linked_by_day[$fallback_day_name][] = $fallback_row;
                        $existing_day_ids[] = $fallback_id;
                        $linked_ids[] = $fallback_id;
                    }
                }

                $linked_ids = array_values(array_unique(array_map('intval', $linked_ids)));
            }

            $conflicts = [];
            foreach ($selected_days as $day_of_week) {
                $exclude_clause = '';
                $exclude_params = [];
                if (!empty($linked_ids)) {
                    $exclude_clause = ' AND id NOT IN (' . implode(', ', array_fill(0, count($linked_ids), '?')) . ')';
                    $exclude_params = $linked_ids;
                }

                $room_sql = "
                    SELECT COUNT(*)
                    FROM schedules
                    WHERE classroom_id = ?
                    AND day_of_week = ?
                    AND status = 'active'" . $exclude_clause . "
                    AND (
                        (start_time <= ? AND {$end_time_compare_expr} > ?) OR
                        (start_time < ? AND {$end_time_compare_expr} >= ?) OR
                        (start_time >= ? AND {$end_time_compare_expr} <= ?)
                    )
                ";
                $room_params = array_merge([$classroom_id, $day_of_week], $exclude_params, [$start_time, $start_time, $end_time, $end_time, $start_time, $end_time]);
                $stmt = $conn->prepare($room_sql);
                $stmt->execute($room_params);
                if ((int)$stmt->fetchColumn() > 0) {
                    $conflicts[] = 'Room conflict on ' . $day_of_week . '.';
                }

                $instructor_sql = "
                    SELECT COUNT(*)
                    FROM schedules
                    WHERE instructor_id = ?
                    AND day_of_week = ?
                    AND status = 'active'" . $exclude_clause . "
                    AND (
                        (start_time <= ? AND {$end_time_compare_expr} > ?) OR
                        (start_time < ? AND {$end_time_compare_expr} >= ?) OR
                        (start_time >= ? AND {$end_time_compare_expr} <= ?)
                    )
                ";
                $instructor_params = array_merge([$instructor_id, $day_of_week], $exclude_params, [$start_time, $start_time, $end_time, $end_time, $start_time, $end_time]);
                $stmt = $conn->prepare($instructor_sql);
                $stmt->execute($instructor_params);
                if ((int)$stmt->fetchColumn() > 0) {
                    $conflicts[] = 'Instructor conflict on ' . $day_of_week . '.';
                }
            }

            if (!empty($conflicts)) {
                throw new Exception("Unable to update class for selected days:\n" . implode("\n", $conflicts));
            }

            $existing_days = array_keys($linked_by_day);
            $days_to_insert = array_values(array_diff($selected_days, $existing_days));
            $days_to_remove = array_values(array_diff($existing_days, $selected_days));

            if (!empty($days_to_remove)) {
                $remove_ids = [];
                foreach ($days_to_remove as $day) {
                    if (isset($linked_by_day[$day])) {
                        foreach ($linked_by_day[$day] as $row) {
                            $row_id = (int)($row['id'] ?? 0);
                            if ($row_id > 0) {
                                $remove_ids[] = $row_id;
                            }
                        }
                    }
                }
                if (!empty($remove_ids)) {
                    $remove_ids = array_values(array_unique(array_map('intval', $remove_ids)));
                    $active_counts = fetch_active_enrollment_counts($conn, $remove_ids);
                    $blocked_days = [];
                    foreach ($days_to_remove as $day) {
                        if (!isset($linked_by_day[$day])) {
                            continue;
                        }
                        $day_has_active = false;
                        foreach ($linked_by_day[$day] as $row) {
                            $row_id = (int)($row['id'] ?? 0);
                            if ($row_id > 0 && (($active_counts[$row_id] ?? 0) > 0)) {
                                $day_has_active = true;
                                break;
                            }
                        }
                        if ($day_has_active) {
                            $blocked_days[] = $day;
                        }
                    }
                    if (!empty($blocked_days)) {
                        throw new Exception('Cannot remove day(s) with active enrollments: ' . implode(', ', $blocked_days) . '.');
                    }
                }
            }

            $conn->beginTransaction();

            $updated_days = [];
            $duplicate_remove_ids = [];
            foreach ($selected_days as $day_of_week) {
                if (!isset($linked_by_day[$day_of_week])) {
                    continue;
                }

                $target_ids = [];
                foreach ($linked_by_day[$day_of_week] as $row) {
                    $row_id = (int)($row['id'] ?? 0);
                    if ($row_id > 0) {
                        $target_ids[] = $row_id;
                    }
                }
                $target_ids = array_values(array_unique(array_map('intval', $target_ids)));
                if (empty($target_ids)) {
                    continue;
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
                } elseif ($has_duration_minutes) {
                    $set['duration_minutes'] = $default_duration;
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
                $sql = 'UPDATE schedules SET ' . implode(', ', $assignments) . ' WHERE id = ?';
                $stmt = $conn->prepare($sql);

                foreach ($target_ids as $target_id) {
                    $run_values = $values;
                    $run_values[] = $target_id;
                    $stmt->execute($run_values);
                }

                if (count($target_ids) > 1) {
                    $active_counts = fetch_active_enrollment_counts($conn, $target_ids);
                    $keep_id = $target_ids[0];
                    foreach ($target_ids as $target_id) {
                        if (($active_counts[$target_id] ?? 0) > 0) {
                            $keep_id = $target_id;
                            break;
                        }
                    }

                    foreach ($target_ids as $target_id) {
                        if ($target_id === $keep_id) {
                            continue;
                        }
                        if (($active_counts[$target_id] ?? 0) <= 0) {
                            $duplicate_remove_ids[] = $target_id;
                        }
                    }
                }

                $updated_days[] = $day_of_week;
            }

            $duplicate_remove_ids = array_values(array_unique(array_map('intval', $duplicate_remove_ids)));
            foreach ($duplicate_remove_ids as $remove_id) {
                $stmt = $conn->prepare("DELETE FROM enrollments WHERE schedule_id = ? AND status NOT IN ('approved', 'enrolled')");
                $stmt->execute([$remove_id]);

                $stmt = $conn->prepare('DELETE FROM schedules WHERE id = ?');
                $stmt->execute([$remove_id]);
            }

            $inserted_days = [];
            foreach ($days_to_insert as $day_of_week) {
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
                } elseif ($has_duration_minutes) {
                    $fields['duration_minutes'] = $default_duration;
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
                $inserted_days[] = $day_of_week;
            }

            $removed_days = [];
            foreach ($days_to_remove as $day_of_week) {
                if (!isset($linked_by_day[$day_of_week])) {
                    continue;
                }

                $day_removed = false;
                foreach ($linked_by_day[$day_of_week] as $row) {
                    $remove_id = (int)($row['id'] ?? 0);
                    if ($remove_id <= 0) {
                        continue;
                    }

                    $stmt = $conn->prepare("DELETE FROM enrollments WHERE schedule_id = ? AND status NOT IN ('approved', 'enrolled')");
                    $stmt->execute([$remove_id]);

                    $stmt = $conn->prepare('DELETE FROM schedules WHERE id = ?');
                    $stmt->execute([$remove_id]);
                    $day_removed = true;
                }

                if ($day_removed) {
                    $removed_days[] = $day_of_week;
                }
            }

            $conn->commit();

            $msg = 'Class updated successfully';
            if (!empty($inserted_days) || !empty($removed_days)) {
                $parts = [];
                if (!empty($inserted_days)) {
                    $parts[] = 'added days: ' . implode(', ', $inserted_days);
                }
                if (!empty($removed_days)) {
                    $parts[] = 'removed days: ' . implode(', ', $removed_days);
                }
                $msg .= ' (' . implode(' | ', $parts) . ')';
            }
            $_SESSION['success'] = $msg;
            break;
        }

        case 'delete': {
            if (empty($_POST['schedule_id'])) {
                throw new Exception('Schedule ID is required');
            }
            $schedule_id = (int)$_POST['schedule_id'];

            $conn->beginTransaction();
            try {
                $stmt = $conn->prepare("SELECT COUNT(*) FROM enrollments WHERE schedule_id = ? AND status IN ('approved', 'enrolled')");
                $stmt->execute([$schedule_id]);
                if ((int)$stmt->fetchColumn() > 0) {
                    throw new Exception('Cannot delete class with active enrollments. Use force delete to remove enrolled students too.');
                }

                // Remove non-active enrollments first to satisfy FK enrollments.schedule_id -> schedules.id.
                $stmt = $conn->prepare("DELETE FROM enrollments WHERE schedule_id = ? AND status NOT IN ('approved', 'enrolled')");
                $stmt->execute([$schedule_id]);

                $stmt = $conn->prepare('DELETE FROM schedules WHERE id = ?');
                $stmt->execute([$schedule_id]);

                if ($stmt->rowCount() === 0) {
                    throw new Exception('Class record was not found or already deleted.');
                }

                $conn->commit();
            } catch (Throwable $e) {
                if ($conn->inTransaction()) {
                    $conn->rollBack();
                }
                throw $e;
            }

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
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    $message = $e->getMessage();
    $driverCode = is_array($e->errorInfo ?? null) ? ($e->errorInfo[1] ?? null) : null;
    if ((int)$driverCode === 1451) {
        $message = 'Cannot delete this class because it is still referenced by enrollments. Try force delete to remove related enrollments first.';
    }
    if ((string)$e->getCode() === '23000' || (int)$driverCode === 1452) {
        $invalid_refs = [];

        $course_id = (int)($_POST['course_id'] ?? 0);
        $instructor_id = (int)($_POST['instructor_id'] ?? 0);
        $classroom_id = (int)($_POST['classroom_id'] ?? 0);
        $subject_id = (int)($_POST['subject_id'] ?? 0);

        if ($course_id > 0 && !record_exists($conn, 'courses', $course_id)) {
            $invalid_refs[] = 'Course';
        }
        if ($instructor_id > 0 && !record_exists($conn, 'users', $instructor_id)) {
            $invalid_refs[] = 'Instructor';
        }
        if ($instructor_id > 0 && record_exists($conn, 'users', $instructor_id) && !is_instructor_user($conn, $instructor_id)) {
            $invalid_refs[] = 'Instructor (role mismatch)';
        }
        if ($classroom_id > 0 && !record_exists($conn, 'classrooms', $classroom_id)) {
            $invalid_refs[] = 'Classroom';
        }
        if ($subject_id > 0 && !record_exists($conn, 'subjects', $subject_id)) {
            $invalid_refs[] = 'Subject';
        }

        if (!empty($invalid_refs)) {
            $message = 'Unable to save class because these references are invalid: ' . implode(', ', $invalid_refs) . '. Please refresh and reselect.';
        } else {
            $message = 'Unable to save class because one or more selected references are invalid. Please refresh and reselect Course, Subject, Instructor, and Classroom.';
        }
    }
    $_SESSION['error'] = $message;
    header('Location: classes.php');
    exit();
} catch (Exception $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    $_SESSION['error'] = $e->getMessage();
    header('Location: classes.php');
    exit();
}
