<?php
require_once '../config/database.php';
require_once '../includes/session.php';

// Check if user is logged in and has instructor role
require_role('instructor');

require_once __DIR__ . '/notifications_data.php';

function instructor_has_column(PDO $conn, string $table, string $column): bool {
    try {
        $stmt = $conn->query("SHOW COLUMNS FROM `$table` LIKE " . $conn->quote($column));
        return (bool) $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return false;
    }
}

function instructor_svg_sparkline_path(array $values, int $width = 78, int $height = 28, int $padding = 3): string {
    if (count($values) < 2) {
        return '';
    }

    $min = min($values);
    $max = max($values);
    if ($max === $min) {
        $max = $min + 1;
    }

    $dx = ($width - 2 * $padding) / (count($values) - 1);
    $points = [];

    foreach ($values as $i => $value) {
        $x = $padding + ($dx * $i);
        $y = $padding + (($height - 2 * $padding) * (1 - (($value - $min) / ($max - $min))));
        $points[] = [$x, $y];
    }

    $d = 'M ' . number_format($points[0][0], 2, '.', '') . ' ' . number_format($points[0][1], 2, '.', '');
    for ($i = 1; $i < count($points); $i++) {
        $d .= ' L ' . number_format($points[$i][0], 2, '.', '') . ' ' . number_format($points[$i][1], 2, '.', '');
    }

    return $d;
}

function instructor_greeting_label(): string {
    $hour = (int) date('G');
    if ($hour < 12) {
        return 'GOOD MORNING';
    }
    if ($hour < 18) {
        return 'GOOD AFTERNOON';
    }
    return 'GOOD EVENING';
}

function instructor_time_label(?string $time): string {
    if (empty($time)) {
        return '';
    }
    return date('g:i A', strtotime($time));
}

function instructor_course_chip(array $schedule, int $index): string {
    $code = (string) ($schedule['course_code'] ?? '');
    if (preg_match('/\d+/', $code, $matches)) {
        return $matches[0];
    }
    if ($code !== '') {
        return strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $code), 0, 3));
    }
    return (string) (100 + $index);
}

$instructor = null;
$total_classes = 0;
$total_students = 0;
$today_classes = 0;
$today_schedule = [];
$ongoing_classes = 0;
$upcoming_classes = 0;
$semester_label = '2nd Sem 2025-2026';
$department_label = 'College of Computing';
$classes_day_map = [
    'Monday' => 0,
    'Tuesday' => 0,
    'Wednesday' => 0,
    'Thursday' => 0,
    'Friday' => 0,
    'Saturday' => 0,
];
$attendance_labels = ['W1', 'W2', 'W3', 'W4', 'W5', 'W6'];
$attendance_values = [72, 75, 77, 76, 78, 82];
$error_message = null;

$today = date('l');
$now_time = date('H:i:s');
$has_schedule_end_time = instructor_has_column($conn, 'schedules', 'end_time');
$has_schedule_subject_id = instructor_has_column($conn, 'schedules', 'subject_id');

$subjects_table_exists = false;
try {
    $subjects_exists_stmt = $conn->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'subjects'");
    $subjects_exists_stmt->execute();
    $subjects_table_exists = ((int) $subjects_exists_stmt->fetchColumn() > 0);
} catch (Throwable $e) {
    $subjects_table_exists = false;
}

$subjects_enabled = ($subjects_table_exists && $has_schedule_subject_id);
$schedule_code_expr = $subjects_enabled
    ? "COALESCE(subj.subject_code, c.course_code, 'N/A')"
    : "COALESCE(c.course_code, 'N/A')";
$schedule_name_expr = $subjects_enabled
    ? "COALESCE(subj.subject_name, c.course_name, 'Untitled Subject')"
    : "COALESCE(c.course_name, 'Untitled Subject')";
$schedule_subject_join_sql = $subjects_enabled
    ? 'LEFT JOIN subjects subj ON subj.id = s.subject_id'
    : '';

