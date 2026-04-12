<?php
// Shared notification data for Registrar pages.
// Expects: $conn (PDO), $_SESSION

if (!isset($_SESSION)) {
    @session_start();
}

if (!isset($conn) || !($conn instanceof PDO)) {
    $notif_items = [];
    $notif_has_new = false;
    $notif_seen_at = $_SESSION['registrar_notif_seen_at'] ?? null;
    $notif_new_registrars = 0;
    $notif_new_instructors = 0;
    $notif_new_students = 0;
    $notif_new_enrollments = 0;
    $notif_new_classes = 0;
    $notif_new_courses = 0;
    $notif_new_rooms = 0;
    $notif_unread_total = 0;
    $notif_badge_label = '';
    return;
}

if (!function_exists('__pct_enum_values_from_type')) {
    function __pct_enum_values_from_type($type) {
        $type = (string)$type;
        if (stripos($type, 'enum(') !== 0) return [];
        $inside = trim(substr($type, 5), ") ");
        if ($inside === '') return [];
        $parts = str_getcsv($inside, ',', "'");
        $values = [];
        foreach ($parts as $p) {
            $v = trim($p);
            if ($v !== '') $values[] = $v;
        }
        return $values;
    }
}

$user_id = (int)($_SESSION['user_id'] ?? 0);

// Keep registrar notifications isolated from super admin notifications.
try {
    $ns_cols = [];
    $stmt = $conn->prepare('DESCRIBE notification_state');
    $stmt->execute();
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if (!empty($r['Field'])) {
            $ns_cols[$r['Field']] = true;
        }
    }
    if (!isset($ns_cols['registrar_notif_seen_at'])) {
        $conn->exec('ALTER TABLE notification_state ADD COLUMN registrar_notif_seen_at DATETIME NULL AFTER notif_cleared_at');
    }
    if (!isset($ns_cols['registrar_notif_cleared_at'])) {
        $conn->exec('ALTER TABLE notification_state ADD COLUMN registrar_notif_cleared_at DATETIME NULL AFTER registrar_notif_seen_at');
    }
} catch (Throwable $e) {
    // keep compatibility with older schemas
}

try {
    $notif_now = (string)$conn->query('SELECT NOW()')->fetchColumn();
} catch (Throwable $e) {
    $notif_now = date('Y-m-d H:i:s');
}

$notif_seen_at = null;
$notif_cleared_at = null;

