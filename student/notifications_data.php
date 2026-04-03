<?php
// Shared notification data for Student pages.
// Expects: $conn (PDO), $_SESSION

if (!isset($_SESSION)) {
    @session_start();
}

if (!isset($conn) || !($conn instanceof PDO)) {
    $notif_items = [];
    $notif_has_new = false;
    $notif_seen_at = $_SESSION['notif_seen_at'] ?? null;
    return;
}

$user_id = (int)($_SESSION['user_id'] ?? 0);

function student_notif_has_column(PDO $conn, string $table, string $column): bool {
    try {
        $stmt = $conn->query("SHOW COLUMNS FROM `" . $table . "` LIKE " . $conn->quote($column));
        return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return false;
    }
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
        $stmt = $conn->prepare('SELECT notif_seen_at, notif_cleared_at FROM notification_state WHERE user_id = ?');
        $stmt->execute([$user_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $notif_seen_at = $row['notif_seen_at'] ?? null;
            $notif_cleared_at = $row['notif_cleared_at'] ?? null;
        }
    } catch (Throwable $e) {
        // ignore
    }
}

if (empty($notif_seen_at)) {
    $notif_seen_at = null;
}

$notif_cutoff_at = '1970-01-01 00:00:00';
if (!empty($notif_seen_at)) {
    $notif_cutoff_at = (string)$notif_seen_at;
}
if (!empty($notif_cleared_at) && (string)$notif_cleared_at > (string)$notif_cutoff_at) {
    $notif_cutoff_at = $notif_cleared_at;
}

$notif_items = [];
$notif_has_new = false;
$notif_new_enrollments = 0;
$notif_unread_total = 0;
$notif_badge_label = '';
$student_id = (int)($_SESSION['user_id'] ?? 0);