try {
    $stmt = $conn->prepare("SELECT * FROM users WHERE id = ? AND role = 'instructor'");
    $stmt->execute([$_SESSION['user_id']]);
    $instructor = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$instructor) {
        clear_session();
        header('Location: ../auth/login.php?role=instructor');
        exit();
    }

    $stmt = $conn->prepare("SELECT COUNT(*) FROM schedules WHERE instructor_id = ? AND status = 'active'");
    $stmt->execute([$_SESSION['user_id']]);
    $total_classes = (int) $stmt->fetchColumn();

    $stmt = $conn->prepare("\n        SELECT COUNT(DISTINCT e.student_id)\n        FROM enrollments e\n        JOIN schedules s ON e.schedule_id = s.id\n        WHERE s.instructor_id = ? AND e.status = 'approved'\n    ");
    $stmt->execute([$_SESSION['user_id']]);
    $total_students = (int) $stmt->fetchColumn();

    $end_time_select = $has_schedule_end_time
        ? "COALESCE(NULLIF(s.end_time, '00:00:00'), ADDTIME(s.start_time, '02:00:00'))"
        : "ADDTIME(s.start_time, '02:00:00')";
    $stmt = $conn->prepare("\n        SELECT\n            s.id,\n            s.start_time,\n            $end_time_select AS end_time,\n            s.max_students,\n            s.semester,\n            s.academic_year,\n            {$schedule_code_expr} AS course_code,\n            {$schedule_name_expr} AS course_name,\n            cl.room_number,\n            (SELECT COUNT(*) FROM enrollments e WHERE e.schedule_id = s.id AND e.status = 'approved') AS enrolled_students\n        FROM schedules s\n        JOIN courses c ON s.course_id = c.id\n        {$schedule_subject_join_sql}\n        JOIN classrooms cl ON s.classroom_id = cl.id\n        WHERE s.instructor_id = ?\n          AND s.day_of_week = ?\n          AND s.status = 'active'\n        ORDER BY s.start_time ASC\n    ");
    $stmt->execute([$_SESSION['user_id'], $today]);
    $today_schedule = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $today_classes = count($today_schedule);

    foreach ($today_schedule as $schedule) {
        $start = (string) ($schedule['start_time'] ?? '');
        $end = (string) ($schedule['end_time'] ?? '');

        if ($start !== '' && $end !== '' && $now_time >= $start && $now_time <= $end) {
            $ongoing_classes++;
        } elseif ($start !== '' && $now_time < $start) {
            $upcoming_classes++;
        }
    }

    $stmt = $conn->prepare("\n        SELECT semester, academic_year\n        FROM schedules\n        WHERE instructor_id = ? AND status = 'active'\n        ORDER BY created_at DESC\n        LIMIT 1\n    ");
    $stmt->execute([$_SESSION['user_id']]);
    $latest_schedule = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!empty($latest_schedule['semester']) || !empty($latest_schedule['academic_year'])) {
        $semester_label = trim((string) ($latest_schedule['semester'] ?? '') . ' ' . (string) ($latest_schedule['academic_year'] ?? ''));
    }

    $stmt = $conn->prepare("\n        SELECT day_of_week, COUNT(*) AS count\n        FROM schedules\n        WHERE instructor_id = ? AND status = 'active'\n        GROUP BY day_of_week\n    ");
    $stmt->execute([$_SESSION['user_id']]);
    $classes_by_day = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($classes_by_day as $row) {
        $day = (string) ($row['day_of_week'] ?? '');
        if (isset($classes_day_map[$day])) {
            $classes_day_map[$day] = (int) ($row['count'] ?? 0);
        }
    }

    $date_column = 'created_at';
    if (instructor_has_column($conn, 'enrollments', 'enrolled_at')) {
        $date_column = 'enrolled_at';
    } elseif (instructor_has_column($conn, 'enrollments', 'enrollment_date')) {
        $date_column = 'enrollment_date';
    } elseif (instructor_has_column($conn, 'enrollments', 'updated_at')) {
        $date_column = 'updated_at';
    }

    try {
        $stmt = $conn->prepare("\n            SELECT YEARWEEK(e.$date_column, 1) AS yw, COUNT(DISTINCT e.student_id) AS cnt\n            FROM enrollments e\n            JOIN schedules s ON e.schedule_id = s.id\n            WHERE s.instructor_id = ?\n              AND e.status = 'approved'\n            GROUP BY YEARWEEK(e.$date_column, 1)\n            ORDER BY yw DESC\n            LIMIT 6\n        ");
        $stmt->execute([$_SESSION['user_id']]);
        $trend_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (!empty($trend_rows)) {
            $counts = array_reverse(array_map(static function ($row) {
                return (int) ($row['cnt'] ?? 0);
            }, $trend_rows));

            while (count($counts) < 6) {
                array_unshift($counts, 0);
            }

            $min = min($counts);
            $max = max($counts);

            if ($max === $min) {
                $attendance_values = array_fill(0, 6, 78);
            } else {
                $attendance_values = array_map(static function ($count) use ($min, $max) {
                    $ratio = ($count - $min) / ($max - $min);
                    return round(70 + ($ratio * 18), 1);
                }, $counts);
            }

            $attendance_labels = ['W1', 'W2', 'W3', 'W4', 'W5', 'W6'];
        }
    } catch (Throwable $e) {
        error_log('Instructor dashboard trend query warning: ' . $e->getMessage());
    }
} catch (Throwable $e) {
    error_log('Instructor dashboard error: ' . $e->getMessage());
}

$today_schedule_students = [];
if (!empty($today_schedule)) {
    $today_schedule_ids = [];
    foreach ($today_schedule as $schedule_row) {
        $sid = (int) ($schedule_row['id'] ?? 0);
        if ($sid > 0) {
            $today_schedule_ids[$sid] = $sid;
        }
    }

    if (!empty($today_schedule_ids)) {
        $placeholders = implode(',', array_fill(0, count($today_schedule_ids), '?'));
        $student_stmt = $conn->prepare("\n            SELECT e.schedule_id, u.first_name, u.last_name\n            FROM enrollments e\n            JOIN users u ON e.student_id = u.id\n            WHERE e.status = 'approved'\n              AND e.schedule_id IN ($placeholders)\n            ORDER BY u.last_name, u.first_name\n        ");
        $student_stmt->execute(array_values($today_schedule_ids));

        foreach ($student_stmt->fetchAll(PDO::FETCH_ASSOC) as $student_row) {
            $sid = (int) ($student_row['schedule_id'] ?? 0);
            if ($sid <= 0) {
                continue;
            }

            $student_name = trim((string) ($student_row['first_name'] ?? '') . ' ' . (string) ($student_row['last_name'] ?? ''));
            if ($student_name === '') {
                continue;
            }

            if (!isset($today_schedule_students[$sid])) {
                $today_schedule_students[$sid] = [];
            }
            $today_schedule_students[$sid][] = $student_name;
        }
    }
}

$today_schedule_modal_map = [];
foreach ($today_schedule as $schedule_row) {
    $sid = (int) ($schedule_row['id'] ?? 0);
    if ($sid <= 0) {
        continue;
    }

    $start = (string) ($schedule_row['start_time'] ?? '');
    $end = (string) ($schedule_row['end_time'] ?? '');
    $today_schedule_modal_map[$sid] = [
        'course_code' => (string) ($schedule_row['course_code'] ?? ''),
        'course_name' => (string) ($schedule_row['course_name'] ?? ''),
        'day_of_week' => $today,
        'time_range' => trim(instructor_time_label($start) . ' - ' . instructor_time_label($end)),
        'room_number' => (string) ($schedule_row['room_number'] ?? 'TBA'),
        'enrolled_students' => (int) ($schedule_row['enrolled_students'] ?? 0),
        'max_students' => (int) ($schedule_row['max_students'] ?? 0),
        'students' => $today_schedule_students[$sid] ?? [],
    ];
}

$full_name = trim((string) ($instructor['first_name'] ?? '') . ' ' . (string) ($instructor['last_name'] ?? ''));
if ($full_name === '') {
    $full_name = (string) ($_SESSION['full_name'] ?? 'Instructor');
}