if ($user_id > 0) {
    try {
        $stmt = $conn->prepare('SELECT registrar_notif_seen_at, registrar_notif_cleared_at, notif_seen_at, notif_cleared_at FROM notification_state WHERE user_id = ?');
        $stmt->execute([$user_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            // Use scoped fields only to keep registrar timeline isolated.
            $notif_seen_at = $row['registrar_notif_seen_at'] ?? null;
            $notif_cleared_at = $row['registrar_notif_cleared_at'] ?? null;
        }
    } catch (Throwable $e) {
        // ignore
    }
}

if (empty($notif_seen_at)) {
    // First scoped run: keep a very old baseline so existing unseen events are not skipped.
    $notif_seen_at = '1970-01-01 00:00:00';
    try {
        if ($user_id > 0) {
            $stmt = $conn->prepare('INSERT INTO notification_state (user_id, registrar_notif_seen_at, registrar_notif_cleared_at) VALUES (?, ?, NULL) ON DUPLICATE KEY UPDATE registrar_notif_seen_at = VALUES(registrar_notif_seen_at)');
            $stmt->execute([$user_id, $notif_seen_at]);
        }
    } catch (Throwable $e) {
        // ignore
    }
}

$_SESSION['registrar_notif_seen_at'] = $notif_seen_at;
$_SESSION['registrar_notif_cleared_at'] = $notif_cleared_at;

$notif_unread_cutoff_at = $notif_seen_at;
if (!empty($notif_cleared_at) && (string)$notif_cleared_at > (string)$notif_unread_cutoff_at) {
    $notif_unread_cutoff_at = $notif_cleared_at;
}

// Keep list items until user explicitly clears/deletes notifications.
$notif_list_cutoff_at = !empty($notif_cleared_at) ? (string)$notif_cleared_at : '1970-01-01 00:00:00';

$notif_items = [];
$notif_has_new = false;
$notif_new_registrars = 0;
$notif_new_instructors = 0;
$notif_new_students = 0;
$notif_new_enrollments = 0;
$notif_new_classes = 0;
$notif_new_courses = 0;
$notif_new_rooms = 0;
$notif_unread_total = 0;
$notif_badge_label = '';

try {
    // Users schema checks
    $users_has_created_at = true;
    try {
        $u_cols_stmt = $conn->prepare('DESCRIBE users');
        $u_cols_stmt->execute();
        $users_has_created_at = false;
        foreach ($u_cols_stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if (($r['Field'] ?? null) === 'created_at') {
                $users_has_created_at = true;
                break;
            }
        }
    } catch (Throwable $e) {
        $users_has_created_at = true;
    }

    // New registrars/instructors/students since cutoff
    if ($users_has_created_at) {
        $stmt = $conn->prepare("SELECT COUNT(*) FROM users WHERE role = 'registrar' AND created_at > :cutoff_at AND created_at <= :now_ts");
        $stmt->execute(['cutoff_at' => $notif_unread_cutoff_at, 'now_ts' => $notif_now]);
        $notif_new_registrars = (int)$stmt->fetchColumn();

        $stmt = $conn->prepare("SELECT COUNT(*) FROM users WHERE role = 'instructor' AND created_at > :cutoff_at AND created_at <= :now_ts");
        $stmt->execute(['cutoff_at' => $notif_unread_cutoff_at, 'now_ts' => $notif_now]);
        $notif_new_instructors = (int)$stmt->fetchColumn();

        $stmt = $conn->prepare("SELECT COUNT(*) FROM users WHERE role = 'student' AND created_at > :cutoff_at AND created_at <= :now_ts");
        $stmt->execute(['cutoff_at' => $notif_unread_cutoff_at, 'now_ts' => $notif_now]);
        $notif_new_students = (int)$stmt->fetchColumn();
    }

    // Latest registrars/instructors/students for list display (independent of enrollments)
    $latest_registrars = [];
    $latest_instructors = [];
    $latest_students = [];
    if ($users_has_created_at) {
        $stmt = $conn->prepare(" 
            SELECT id, first_name, last_name, created_at
            FROM users
            WHERE role = 'registrar'
              AND created_at > :cutoff_at
              AND created_at <= :now_ts
            ORDER BY created_at DESC
            LIMIT 5
        ");
                $stmt->execute(['cutoff_at' => $notif_list_cutoff_at, 'now_ts' => $notif_now]);
        $latest_registrars = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stmt = $conn->prepare("
            SELECT id, first_name, last_name, created_at
            FROM users
            WHERE role = 'instructor'
              AND created_at > :cutoff_at
              AND created_at <= :now_ts
            ORDER BY created_at DESC
            LIMIT 5
        ");
                $stmt->execute(['cutoff_at' => $notif_list_cutoff_at, 'now_ts' => $notif_now]);
        $latest_instructors = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stmt = $conn->prepare("
            SELECT id, first_name, last_name, created_at
            FROM users
            WHERE role = 'student'
              AND created_at > :cutoff_at
              AND created_at <= :now_ts
            ORDER BY created_at DESC
            LIMIT 5
        ");
                $stmt->execute(['cutoff_at' => $notif_list_cutoff_at, 'now_ts' => $notif_now]);
        $latest_students = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Enrollment schema checks
    $enrollment_ts_expr = null;
    $status_enum_values = [];
    $enrollment_subject_join_sql = '';
    $enrollment_subject_code_expr = "NULLIF(TRIM(c.course_code), '')";
    $enrollment_subject_name_expr = "NULLIF(TRIM(c.course_name), '')";

    $cols_stmt = $conn->prepare('DESCRIBE enrollments');
    $cols_stmt->execute();
    $en_cols = $cols_stmt->fetchAll(PDO::FETCH_ASSOC);

    $col_set = [];
    foreach ($en_cols as $r) {
        $col_set[$r['Field']] = $r;
    }

    $ts_parts = [];
    foreach (['enrolled_at', 'enrollment_date', 'created_at'] as $cand) {
        if (isset($col_set[$cand])) {
            $ts_parts[] = "e.$cand";
        }
    }
    if (count($ts_parts) === 1) {
        $enrollment_ts_expr = $ts_parts[0];
    } elseif (count($ts_parts) > 1) {
        $enrollment_ts_expr = 'COALESCE(' . implode(', ', $ts_parts) . ')';
    }

    if (isset($col_set['status']) && isset($col_set['status']['Type'])) {
        $status_enum_values = __pct_enum_values_from_type($col_set['status']['Type']);
    }

    // Prefer subject details when schedules.subject_id and subjects table exist.
    try {
        $schedules_has_subject_id = false;
        $sched_cols_stmt = $conn->prepare('DESCRIBE schedules');
        $sched_cols_stmt->execute();
        foreach ($sched_cols_stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if (($r['Field'] ?? '') === 'subject_id') {
                $schedules_has_subject_id = true;
                break;
            }
        }

        $subjects_table_exists = false;
        $subjects_exists_stmt = $conn->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'subjects'");
        $subjects_exists_stmt->execute();
        $subjects_table_exists = ((int)$subjects_exists_stmt->fetchColumn() > 0);

        if ($schedules_has_subject_id && $subjects_table_exists) {
            $enrollment_subject_join_sql = 'LEFT JOIN subjects sub ON (s.subject_id IS NOT NULL AND s.subject_id = sub.id)';
            $enrollment_subject_code_expr = "COALESCE(NULLIF(TRIM(sub.subject_code), ''), NULLIF(TRIM(c.course_code), ''))";
            $enrollment_subject_name_expr = "COALESCE(NULLIF(TRIM(sub.subject_name), ''), NULLIF(TRIM(c.course_name), ''))";
        }
    } catch (Throwable $e) {
        // Keep course fallback when subject metadata is unavailable.
    }

    if ($enrollment_ts_expr !== null) {

        $count_sql = "
            SELECT COUNT(*)
            FROM enrollments e
            WHERE $enrollment_ts_expr > ?
              AND $enrollment_ts_expr <= ?
        ";
        $stmt = $conn->prepare($count_sql);
                $stmt->execute([$notif_unread_cutoff_at, $notif_now]);
        $notif_new_enrollments = (int)$stmt->fetchColumn();

        $list_ts_expr = $enrollment_ts_expr;

        $list_sql = "
            SELECT
                e.id,
                $list_ts_expr AS ts,
                e.status,
                u.first_name AS student_first,
                u.last_name AS student_last,
                {$enrollment_subject_code_expr} AS subject_code,
                {$enrollment_subject_name_expr} AS subject_name
            FROM enrollments e
            JOIN users u ON u.id = e.student_id
            JOIN schedules s ON s.id = e.schedule_id
            JOIN courses c ON c.id = s.course_id
            {$enrollment_subject_join_sql}
            WHERE $list_ts_expr > ?
              AND $list_ts_expr <= ?
            ORDER BY ts DESC
            LIMIT 5
        ";
        $stmt = $conn->prepare($list_sql);
            $stmt->execute([$notif_list_cutoff_at, $notif_now]);
        $latest_enrollments = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($latest_enrollments as $r) {
            $student = trim(($r['student_first'] ?? '') . ' ' . ($r['student_last'] ?? ''));
            $subject = trim(($r['subject_code'] ?? '') . ' — ' . ($r['subject_name'] ?? ''));
            $status = trim((string)($r['status'] ?? ''));
            $status_label = $status !== '' ? ('Status: ' . $status) : 'Enrollment update';
            $notif_items[] = [
                'ts' => (string)($r['ts'] ?? ''),
                'icon' => 'bi-person-plus',
                'title' => ($student !== '' ? $student : 'Student') . ' enrollment',
                'subtitle' => ($subject !== '' ? $subject : 'Enrollment') . ' • ' . $status_label,
                'href' => 'manage_enrollments.php',
            ];
        }
    }

    foreach ($latest_registrars as $r) {
        $name = trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? ''));
        $notif_items[] = [
            'ts' => (string)($r['created_at'] ?? ''),
            'icon' => 'bi-person-badge',
            'title' => ($name !== '' ? $name : 'Registrar') . ' added',
            'subtitle' => 'New registrar account',
            'href' => 'dashboard.php',
        ];
    }

    foreach ($latest_students as $r) {
        $name = trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? ''));
        $notif_items[] = [
            'ts' => (string)($r['created_at'] ?? ''),
            'icon' => 'bi-mortarboard',
            'title' => ($name !== '' ? $name : 'Student') . ' added',
            'subtitle' => 'New student account',
            'href' => 'students.php',
        ];
    }

    foreach ($latest_instructors as $r) {
        $name = trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? ''));
        $notif_items[] = [
            'ts' => (string)($r['created_at'] ?? ''),
            'icon' => 'bi-person-video3',
            'title' => ($name !== '' ? $name : 'Instructor') . ' added',
            'subtitle' => 'New instructor account',
            'href' => 'instructors.php',
        ];
    }

    // Classes (schedules), courses, rooms
    $s_cols = [];
    try {
        $s_stmt = $conn->prepare('DESCRIBE schedules');
        $s_stmt->execute();
        foreach ($s_stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if (!empty($r['Field'])) $s_cols[$r['Field']] = true;
        }
    } catch (Throwable $e) {
        $s_cols = [];
    }

    $schedule_ts_expr = null;
    if (isset($s_cols['updated_at']) && isset($s_cols['created_at'])) {
        $schedule_ts_expr = 'COALESCE(s.updated_at, s.created_at)';
    } elseif (isset($s_cols['updated_at'])) {
        $schedule_ts_expr = 's.updated_at';
    } elseif (isset($s_cols['created_at'])) {
        $schedule_ts_expr = 's.created_at';
    }

    if ($schedule_ts_expr !== null) {
        $stmt = $conn->prepare("SELECT COUNT(*) FROM schedules s WHERE $schedule_ts_expr > :cutoff_at AND $schedule_ts_expr <= :now_ts");
        $stmt->execute(['cutoff_at' => $notif_unread_cutoff_at, 'now_ts' => $notif_now]);
        $notif_new_classes = (int)$stmt->fetchColumn();

        $select_end = isset($s_cols['end_time']) ? ', s.end_time' : '';
        $select_created = isset($s_cols['created_at']) ? ', s.created_at' : ', NULL AS created_at';
        $select_updated = isset($s_cols['updated_at']) ? ', s.updated_at' : ', NULL AS updated_at';
        $stmt = $conn->prepare(" 
            SELECT s.id, $schedule_ts_expr AS event_ts, s.day_of_week, s.start_time$select_end,
                   c.course_code, c.course_name,
                   r.room_number
                   $select_created
                   $select_updated
            FROM schedules s
            JOIN courses c ON c.id = s.course_id
            JOIN classrooms r ON r.id = s.classroom_id
            WHERE $schedule_ts_expr > :cutoff_at AND $schedule_ts_expr <= :now_ts
            ORDER BY event_ts DESC
            LIMIT 5
        ");
        $stmt->execute(['cutoff_at' => $notif_list_cutoff_at, 'now_ts' => $notif_now]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $course = trim(($r['course_code'] ?? '') . ' — ' . ($r['course_name'] ?? ''));
            $when = trim((string)($r['day_of_week'] ?? '') . ' ' . (string)($r['start_time'] ?? ''));
            if (!empty($r['end_time'])) {
                $when .= '-' . (string)$r['end_time'];
            }
            $room = trim((string)($r['room_number'] ?? ''));
            $sub = $course;
            $extra = trim($when . ($room !== '' ? (' • Room ' . $room) : ''));
            if ($extra !== '') {
                $sub = ($sub !== '' ? ($sub . ' • ' . $extra) : $extra);
            }

            $created_ts = (string)($r['created_at'] ?? '');
            $updated_ts = (string)($r['updated_at'] ?? '');
            $is_updated = ($updated_ts !== '' && $created_ts !== '' && (strtotime($updated_ts) ?: 0) > ((strtotime($created_ts) ?: 0) + 1));

            $notif_items[] = [
                'ts' => (string)($r['event_ts'] ?? ''),
                'icon' => $is_updated ? 'bi-pencil-square' : 'bi-calendar-plus',
                'title' => $is_updated ? 'Class updated' : 'Class added',
                'subtitle' => $sub !== '' ? $sub : 'Class schedule change',
                'href' => 'classes.php',
            ];
        }
    }

    $c_cols = [];
    try {
        $c_stmt = $conn->prepare('DESCRIBE courses');
        $c_stmt->execute();
        foreach ($c_stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if (!empty($r['Field'])) $c_cols[$r['Field']] = true;
        }
    } catch (Throwable $e) {
        $c_cols = [];
    }

    if (isset($c_cols['created_at'])) {
        $stmt = $conn->prepare("SELECT COUNT(*) FROM courses WHERE created_at > :cutoff_at AND created_at <= :now_ts");
        $stmt->execute(['cutoff_at' => $notif_unread_cutoff_at, 'now_ts' => $notif_now]);
        $notif_new_courses = (int)$stmt->fetchColumn();

        $stmt = $conn->prepare("
            SELECT id, course_code, course_name, created_at
            FROM courses
            WHERE created_at > :cutoff_at AND created_at <= :now_ts
            ORDER BY created_at DESC
            LIMIT 5
        ");
        $stmt->execute(['cutoff_at' => $notif_list_cutoff_at, 'now_ts' => $notif_now]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $course = trim(($r['course_code'] ?? '') . ' — ' . ($r['course_name'] ?? ''));
            $notif_items[] = [
                'ts' => (string)($r['created_at'] ?? ''),
                'icon' => 'bi-journal-plus',
                'title' => 'Course added',
                'subtitle' => $course !== '' ? $course : 'New course',
                'href' => 'courses.php',
            ];
        }
    }

    $r_cols = [];
    try {
        $r_stmt = $conn->prepare('DESCRIBE classrooms');
        $r_stmt->execute();
        foreach ($r_stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if (!empty($r['Field'])) $r_cols[$r['Field']] = true;
        }
    } catch (Throwable $e) {
        $r_cols = [];
    }

    if (isset($r_cols['created_at'])) {
        $stmt = $conn->prepare("SELECT COUNT(*) FROM classrooms WHERE created_at > :cutoff_at AND created_at <= :now_ts");
        $stmt->execute(['cutoff_at' => $notif_unread_cutoff_at, 'now_ts' => $notif_now]);
        $notif_new_rooms = (int)$stmt->fetchColumn();

        $stmt = $conn->prepare("
            SELECT id, room_number, building, created_at
            FROM classrooms
            WHERE created_at > :cutoff_at AND created_at <= :now_ts
            ORDER BY created_at DESC
            LIMIT 5
        ");
        $stmt->execute(['cutoff_at' => $notif_list_cutoff_at, 'now_ts' => $notif_now]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $room = trim((string)($r['room_number'] ?? ''));
            $building = trim((string)($r['building'] ?? ''));
            $sub = $room !== '' ? ('Room ' . $room) : 'New room';
            if ($building !== '') {
                $sub .= ' • ' . $building;
            }
            $notif_items[] = [
                'ts' => (string)($r['created_at'] ?? ''),
                'icon' => 'bi-door-open',
                'title' => 'Room added',
                'subtitle' => $sub,
                'href' => 'rooms.php',
            ];
        }
    }

    usort($notif_items, function ($a, $b) {
        $ta = strtotime((string)($a['ts'] ?? '')) ?: 0;
        $tb = strtotime((string)($b['ts'] ?? '')) ?: 0;
        return $tb <=> $ta;
    });

    $any_unread = (
        $notif_new_enrollments > 0 ||
        $notif_new_registrars > 0 ||
        $notif_new_instructors > 0 ||
        $notif_new_students > 0 ||
        $notif_new_classes > 0 ||
        $notif_new_courses > 0 ||
        $notif_new_rooms > 0
    );

    if (empty($notif_items) && $any_unread) {
        if ($notif_new_enrollments > 0) {
            $notif_items[] = [
                'ts' => $notif_now,
                'icon' => 'bi-person-plus',
                'title' => $notif_new_enrollments . ' new enrollment(s)',
                'subtitle' => 'Review enrollments',
                'href' => 'manage_enrollments.php',
            ];
        }
        if ($notif_new_registrars > 0) {
            $notif_items[] = [
                'ts' => $notif_now,
                'icon' => 'bi-person-badge',
                'title' => $notif_new_registrars . ' new registrar(s)',
                'subtitle' => 'New registrar accounts',
                'href' => 'dashboard.php',
            ];
        }
        if ($notif_new_instructors > 0) {
            $notif_items[] = [
                'ts' => $notif_now,
                'icon' => 'bi-person-video3',
                'title' => $notif_new_instructors . ' new instructor(s)',
                'subtitle' => 'Review instructors',
                'href' => 'instructors.php',
            ];
        }
        if ($notif_new_students > 0) {
            $notif_items[] = [
                'ts' => $notif_now,
                'icon' => 'bi-mortarboard',
                'title' => $notif_new_students . ' new student(s)',
                'subtitle' => 'Review students',
                'href' => 'students.php',
            ];
        }
        if ($notif_new_classes > 0) {
            $notif_items[] = [
                'ts' => $notif_now,
                'icon' => 'bi-calendar-plus',
                'title' => $notif_new_classes . ' class change(s)',
                'subtitle' => 'Review classes',
                'href' => 'classes.php',
            ];
        }
        if ($notif_new_courses > 0) {
            $notif_items[] = [
                'ts' => $notif_now,
                'icon' => 'bi-journal-plus',
                'title' => $notif_new_courses . ' new course(s)',
                'subtitle' => 'Review courses',
                'href' => 'courses.php',
            ];
        }
        if ($notif_new_rooms > 0) {
            $notif_items[] = [
                'ts' => $notif_now,
                'icon' => 'bi-door-open',
                'title' => $notif_new_rooms . ' new room(s)',
                'subtitle' => 'Review rooms',
                'href' => 'rooms.php',
            ];
        }
    }

    $notif_unread_total = (int)$notif_new_registrars + (int)$notif_new_instructors + (int)$notif_new_students + (int)$notif_new_enrollments + (int)$notif_new_classes + (int)$notif_new_courses + (int)$notif_new_rooms;
    $notif_badge_label = $notif_unread_total > 99 ? '99+' : (string)$notif_unread_total;
    $notif_has_new = ($notif_unread_total > 0);
} catch (Throwable $e) {
    $notif_items = [];
    $notif_has_new = false;
    $notif_new_registrars = 0;
    $notif_new_instructors = 0;
    $notif_new_students = 0;
    $notif_new_enrollments = 0;
    $notif_new_classes = 0;
    $notif_new_courses = 0;
    $notif_new_rooms = 0;
    $notif_unread_total = 0;
    $notif_badge_label = '';
}
