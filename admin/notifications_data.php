<?php
// Shared notification data for Super Admin pages.
// Expects: $conn (PDO), $_SESSION

if (!isset($_SESSION)) {
    // Should never happen because pages call session_start(), but guard anyway.
    @session_start();
}

if (!isset($conn) || !($conn instanceof PDO)) {
    $notif_items = [];
    $notif_has_new = false;
    $notif_seen_at = $_SESSION['admin_notif_seen_at'] ?? null;
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

// Keep admin notifications isolated from registrar notifications.
try {
    $ns_cols = [];
    $stmt = $conn->prepare('DESCRIBE notification_state');
    $stmt->execute();
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if (!empty($r['Field'])) {
            $ns_cols[$r['Field']] = true;
        }
    }
    if (!isset($ns_cols['admin_notif_seen_at'])) {
        $conn->exec('ALTER TABLE notification_state ADD COLUMN admin_notif_seen_at DATETIME NULL AFTER notif_cleared_at');
    }
    if (!isset($ns_cols['admin_notif_cleared_at'])) {
        $conn->exec('ALTER TABLE notification_state ADD COLUMN admin_notif_cleared_at DATETIME NULL AFTER admin_notif_seen_at');
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
        $stmt = $conn->prepare('SELECT admin_notif_seen_at, admin_notif_cleared_at, notif_seen_at, notif_cleared_at FROM notification_state WHERE user_id = ?');
        $stmt->execute([$user_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            // Use scoped fields only to keep admin timeline isolated.
            $notif_seen_at = $row['admin_notif_seen_at'] ?? null;
            $notif_cleared_at = $row['admin_notif_cleared_at'] ?? null;
        }
    } catch (Throwable $e) {
        // ignore
    }
}

if (empty($notif_seen_at)) {
    $notif_seen_at = $notif_now;
    try {
        if ($user_id > 0) {
            $stmt = $conn->prepare('INSERT INTO notification_state (user_id, admin_notif_seen_at, admin_notif_cleared_at) VALUES (?, ?, NULL) ON DUPLICATE KEY UPDATE admin_notif_seen_at = VALUES(admin_notif_seen_at)');
            $stmt->execute([$user_id, $notif_seen_at]);
        }
    } catch (Throwable $e) {
        // ignore
    }
}

$_SESSION['admin_notif_seen_at'] = $notif_seen_at;
$_SESSION['admin_notif_cleared_at'] = $notif_cleared_at;

// Unread cutoff: based on seen/cleared timestamps.
$notif_unread_cutoff_at = $notif_seen_at;
if (!empty($notif_cleared_at) && (string)$notif_cleared_at > (string)$notif_unread_cutoff_at) {
    $notif_unread_cutoff_at = $notif_cleared_at;
}

// List cutoff: only clear/delete should hide old items from the list.
$notif_list_cutoff_at = !empty($notif_cleared_at) ? (string)$notif_cleared_at : '1970-01-01 00:00:00';

$notif_items = [];
$notif_has_new = false;
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

    // Instructors/students "new" since last seen
    if ($users_has_created_at) {
        $stmt = $conn->prepare("SELECT COUNT(*) FROM users WHERE role = 'instructor' AND created_at > :cutoff_at AND created_at <= :now_ts");
        $stmt->execute(['cutoff_at' => $notif_unread_cutoff_at, 'now_ts' => $notif_now]);
        $notif_new_instructors = (int)$stmt->fetchColumn();

        $stmt = $conn->prepare("SELECT COUNT(*) FROM users WHERE role = 'student' AND created_at > :cutoff_at AND created_at <= :now_ts");
        $stmt->execute(['cutoff_at' => $notif_unread_cutoff_at, 'now_ts' => $notif_now]);
        $notif_new_students = (int)$stmt->fetchColumn();
    }

    // Latest instructors/students for list display (independent of enrollments)
    $latest_instructors = [];
    $latest_students = [];
    if ($users_has_created_at) {
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

    // Enrollment schema checks (timestamp column/expression + status enum values)
    $enrollment_ts_expr = null;
    $status_enum_values = [];

    $cols_stmt = $conn->prepare('DESCRIBE enrollments');
    $cols_stmt->execute();
    $en_cols = $cols_stmt->fetchAll(PDO::FETCH_ASSOC);

    $col_set = [];
    foreach ($en_cols as $r) {
        $col_set[$r['Field']] = $r;
    }

    // Build a robust timestamp expression: prefer enrolled_at, then enrollment_date, then created_at.
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

    if ($enrollment_ts_expr !== null) {
        // Any new enrollments since cutoff (any status)
        $count_sql = "
            SELECT COUNT(*)
            FROM enrollments e
            WHERE $enrollment_ts_expr > ?
              AND $enrollment_ts_expr <= ?
        ";
        $stmt = $conn->prepare($count_sql);
                $stmt->execute([$notif_unread_cutoff_at, $notif_now]);
        $notif_new_enrollments = (int)$stmt->fetchColumn();

        $latest_enrollments = [];
        $list_ts_expr = $enrollment_ts_expr;

        $list_sql = "
            SELECT
                e.id,
                $list_ts_expr AS ts,
                e.status,
                u.first_name AS student_first,
                u.last_name AS student_last,
                c.course_code,
                c.course_name
            FROM enrollments e
            JOIN users u ON u.id = e.student_id
            JOIN schedules s ON s.id = e.schedule_id
            JOIN courses c ON c.id = s.course_id
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
            $course = trim(($r['course_code'] ?? '') . ' — ' . ($r['course_name'] ?? ''));
            $status = trim((string)($r['status'] ?? ''));
            $status_label = $status !== '' ? ('Status: ' . $status) : 'Enrollment update';
            $notif_items[] = [
                'ts' => (string)($r['ts'] ?? ''),
                'icon' => 'bi-person-plus',
                'title' => ($student !== '' ? $student : 'Student') . ' enrollment',
                'subtitle' => ($course !== '' ? $course : 'Enrollment') . ' • ' . $status_label,
                'href' => 'enrollments.php',
            ];
        }
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

    if (isset($s_cols['created_at'])) {
        $stmt = $conn->prepare("SELECT COUNT(*) FROM schedules WHERE created_at > :cutoff_at AND created_at <= :now_ts");
        $stmt->execute(['cutoff_at' => $notif_unread_cutoff_at, 'now_ts' => $notif_now]);
        $notif_new_classes = (int)$stmt->fetchColumn();

        $select_end = isset($s_cols['end_time']) ? ', s.end_time' : '';
        $stmt = $conn->prepare("
            SELECT s.id, s.created_at, s.day_of_week, s.start_time$select_end,
                   c.course_code, c.course_name,
                   r.room_number
            FROM schedules s
            JOIN courses c ON c.id = s.course_id
            JOIN classrooms r ON r.id = s.classroom_id
            WHERE s.created_at > :cutoff_at AND s.created_at <= :now_ts
            ORDER BY s.created_at DESC
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
            $notif_items[] = [
                'ts' => (string)($r['created_at'] ?? ''),
                'icon' => 'bi-calendar-plus',
                'title' => 'Class added',
                'subtitle' => $sub !== '' ? $sub : 'New class schedule',
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
                'href' => 'classrooms.php',
            ];
        }
    }

    usort($notif_items, function ($a, $b) {
        $ta = strtotime((string)($a['ts'] ?? '')) ?: 0;
        $tb = strtotime((string)($b['ts'] ?? '')) ?: 0;
        return $tb <=> $ta;
    });

    $notif_items = array_slice($notif_items, 0, 6);

    // Fallback: if counts say there are unread items but we couldn't build the list,
    // show a generic entry so the UI and red dot stay consistent.
    $any_unread = (
        $notif_new_enrollments > 0 ||
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
                'href' => 'enrollments.php',
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
                'title' => $notif_new_classes . ' new class(es)',
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
                'href' => 'classrooms.php',
            ];
        }
    }

    $notif_unread_total = (int)$notif_new_instructors + (int)$notif_new_students + (int)$notif_new_enrollments + (int)$notif_new_classes + (int)$notif_new_courses + (int)$notif_new_rooms;
    $notif_badge_label = $notif_unread_total > 99 ? '99+' : (string)$notif_unread_total;
    $notif_has_new = ($notif_unread_total > 0);
} catch (Throwable $e) {
    // If anything goes wrong, degrade gracefully to no notifications.
    $notif_items = [];
    $notif_has_new = false;
    $notif_new_instructors = 0;
    $notif_new_students = 0;
    $notif_new_enrollments = 0;
    $notif_new_classes = 0;
    $notif_new_courses = 0;
    $notif_new_rooms = 0;
    $notif_unread_total = 0;
    $notif_badge_label = '';
}