$first_initial = strtoupper(substr((string) ($instructor['first_name'] ?? ''), 0, 1));
$last_initial = strtoupper(substr((string) ($instructor['last_name'] ?? ''), 0, 1));
$user_initials = trim($first_initial . $last_initial);
if ($user_initials === '') {
    $user_initials = 'IN';
}

$greeting_label = instructor_greeting_label();

$classes_day_labels = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
$classes_day_values = [
    (int) $classes_day_map['Monday'],
    (int) $classes_day_map['Tuesday'],
    (int) $classes_day_map['Wednesday'],
    (int) $classes_day_map['Thursday'],
    (int) $classes_day_map['Friday'],
    (int) $classes_day_map['Saturday'],
];

$students_week_delta = 0;
if (count($attendance_values) >= 2) {
    $students_week_delta = (int) round($attendance_values[count($attendance_values) - 1] - $attendance_values[count($attendance_values) - 2]);
}
$students_week_delta_label = ($students_week_delta >= 0 ? '+' : '') . $students_week_delta . ' this week';

$avg_attendance = !empty($attendance_values) ? round(array_sum($attendance_values) / count($attendance_values)) : 0;

$classes_spark_path = instructor_svg_sparkline_path(array_map(static function ($value) {
    return max(1, (int) $value);
}, $classes_day_values));

$students_spark_path = instructor_svg_sparkline_path(array_map(static function ($value) {
    return (float) $value;
}, $attendance_values));

$today_spark_source = [
    max(0, $today_classes - 1),
    max(0, $today_classes - 1),
    max(0, $today_classes),
    max(0, $today_classes),
    max(0, $today_classes + ($ongoing_classes > 0 ? 1 : 0)),
];
$today_spark_path = instructor_svg_sparkline_path($today_spark_source);

$nav_items = [
    ['key' => 'dashboard', 'label' => 'Dashboard', 'href' => 'dashboard.php', 'icon' => 'bi-grid'],
    ['key' => 'schedule', 'label' => 'My Schedule', 'href' => 'my_schedule.php', 'icon' => 'bi-calendar3'],
    ['key' => 'classes', 'label' => 'My Classes', 'href' => 'my_classes.php', 'icon' => 'bi-book'],
];

?>
<!doctype html>
<html lang="en">
<head>
    <link rel="icon" type="image/png" href="/PCTClassSchedulingSystem/pctlogo.png">
<meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Instructor Dashboard - PCT</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['Inter', 'ui-sans-serif', 'system-ui', 'sans-serif']
                    }
                }
            }
        };
    </script>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="https://unpkg.com/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>

    <style>
        body {
            background: radial-gradient(circle at 10% 5%, #f7faf9 0%, #f3f6f7 45%, #edf1f4 100%);
        }

        .glass-outline {
            box-shadow: 0 1px 0 rgba(255, 255, 255, 0.7) inset;
        }

        .card-hover {
            transition: transform 0.25s ease, box-shadow 0.25s ease, border-color 0.25s ease;
        }

        .card-hover:hover {
            transform: translateY(-5px);
            box-shadow: 0 16px 32px rgba(15, 23, 42, 0.08);
            border-color: #d4dae2;
        }

        .sidebar-scroll {
            scrollbar-width: thin;
        }

        .sidebar-scroll::-webkit-scrollbar {
            width: 6px;
        }

        .sidebar-scroll::-webkit-scrollbar-thumb {
            background: rgba(255, 255, 255, 0.24);
            border-radius: 999px;
        }

        .sidebar-shell {
            width: 250px;
        }

        .sidebar-compact .sidebar-brand-copy,
        .sidebar-compact .sidebar-nav-title,
        .sidebar-compact .sidebar-label,
        .sidebar-compact .sidebar-active-dot {
            display: none;
        }

        .sidebar-compact .sidebar-brand-row,
        .sidebar-compact .sidebar-logout-link {
            justify-content: center;
            padding-left: 0.5rem;
            padding-right: 0.5rem;
        }

        .sidebar-compact .sidebar-nav-link {
            justify-content: center;
            padding-left: 0.5rem;
            padding-right: 0.5rem;
        }

        .sidebar-compact .sidebar-nav-content {
            justify-content: center;
            gap: 0;
            width: 100%;
        }

        .sidebar-compact .sidebar-nav-link i {
            font-size: 1.38rem;
        }
    </style>
</head>
<body class="font-sans text-slate-900">
    <div class="min-h-screen flex">
        <aside id="sidebar" class="sidebar-shell fixed inset-y-0 left-0 z-40 -translate-x-full lg:translate-x-0 transition-transform duration-300 bg-gradient-to-b from-emerald-950 via-emerald-950 to-emerald-900 text-emerald-50 border-r border-emerald-800/70">
            <div class="sidebar-brand-row h-20 px-4 flex items-center gap-3 border-b border-emerald-800/80">
                <img src="../pctlogo.png" alt="PCT Logo" class="h-11 w-11 rounded-full object-contain bg-emerald-50/10 p-1" />
                <div class="sidebar-brand-copy">
                    <div class="text-sm font-semibold leading-tight">PCT Instructor</div>
                    <div class="text-xs text-emerald-100/75">Instructor's Portal</div>
                </div>
            </div>

            <div class="h-[calc(100vh-136px)] overflow-y-auto sidebar-scroll px-3 py-5">
                <div class="sidebar-nav-title text-[11px] tracking-[0.18em] text-emerald-100/55 px-3 mb-3">NAVIGATION</div>
                <nav class="space-y-1.5">
                    <?php foreach ($nav_items as $item): ?>
                        <?php $is_active = $item['key'] === 'dashboard'; ?>
                        <a href="<?php echo htmlspecialchars($item['href']); ?>" class="sidebar-nav-link group flex items-center justify-between rounded-xl px-3 py-3 <?php echo $is_active ? 'bg-emerald-700/35 text-emerald-50 shadow-[inset_0_0_0_1px_rgba(110,231,183,0.18)]' : 'text-emerald-100/80 hover:text-emerald-50 hover:bg-emerald-800/35'; ?>">
                            <span class="sidebar-nav-content flex items-center gap-3">
                                <i class="bi <?php echo htmlspecialchars($item['icon']); ?> text-sm"></i>
                                <span class="sidebar-label text-sm font-medium"><?php echo htmlspecialchars($item['label']); ?></span>
                            </span>
                            <?php if ($is_active): ?>
                                <span class="sidebar-active-dot h-1.5 w-1.5 rounded-full bg-emerald-300"></span>
                            <?php endif; ?>
                        </a>
                    <?php endforeach; ?>
                </nav>
            </div>

            <div class="h-14 px-3 border-t border-emerald-800/80 flex items-center">
                <a href="../auth/logout.php" class="sidebar-logout-link w-full flex items-center gap-3 px-3 py-2.5 rounded-xl text-rose-200 hover:text-rose-100 hover:bg-rose-500/10">
                    <i class="bi bi-box-arrow-right"></i>
                    <span class="sidebar-label text-sm font-semibold">Logout</span>
                </a>
            </div>
        </aside>

        <div id="sidebarOverlay" class="fixed inset-0 z-30 hidden bg-slate-900/40 lg:hidden"></div>

        <div id="contentWrap" class="flex-1 min-h-screen transition-all duration-300">
            <header class="sticky top-0 z-20 h-16 bg-white/90 backdrop-blur border-b border-slate-200">
                <div class="h-full px-4 sm:px-6 flex items-center justify-between">
                    <div class="flex items-center gap-3">
                        <button id="sidebarBtn" type="button" class="inline-flex h-10 w-10 items-center justify-center rounded-xl border border-slate-200 bg-white text-slate-700 hover:bg-slate-50" aria-label="Toggle sidebar">
                            <i class="bi bi-list text-xl"></i>
                        </button>

                        <div class="hidden sm:flex items-center gap-2 text-sm">
                            <i class="bi bi-layers text-emerald-500"></i>
                            <span class="text-slate-500">Instructor</span>
                            <span class="text-slate-300">/</span>
                            <span class="font-semibold text-slate-800">Dashboard</span>
                        </div>

                        <div class="sm:hidden text-sm font-semibold text-slate-800">Instructor Dashboard</div>
                    </div>

                    <div class="flex items-center gap-3">
                        <div class="relative">
                            <button id="notifBtn" type="button" class="inline-flex h-10 w-10 items-center justify-center rounded-xl border border-slate-200 bg-white hover:bg-slate-50" aria-label="Notifications" aria-haspopup="menu" aria-expanded="false">
                                <span class="relative">
                                    <i class="bi bi-bell text-lg text-slate-700"></i>
                                    <span id="notifDot" class="absolute -right-1 -top-1 min-w-5 h-5 px-1 rounded-full bg-rose-500 text-white text-[10px] font-bold flex items-center justify-center ring-2 ring-white <?php echo (($notif_unread_total ?? 0) > 0) ? '' : 'hidden'; ?>">
                                        <?php echo htmlspecialchars($notif_badge_label ?? ''); ?>
                                    </span>
                                </span>
                            </button>

                            <div id="notifMenu" class="absolute right-0 mt-2 w-80 hidden" role="menu" aria-label="Notifications">
                                <div class="rounded-2xl border border-slate-200 bg-white shadow-lg overflow-hidden">
                                    <div class="px-4 py-3 border-b border-slate-200 flex items-start justify-between gap-2">
                                        <div>
                                            <div class="text-sm font-semibold text-slate-900">Notifications</div>
                                            <div class="text-xs text-slate-500">Class updates and reminders</div>
                                        </div>
                                        <div class="flex items-center gap-3">
                                            <button id="notifMarkRead" type="button" class="text-xs font-semibold text-emerald-600 hover:text-emerald-700">Mark as read</button>
                                            <button id="notifDelete" type="button" class="text-xs font-semibold text-rose-600 hover:text-rose-700">Delete</button>
                                        </div>
                                    </div>

                                    <div class="p-3">
                                        <div class="mb-2 flex justify-end gap-1 <?php echo empty($notif_items) ? 'hidden' : ''; ?>">
                                            <button id="notifScrollUp" type="button" class="inline-flex h-7 w-7 items-center justify-center rounded-lg border border-slate-200 bg-white text-slate-600 hover:bg-slate-50" aria-label="Scroll notifications up">
                                                <i class="bi bi-chevron-up"></i>
                                            </button>
                                            <button id="notifScrollDown" type="button" class="inline-flex h-7 w-7 items-center justify-center rounded-lg border border-slate-200 bg-white text-slate-600 hover:bg-slate-50" aria-label="Scroll notifications down">
                                                <i class="bi bi-chevron-down"></i>
                                            </button>
                                        </div>

                                        <div id="notifList" class="max-h-80 overflow-auto pr-1 space-y-2">
                                            <?php if (empty($notif_items)): ?>
                                                <div class="rounded-xl border border-slate-200 bg-slate-50 p-3 text-xs text-slate-500">No new notifications.</div>
                                            <?php else: ?>
                                                <?php foreach ($notif_items as $item): ?>
                                                    <a href="<?php echo htmlspecialchars($item['href'] ?? '#'); ?>" class="block rounded-xl border border-slate-200 bg-white p-3 hover:bg-slate-50">
                                                        <div class="flex items-start gap-3">
                                                            <div class="inline-flex h-8 w-8 items-center justify-center rounded-lg bg-slate-100 text-slate-600">
                                                                <i class="bi <?php echo htmlspecialchars($item['icon'] ?? 'bi-bell'); ?>"></i>
                                                            </div>
                                                            <div class="min-w-0">
                                                                <div class="text-xs font-semibold text-slate-900 truncate"><?php echo htmlspecialchars($item['title'] ?? 'Notification'); ?></div>
                                                                <div class="text-xs text-slate-500 truncate"><?php echo htmlspecialchars($item['subtitle'] ?? ''); ?></div>
                                                            </div>
                                                        </div>
                                                    </a>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="hidden sm:flex items-center gap-3">
                            <div class="h-10 w-10 rounded-xl bg-emerald-600 text-white flex items-center justify-center text-sm font-bold">
                                <?php echo htmlspecialchars($user_initials); ?>
                            </div>
                            <div>
                                <div class="text-sm font-semibold text-slate-900 leading-tight"><?php echo htmlspecialchars($full_name); ?></div>
                                <div class="text-xs text-slate-500">Instructor</div>
                            </div>
                        </div>
                    </div>
                </div>
            </header>

            <main class="px-4 sm:px-6 py-5 space-y-4">
                <section class="rounded-[24px] border border-emerald-700/35 bg-gradient-to-r from-emerald-950 via-emerald-900 to-emerald-700 text-white p-5 sm:p-6 glass-outline">
                    <div class="flex flex-col lg:flex-row lg:items-start lg:justify-between gap-5">
                        <div class="min-w-0">
                            <div class="flex items-start gap-4">
                                <div class="hidden sm:flex h-12 w-12 rounded-2xl bg-white/12 border border-white/20 items-center justify-center text-xl font-semibold text-emerald-100">
                                    <?php echo htmlspecialchars($user_initials); ?>
                                </div>
                                <div class="min-w-0">
                                    <div class="text-xs font-bold tracking-[0.14em] text-emerald-200"><?php echo htmlspecialchars($greeting_label); ?></div>
                                    <h1 class="mt-1 text-2xl md:text-[38px] font-semibold leading-tight truncate">Welcome back, <?php echo htmlspecialchars($full_name); ?>!</h1>
                                    <p class="mt-1 text-emerald-100/90">Here's your teaching schedule and class overview for today.</p>
                                </div>
                            </div>

                            <div class="mt-5 grid grid-cols-1 sm:grid-cols-3 gap-3 text-sm text-emerald-100/90">
                                <div>Semester: <span class="text-white font-medium"><?php echo htmlspecialchars($semester_label); ?></span></div>
                                <div>Department: <span class="text-white font-medium">Information Technology Education</span></div>
                            </div>
                        </div>

                        <div class="flex flex-wrap gap-2 lg:justify-end">
                            <a href="my_schedule.php" class="inline-flex items-center gap-2 rounded-xl bg-white/10 border border-white/15 px-4 py-2.5 text-sm font-semibold text-white hover:bg-white/20">
                                <i class="bi bi-calendar3"></i>
                                <span>View Full Schedule</span>
                            </a>
                            <a href="my_classes.php" class="inline-flex items-center gap-2 rounded-xl bg-white/10 border border-white/15 px-4 py-2.5 text-sm font-semibold text-white hover:bg-white/20">
                                <i class="bi bi-book"></i>
                                <span>My Classes</span>
                            </a>
                        </div>
                    </div>
                </section>

                <section class="grid grid-cols-1 lg:grid-cols-3 gap-4">
                    <article class="card-hover rounded-3xl border border-slate-200 bg-white p-5">
                        <div class="flex items-start justify-between">
                            <div class="inline-flex h-12 w-12 items-center justify-center rounded-2xl bg-emerald-50 text-emerald-600 text-xl">
                                <i class="bi bi-book"></i>
                            </div>
                            <span class="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-3 py-1 text-xs font-semibold text-emerald-600">
                                <i class="bi bi-arrow-up-right"></i>
                                Stable
                            </span>
                        </div>
                        <div class="mt-4 text-[42px] leading-none font-semibold text-slate-800"><?php echo (int) $total_classes; ?></div>
                        <div class="mt-1 text-base text-slate-600">Total Classes</div>
                        <div class="text-sm text-slate-400">Active this semester</div>
                        <div class="mt-3 flex justify-end">
                            <svg width="78" height="28" viewBox="0 0 78 28" preserveAspectRatio="none">
                                <path d="<?php echo htmlspecialchars($classes_spark_path); ?>" fill="none" stroke="#10b981" stroke-width="2.5" />
                            </svg>
                        </div>
                    </article>

                    <article class="card-hover rounded-3xl border border-slate-200 bg-white p-5">
                        <div class="flex items-start justify-between">
                            <div class="inline-flex h-12 w-12 items-center justify-center rounded-2xl bg-indigo-50 text-indigo-500 text-xl">
                                <i class="bi bi-people"></i>
                            </div>
                            <span class="inline-flex items-center gap-1 rounded-full bg-indigo-50 px-3 py-1 text-xs font-semibold text-indigo-500">
                                <i class="bi bi-arrow-up-right"></i>
                                <?php echo htmlspecialchars($students_week_delta_label); ?>
                            </span>
                        </div>
                        <div class="mt-4 text-[42px] leading-none font-semibold text-slate-800"><?php echo (int) $total_students; ?></div>
                        <div class="mt-1 text-base text-slate-600">Total Students</div>
                        <div class="text-sm text-slate-400">Across all classes</div>
                        <div class="mt-3 flex justify-end">
                            <svg width="78" height="28" viewBox="0 0 78 28" preserveAspectRatio="none">
                                <path d="<?php echo htmlspecialchars($students_spark_path); ?>" fill="none" stroke="#6366f1" stroke-width="2.5" />
                            </svg>
                        </div>
                    </article>

                    <article class="card-hover rounded-3xl border border-slate-200 bg-white p-5">
                        <div class="flex items-start justify-between">
                            <div class="inline-flex h-12 w-12 items-center justify-center rounded-2xl bg-amber-50 text-amber-500 text-xl">
                                <i class="bi bi-calendar2-week"></i>
                            </div>
                            <span class="inline-flex items-center gap-1 rounded-full bg-amber-50 px-3 py-1 text-xs font-semibold text-amber-600">
                                <i class="bi bi-arrow-up-right"></i>
                                On track
                            </span>
                        </div>
                        <div class="mt-4 text-[42px] leading-none font-semibold text-slate-800"><?php echo (int) $today_classes; ?></div>
                        <div class="mt-1 text-base text-slate-600">Today's Classes</div>
                        <div class="text-sm text-slate-400"><?php echo (int) $ongoing_classes; ?> ongoing - <?php echo (int) $upcoming_classes; ?> upcoming</div>
                        <div class="mt-3 flex justify-end">
                            <svg width="78" height="28" viewBox="0 0 78 28" preserveAspectRatio="none">
                                <path d="<?php echo htmlspecialchars($today_spark_path); ?>" fill="none" stroke="#f59e0b" stroke-width="2.5" />
                            </svg>
                        </div>
                    </article>
                </section>

                <section class="grid grid-cols-1 xl:grid-cols-3 gap-4">
                    <article class="xl:col-span-2 rounded-3xl border border-slate-200 bg-white p-5">
                        <div class="flex items-center justify-between gap-3">
                            <div>
                                <h2 class="text-2xl font-semibold text-slate-700">Today's Schedule</h2>
                                <p class="text-sm text-slate-400"><?php echo htmlspecialchars(date('l, F j, Y')); ?></p>
                            </div>

                            <div class="flex items-center gap-3">
                                <span class="inline-flex items-center rounded-full bg-emerald-50 px-3 py-1 text-xs font-semibold text-emerald-600"><?php echo (int) $today_classes; ?> Classes</span>
                                <a href="my_schedule.php" class="text-sm font-medium text-slate-500 hover:text-emerald-600">View all <i class="bi bi-chevron-right"></i></a>
                            </div>
                        </div>

                        <div class="mt-4">
                            <?php if (empty($today_schedule)): ?>
                                <div class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-5 text-sm text-slate-600">No classes scheduled for today.</div>
                            <?php else: ?>
                                <div class="space-y-3">
                                    <?php foreach ($today_schedule as $index => $schedule): ?>
                                        <?php
                                            $start = (string) ($schedule['start_time'] ?? '');
                                            $end = (string) ($schedule['end_time'] ?? '');
                                            $is_ongoing = ($start !== '' && $end !== '' && $now_time >= $start && $now_time <= $end);
                                            $status_label = $is_ongoing ? 'Ongoing' : 'Upcoming';
                                            $status_class = $is_ongoing
                                                ? 'bg-emerald-50 text-emerald-600 border-emerald-200'
                                                : 'bg-slate-50 text-slate-500 border-slate-200';
                                            $chip_palette = ['bg-emerald-100 text-emerald-700', 'bg-indigo-100 text-indigo-700', 'bg-amber-100 text-amber-700', 'bg-rose-100 text-rose-700'];
                                            $chip_color = $chip_palette[$index % count($chip_palette)];
                                        ?>
                                        <button type="button" class="js-open-schedule-modal w-full rounded-2xl border border-slate-200 px-4 py-3 hover:bg-slate-50/70 transition text-left" data-schedule-id="<?php echo (int) ($schedule['id'] ?? 0); ?>">
                                            <div class="grid grid-cols-[78px,1fr,auto] items-center gap-3">
                                                <div class="text-sm font-semibold text-slate-500"><?php echo htmlspecialchars(instructor_time_label($start)); ?></div>

                                                <div class="min-w-0">
                                                    <div class="flex items-center gap-2">
                                                        <span class="h-2.5 w-2.5 rounded-full <?php echo $is_ongoing ? 'bg-emerald-500' : 'bg-slate-300'; ?>"></span>
                                                        <span class="inline-flex items-center rounded-xl px-2 py-1 text-xs font-semibold <?php echo $chip_color; ?>"><?php echo htmlspecialchars(instructor_course_chip($schedule, $index + 1)); ?></span>
                                                        <span class="truncate text-lg font-medium text-slate-700"><?php echo htmlspecialchars((string) ($schedule['course_name'] ?? 'Untitled Class')); ?></span>
                                                    </div>
                                                    <div class="mt-1 text-sm text-slate-400 flex items-center gap-4">
                                                        <span><i class="bi bi-geo-alt"></i> <?php echo htmlspecialchars((string) ($schedule['room_number'] ?? 'TBA')); ?></span>
                                                        <span><i class="bi bi-people"></i> <?php echo (int) ($schedule['enrolled_students'] ?? 0); ?> students</span>
                                                    </div>
                                                </div>

                                                <span class="inline-flex items-center rounded-full border px-3 py-1 text-xs font-semibold <?php echo $status_class; ?>"><?php echo $status_label; ?></span>
                                            </div>
                                        </button>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </article>

                    <div class="space-y-4">
                        <article class="rounded-3xl border border-slate-200 bg-white p-5">
                            <div class="flex items-center justify-between">
                                <div>
                                    <h3 class="text-[28px] font-semibold leading-tight text-slate-700">Classes per Day</h3>
                                    <p class="text-sm text-slate-400">This week</p>
                                </div>
                                <i class="bi bi-bar-chart text-slate-300 text-xl"></i>
                            </div>
                            <div class="mt-4 h-44">
                                <canvas id="classesChart"></canvas>
                            </div>
                        </article>
                    </div>
                </section>
            </main>
        </div>
    </div>

    <div id="scheduleDetailModal" class="fixed inset-0 z-50 hidden" aria-hidden="true">
        <div class="absolute inset-0 bg-slate-900/50" data-modal-close></div>
        <div class="absolute inset-0 p-4 flex items-center justify-center">
            <div class="w-full max-w-3xl rounded-3xl border border-slate-200 bg-white shadow-2xl overflow-hidden">
                <div class="px-5 py-4 border-b border-slate-200 flex items-center justify-between">
                    <div>
                        <div class="text-xs font-semibold tracking-[0.18em] text-emerald-600">CLASS DETAILS</div>
                        <h3 id="scheduleModalCourseName" class="mt-1 text-2xl font-semibold text-slate-900">Class</h3>
                        <p id="scheduleModalCourseCode" class="text-sm text-slate-500"></p>
                    </div>
                    <button id="scheduleModalCloseBtn" type="button" class="inline-flex h-9 w-9 items-center justify-center rounded-xl border border-slate-200 text-slate-600 hover:bg-slate-50" aria-label="Close class details">
                        <i class="bi bi-x-lg"></i>
                    </button>
                </div>

                <div class="px-5 py-5 grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3">
                        <div class="text-xs font-semibold tracking-wide text-slate-500">DAY</div>
                        <div id="scheduleModalDay" class="mt-1 text-base font-semibold text-slate-800">-</div>
                    </div>
                    <div class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3">
                        <div class="text-xs font-semibold tracking-wide text-slate-500">TIME</div>
                        <div id="scheduleModalTime" class="mt-1 text-base font-semibold text-slate-800">-</div>
                    </div>
                    <div class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3">
                        <div class="text-xs font-semibold tracking-wide text-slate-500">ROOM</div>
                        <div id="scheduleModalRoom" class="mt-1 text-base font-semibold text-slate-800">-</div>
                    </div>
                    <div class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3">
                        <div class="text-xs font-semibold tracking-wide text-slate-500">ENROLLMENT</div>
                        <div id="scheduleModalEnrollment" class="mt-1 text-base font-semibold text-slate-800">-</div>
                    </div>
                    <div class="sm:col-span-2 rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3">
                        <div class="text-xs font-semibold tracking-wide text-slate-500">ENROLLED STUDENTS</div>
                        <ul id="scheduleModalStudentsList" class="mt-2 space-y-1 text-sm text-slate-700 max-h-44 overflow-auto pr-1">
                            <li class="text-slate-500">No students enrolled yet.</li>
                        </ul>
                    </div>
                </div>

                <div class="px-5 pb-5 flex justify-end">
                    <button type="button" class="inline-flex items-center rounded-xl bg-emerald-600 text-white px-4 py-2 text-sm font-semibold hover:bg-emerald-700" data-modal-close>Close</button>
                </div>
            </div>
        </div>
    </div>

    <script>
        (function () {
            const sidebar = document.getElementById('sidebar');
            const sidebarBtn = document.getElementById('sidebarBtn');
            const overlay = document.getElementById('sidebarOverlay');
            const contentWrap = document.getElementById('contentWrap');
            let desktopExpanded = true;

            function isDesktop() {
                return window.innerWidth >= 1024;
            }

            function setSidebarOpen(open) {
                if (isDesktop()) {
                    sidebar.classList.remove('-translate-x-full');
                    sidebar.classList.remove('lg:-translate-x-full');
                    sidebar.classList.toggle('sidebar-compact', !open);
                    const desktopWidth = open ? 250 : 86;
                    sidebar.style.width = desktopWidth + 'px';

                    if (contentWrap) {
                        contentWrap.style.marginLeft = desktopWidth + 'px';
                    }
                } else {
                    sidebar.classList.remove('sidebar-compact');
                    sidebar.classList.remove('lg:-translate-x-full');
                    sidebar.classList.toggle('-translate-x-full', !open);
                    sidebar.style.width = '250px';

                    if (contentWrap) {
                        contentWrap.style.marginLeft = '0px';
                    }
                }

                if (overlay) {
                    overlay.classList.toggle('hidden', !open || isDesktop());
                }
            }

            function applyLayoutState() {
                if (isDesktop()) {
                    setSidebarOpen(desktopExpanded);
                    return;
                }
                setSidebarOpen(false);
            }

            function isSidebarOpen() {
                return isDesktop()
                    ? !sidebar.classList.contains('sidebar-compact')
                    : !sidebar.classList.contains('-translate-x-full');
            }

            if (sidebarBtn) {
                sidebarBtn.addEventListener('click', function () {
                    const currentlyOpen = isSidebarOpen();

                    if (isDesktop()) {
                        desktopExpanded = !currentlyOpen;
                        setSidebarOpen(desktopExpanded);
                        return;
                    }

                    setSidebarOpen(!currentlyOpen);
                });
            }

            if (overlay) {
                overlay.addEventListener('click', function () {
                    setSidebarOpen(false);
                });
            }

            window.addEventListener('resize', applyLayoutState);
            applyLayoutState();
        })();

        (function () {
            const btn = document.getElementById('notifBtn');
            const menu = document.getElementById('notifMenu');
            const dot = document.getElementById('notifDot');
            const markRead = document.getElementById('notifMarkRead');
            const delBtn = document.getElementById('notifDelete');
            const notifList = document.getElementById('notifList');
            const scrollUpBtn = document.getElementById('notifScrollUp');
            const scrollDownBtn = document.getElementById('notifScrollDown');
            const scrollStep = 96;

            if (!btn || !menu) {
                return;
            }

            function isOpen() {
                return !menu.classList.contains('hidden');
            }

            function closeMenu() {
                menu.classList.add('hidden');
                btn.setAttribute('aria-expanded', 'false');
            }

            function toggleMenu() {
                if (!isOpen()) {
                    menu.classList.remove('hidden');
                    btn.setAttribute('aria-expanded', 'true');
                    window.requestAnimationFrame(updateScrollButtons);
                } else {
                    closeMenu();
                }
            }

            btn.addEventListener('click', function (event) {
                event.preventDefault();
                event.stopPropagation();
                toggleMenu();
            });

            menu.addEventListener('click', function (event) {
                event.stopPropagation();
            });

            document.addEventListener('click', function (event) {
                if (!isOpen()) return;
                const target = event.target;
                if (!(target instanceof Element)) return;
                if (menu.contains(target) || btn.contains(target)) return;
                closeMenu();
            });

            async function postAction(action) {
                try {
                    const response = await fetch('notifications_seen.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: 'action=' + encodeURIComponent(action)
                    });
                    return response.ok;
                } catch (_) {
                    return false;
                }
            }

            function updateScrollButtons() {
                if (!notifList || !scrollUpBtn || !scrollDownBtn) return;
                const maxScroll = Math.max(0, notifList.scrollHeight - notifList.clientHeight);
                const atTop = notifList.scrollTop <= 1;
                const atBottom = notifList.scrollTop >= (maxScroll - 1);

                scrollUpBtn.disabled = atTop;
                scrollDownBtn.disabled = atBottom;

                scrollUpBtn.classList.toggle('opacity-40', atTop);
                scrollDownBtn.classList.toggle('opacity-40', atBottom);
            }

            if (markRead) {
                markRead.addEventListener('click', async function (event) {
                    event.preventDefault();
                    event.stopPropagation();
                    const ok = await postAction('seen');
                    if (ok) {
                        dot?.classList.add('hidden');
                        closeMenu();
                        window.location.reload();
                    }
                });
            }

            if (delBtn) {
                delBtn.addEventListener('click', async function (event) {
                    event.preventDefault();
                    event.stopPropagation();
                    const ok = await postAction('delete');
                    if (ok) {
                        dot?.classList.add('hidden');
                        closeMenu();
                        window.location.reload();
                    }
                });
            }

            scrollUpBtn?.addEventListener('click', function (event) {
                event.preventDefault();
                event.stopPropagation();
                notifList?.scrollBy({ top: -scrollStep, behavior: 'smooth' });
            });

            scrollDownBtn?.addEventListener('click', function (event) {
                event.preventDefault();
                event.stopPropagation();
                notifList?.scrollBy({ top: scrollStep, behavior: 'smooth' });
            });

            notifList?.addEventListener('scroll', updateScrollButtons);
            updateScrollButtons();
        })();

        (function () {
            const classesCtx = document.getElementById('classesChart');
            const classesLabels = <?php echo json_encode($classes_day_labels); ?>;
            const classesValues = <?php echo json_encode($classes_day_values, JSON_NUMERIC_CHECK); ?>;

            if (classesCtx && window.Chart) {
                new Chart(classesCtx, {
                    type: 'bar',
                    data: {
                        labels: classesLabels,
                        datasets: [{
                            data: classesValues,
                            backgroundColor: 'rgba(16, 185, 129, 0.38)',
                            borderColor: 'rgba(16, 185, 129, 0.95)',
                            borderWidth: 1.4,
                            borderRadius: 8,
                            maxBarThickness: 24
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: { display: false },
                            tooltip: {
                                callbacks: {
                                    label: function (context) {
                                        const count = Number(context.parsed.y || 0);
                                        return count + (count === 1 ? ' class' : ' classes');
                                    }
                                }
                            }
                        },
                        scales: {
                            x: {
                                grid: { display: false },
                                ticks: { color: '#94a3b8', font: { size: 11 } }
                            },
                            y: {
                                beginAtZero: true,
                                grid: { color: 'rgba(148, 163, 184, 0.16)' },
                                ticks: { color: '#94a3b8', stepSize: 1 }
                            }
                        }
                    }
                });
            }
        })();

        (function () {
            const modal = document.getElementById('scheduleDetailModal');
            const closeBtn = document.getElementById('scheduleModalCloseBtn');
            const closeTargets = Array.from(document.querySelectorAll('[data-modal-close]'));
            const triggers = Array.from(document.querySelectorAll('.js-open-schedule-modal'));

            const courseNameEl = document.getElementById('scheduleModalCourseName');
            const courseCodeEl = document.getElementById('scheduleModalCourseCode');
            const dayEl = document.getElementById('scheduleModalDay');
            const timeEl = document.getElementById('scheduleModalTime');
            const roomEl = document.getElementById('scheduleModalRoom');
            const enrollmentEl = document.getElementById('scheduleModalEnrollment');
            const studentsListEl = document.getElementById('scheduleModalStudentsList');

            const scheduleDetails = <?php echo json_encode($today_schedule_modal_map, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

            if (!modal) {
                return;
            }

            function escapeHtml(value) {
                return String(value == null ? '' : value)
                    .replace(/&/g, '&amp;')
                    .replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;')
                    .replace(/\"/g, '&quot;')
                    .replace(/'/g, '&#39;');
            }

            function closeModal() {
                modal.classList.add('hidden');
                modal.setAttribute('aria-hidden', 'true');
                document.body.classList.remove('overflow-hidden');
            }

            function openModal(scheduleId) {
                const key = String(scheduleId || '');
                if (key === '' || !scheduleDetails[key]) {
                    return;
                }

                const detail = scheduleDetails[key];
                const maxStudents = Number(detail.max_students || 0);
                const enrolled = Number(detail.enrolled_students || 0);
                const students = Array.isArray(detail.students) ? detail.students : [];

                courseNameEl.textContent = detail.course_name || 'Class details';
                courseCodeEl.textContent = detail.course_code || 'No subject code';
                dayEl.textContent = detail.day_of_week || '-';
                timeEl.textContent = detail.time_range || '-';
                roomEl.textContent = detail.room_number || 'TBA';
                enrollmentEl.textContent = maxStudents > 0
                    ? (enrolled + ' / ' + maxStudents + ' students')
                    : (enrolled + ' students');

                if (studentsListEl) {
                    if (students.length === 0) {
                        studentsListEl.innerHTML = '<li class="text-slate-500">No students enrolled yet.</li>';
                    } else {
                        studentsListEl.innerHTML = students.map(function (name) {
                            return '<li class="rounded-lg bg-white border border-slate-200 px-2.5 py-1.5">' + escapeHtml(name) + '</li>';
                        }).join('');
                    }
                }

                modal.classList.remove('hidden');
                modal.setAttribute('aria-hidden', 'false');
                document.body.classList.add('overflow-hidden');
            }

            triggers.forEach(function (trigger) {
                trigger.addEventListener('click', function () {
                    openModal(trigger.getAttribute('data-schedule-id'));
                });
            });

            if (closeBtn) {
                closeBtn.addEventListener('click', closeModal);
            }

            closeTargets.forEach(function (target) {
                target.addEventListener('click', closeModal);
            });

            document.addEventListener('keydown', function (event) {
                if (event.key === 'Escape' && !modal.classList.contains('hidden')) {
                    closeModal();
                }
            });
        })();
    </script>
</body>
</html>