try {
    $enroll_ts_candidates = [];
    foreach (['enrolled_at', 'enrollment_date', 'created_at', 'updated_at', 'dropped_at', 'rejected_at'] as $col) {
        if (student_notif_has_column($conn, 'enrollments', $col)) {
            $enroll_ts_candidates[] = 'e.' . $col;
        }
    }

    $notif_has_event_ts = count($enroll_ts_candidates) > 0;

    $schedule_end_expr = 'ADDTIME(s.start_time, SEC_TO_TIME(120 * 60))';
    if (student_notif_has_column($conn, 'schedules', 'end_time')) {
        $schedule_end_expr = 's.end_time';
    } elseif (student_notif_has_column($conn, 'schedules', 'duration_minutes')) {
        $schedule_end_expr = 'ADDTIME(s.start_time, SEC_TO_TIME(COALESCE(s.duration_minutes, 120) * 60))';
    }

    $subjects_table_exists = false;
    try {
        $subjects_exists_stmt = $conn->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'subjects'");
        $subjects_exists_stmt->execute();
        $subjects_table_exists = ((int)$subjects_exists_stmt->fetchColumn() > 0);
    } catch (Throwable $e) {
        $subjects_table_exists = false;
    }
    $subjects_enabled = ($subjects_table_exists && student_notif_has_column($conn, 'schedules', 'subject_id'));

    $subject_code_expr = $subjects_enabled
        ? "COALESCE(subj.subject_code, 'N/A')"
        : "COALESCE(c.course_code, 'N/A')";
    $subject_name_expr = $subjects_enabled
        ? "COALESCE(subj.subject_name, 'Untitled Subject')"
        : "COALESCE(c.course_name, 'Untitled Subject')";
    $subject_join_sql = $subjects_enabled
        ? 'LEFT JOIN subjects subj ON subj.id = s.subject_id'
        : '';

    $enroll_ts_expr = 'NULL';
    if (count($enroll_ts_candidates) === 1) {
        $enroll_ts_expr = $enroll_ts_candidates[0];
    } elseif (count($enroll_ts_candidates) > 1) {
        $enroll_ts_expr = 'COALESCE(' . implode(', ', $enroll_ts_candidates) . ')';
    } else {
        // Fallback keeps list visible even on schemas without enrollment timestamps.
        $enroll_ts_expr = "'1970-01-01 00:00:00'";
    }

    // Enrollment status events (enrolled/approved/active/dropped/rejected).
    if ($student_id > 0) {
        $sql = "
            SELECT
                e.id AS enrollment_id,
                {$enroll_ts_expr} AS event_ts,
                e.status AS enrollment_status,
                s.id AS schedule_id,
                s.day_of_week,
                TIME_FORMAT(s.start_time, '%H:%i:%s') AS start_time,
                TIME_FORMAT({$schedule_end_expr}, '%H:%i:%s') AS end_time,
                {$subject_code_expr} AS subject_code,
                {$subject_name_expr} AS subject_name,
                COALESCE(cr.room_number, 'TBA') AS room_number,
                COALESCE(i.first_name, '') AS instructor_first,
                COALESCE(i.last_name, '') AS instructor_last
                        FROM enrollments e
                        LEFT JOIN schedules s ON s.id = e.schedule_id
            LEFT JOIN courses c ON c.id = s.course_id
            {$subject_join_sql}
            LEFT JOIN classrooms cr ON cr.id = s.classroom_id
            LEFT JOIN users i ON i.id = s.instructor_id
            WHERE e.student_id = :sid
                            AND LOWER(COALESCE(e.status, '')) IN ('approved', 'enrolled', 'active', 'pending', 'dropped', 'rejected')
                        ORDER BY {$enroll_ts_expr} DESC, e.id DESC
                        LIMIT 30
        ";

        $stmt = $conn->prepare($sql);
        $stmt->execute(['sid' => $student_id]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $notif_new_enrollments = 0;

        foreach ($rows as $r) {
            $event_ts = (string)($r['event_ts'] ?? '');
            $is_unread = ($event_ts !== '' && $event_ts > (string)$notif_cutoff_at);
            if ($is_unread) {
                $notif_new_enrollments++;
            }

            $item_ts = $event_ts !== '' ? $event_ts : $notif_now;
            $subject = trim(($r['subject_code'] ?? '') . ' — ' . ($r['subject_name'] ?? ''));
            $dayLabel = (string)($r['day_of_week'] ?? '');
            $start_time = (string)($r['start_time'] ?? '');
            $end_time = (string)($r['end_time'] ?? '');
            $start_label = $start_time !== '' ? date('g:i A', strtotime($start_time)) : '';
            $end_label = $end_time !== '' ? date('g:i A', strtotime($end_time)) : '';
            $when = trim($dayLabel . ' ' . trim($start_label . ($end_label !== '' ? (' - ' . $end_label) : '')));
            $room = trim((string)($r['room_number'] ?? ''));
            $inst = trim(($r['instructor_first'] ?? '') . ' ' . ($r['instructor_last'] ?? ''));
            $sub = $subject;
            $extra = trim($when . ($room !== '' ? (' • Room ' . $room) : '') . ($inst !== '' ? (' • ' . $inst) : ''));
            if ($extra !== '') {
                $sub = ($sub !== '' ? ($sub . ' • ' . $extra) : $extra);
            }

            $status = strtolower(trim((string)($r['enrollment_status'] ?? '')));
            $is_dropped = ($status === 'dropped' || $status === 'rejected');

            $title = 'Class enrollment confirmed';
            $icon = 'bi-check-circle';
            $href = 'my_schedule.php';

            if ($status === 'approved') {
                $title = 'Enrollment approved';
            }
            if ($status === 'pending') {
                $title = 'Enrollment submitted';
                $icon = 'bi-hourglass-split';
            }
            if ($is_dropped) {
                $title = 'Enrollment dropped';
                $icon = 'bi-x-circle';
                $href = 'dashboard.php';
            }

            if ($sub === '' && $is_dropped) {
                $sub = 'A class was removed from your schedule.';
            }

            $notif_items[] = [
                'ts' => $item_ts,
                'icon' => $icon,
                'title' => $title,
                'subtitle' => $sub !== '' ? $sub : 'Class schedule',
                'href' => $href,
            ];
        }
    }

    usort($notif_items, function (array $a, array $b): int {
        $ta = strtotime((string)($a['ts'] ?? '')) ?: 0;
        $tb = strtotime((string)($b['ts'] ?? '')) ?: 0;
        return $tb <=> $ta;
    });

    // Fallback: when schema/events do not provide usable timestamps yet,
    // show indicator until user explicitly marks notifications as seen.
    if ($notif_new_enrollments === 0 && !empty($notif_items) && (string)$notif_cutoff_at === '1970-01-01 00:00:00') {
        $notif_new_enrollments = count($notif_items);
    }

    $notif_unread_total = (int)$notif_new_enrollments;
    $notif_badge_label = $notif_unread_total > 99 ? '99+' : (string)$notif_unread_total;
    $notif_has_new = ($notif_unread_total > 0)
        || (!empty($notif_items) && (string)$notif_cutoff_at === '1970-01-01 00:00:00');
} catch (Throwable $e) {
    $notif_items = [];
    $notif_has_new = false;
    $notif_unread_total = 0;
    $notif_badge_label = '';
}
