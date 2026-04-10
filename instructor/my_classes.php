<?php
require_once '../config/database.php';
require_once '../includes/session.php';

require_role('instructor');
require_once __DIR__ . '/notifications_data.php';

function ins_cls_course_chip(string $courseCode): string {
    if (preg_match('/\d+/', $courseCode, $matches)) {
        return $matches[0];
    }
    $plain = preg_replace('/[^A-Za-z0-9]/', '', $courseCode);
    return strtoupper(substr((string) $plain, 0, 3));
}

function ins_cls_day_abbrev(string $day): string {
    $map = [
        'Monday' => 'M',
        'Tuesday' => 'T',
        'Wednesday' => 'W',
        'Thursday' => 'Th',
        'Friday' => 'F',
        'Saturday' => 'Sat',
    ];
    return $map[$day] ?? substr($day, 0, 1);
}

function ins_cls_time_label(string $time): string {
    $ts = strtotime($time);
    if ($ts === false) {
        return $time;
    }
    return date('g:i A', $ts);
}

function ins_cls_sort_days(array &$days, array $dayOrder): void {
    usort($days, function ($a, $b) use ($dayOrder) {
        return ($dayOrder[$a] ?? 99) <=> ($dayOrder[$b] ?? 99);
    });
}

$instructor_id = (int) ($_SESSION['user_id'] ?? 0);
$stmt = $conn->prepare('SELECT first_name, last_name FROM users WHERE id = ? LIMIT 1');
$stmt->execute([$instructor_id]);
$instructor = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

$full_name = trim((string) ($instructor['first_name'] ?? ($_SESSION['first_name'] ?? '')) . ' ' . (string) ($instructor['last_name'] ?? ($_SESSION['last_name'] ?? '')));
if ($full_name === '') {
    $full_name = 'Instructor';
}
$user_initials = strtoupper(substr((string) ($instructor['first_name'] ?? 'I'), 0, 1) . substr((string) ($instructor['last_name'] ?? 'N'), 0, 1));

$schedule_cols_stmt = $conn->prepare('DESCRIBE schedules');
$schedule_cols_stmt->execute();
$schedule_cols = [];
foreach ($schedule_cols_stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $schedule_cols[$row['Field']] = true;
}

$has_end_time = isset($schedule_cols['end_time']);
$has_duration_minutes = isset($schedule_cols['duration_minutes']);

$end_expr = $has_end_time
    ? "TIME_FORMAT(COALESCE(s.end_time, ADDTIME(s.start_time, SEC_TO_TIME(120 * 60))), '%H:%i:%s')"
    : ($has_duration_minutes
        ? "TIME_FORMAT(ADDTIME(s.start_time, SEC_TO_TIME(COALESCE(s.duration_minutes, 120) * 60)), '%H:%i:%s')"
        : "TIME_FORMAT(ADDTIME(s.start_time, SEC_TO_TIME(120 * 60)), '%H:%i:%s')");

$subjects_table_exists = false;
try {
    $subjects_exists_stmt = $conn->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'subjects'");
    $subjects_exists_stmt->execute();
    $subjects_table_exists = ((int) $subjects_exists_stmt->fetchColumn() > 0);
} catch (Throwable $e) {
    $subjects_table_exists = false;
}

$subjects_enabled = ($subjects_table_exists && isset($schedule_cols['subject_id']));
$subject_join_sql = $subjects_enabled
    ? 'LEFT JOIN subjects subj ON subj.id = s.subject_id'
    : '';
$course_code_expr = $subjects_enabled
    ? "COALESCE(subj.subject_code, c.course_code, 'N/A')"
    : "COALESCE(c.course_code, 'N/A')";
$course_name_expr = $subjects_enabled
    ? "COALESCE(subj.subject_name, c.course_name, 'Untitled Subject')"
    : "COALESCE(c.course_name, 'Untitled Subject')";

$link_subject_expr = $subjects_enabled
    ? 'COALESCE(s.subject_id, 0)'
    : '0';
$link_semester_expr = isset($schedule_cols['semester'])
    ? "COALESCE(s.semester, '')"
    : "''";
$link_academic_year_expr = isset($schedule_cols['academic_year'])
    ? "COALESCE(s.academic_year, '')"
    : "''";
$link_year_level_expr = isset($schedule_cols['year_level'])
    ? "COALESCE(s.year_level, '')"
    : "''";

$stmt = $conn->prepare("\n    SELECT\n        s.id,\n        s.course_id,\n        {$link_subject_expr} AS link_subject_id,\n        {$link_semester_expr} AS link_semester,\n        {$link_academic_year_expr} AS link_academic_year,\n        {$link_year_level_expr} AS link_year_level,\n        s.classroom_id,\n        s.day_of_week,\n        s.start_time,\n        {$end_expr} AS end_time,\n        s.max_students,\n        {$course_code_expr} AS course_code,\n        {$course_name_expr} AS course_name,\n        cl.room_number,\n        COUNT(DISTINCT CASE WHEN LOWER(COALESCE(e.status, '')) IN ('approved', 'enrolled', 'active') THEN e.student_id END) AS enrolled_students\n    FROM schedules s\n    JOIN courses c ON s.course_id = c.id\n    {$subject_join_sql}\n    JOIN classrooms cl ON s.classroom_id = cl.id\n    LEFT JOIN enrollments e ON s.id = e.schedule_id\n    WHERE s.instructor_id = :instructor_id\n      AND s.status = 'active'\n    GROUP BY s.id\n    ORDER BY course_code, FIELD(s.day_of_week, 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'), s.start_time\n");
$stmt->execute(['instructor_id' => $instructor_id]);
$schedules = $stmt->fetchAll(PDO::FETCH_ASSOC);

$day_order = ['Monday' => 1, 'Tuesday' => 2, 'Wednesday' => 3, 'Thursday' => 4, 'Friday' => 5, 'Saturday' => 6];
$classes = [];
$schedule_ids = [];
$schedule_group_key_map = [];
foreach ($schedules as $row) {
    $schedule_id = (int) ($row['id'] ?? 0);
    if ($schedule_id <= 0) {
        continue;
    }

    $key_parts = [
        (string) ($row['course_id'] ?? 0),
        (string) ($row['link_subject_id'] ?? 0),
        (string) ($row['classroom_id'] ?? 0),
        (string) ($row['start_time'] ?? ''),
        (string) ($row['end_time'] ?? ''),
        (string) ($row['max_students'] ?? 0),
        (string) ($row['link_semester'] ?? ''),
        (string) ($row['link_academic_year'] ?? ''),
        (string) ($row['link_year_level'] ?? ''),
    ];
    $key = implode('|', $key_parts);

    $schedule_ids[$schedule_id] = $schedule_id;
    $schedule_group_key_map[$schedule_id] = $key;

    if (!isset($classes[$key])) {
        $classes[$key] = [
            'schedule_id' => $schedule_id,
            'course_code' => (string) ($row['course_code'] ?? ''),
            'course_name' => (string) ($row['course_name'] ?? ''),
            'room_number' => (string) ($row['room_number'] ?? ''),
            'start_time' => (string) ($row['start_time'] ?? ''),
            'end_time' => (string) ($row['end_time'] ?? ''),
            'max_students' => (int) ($row['max_students'] ?? 0),
            'enrolled_students' => 0,
            'days' => [],
            'student_ids' => [],
            'schedule_ids' => [],
            'schedule_rows' => [],
        ];
    }

    $classes[$key]['schedule_ids'][$schedule_id] = $schedule_id;

    $classes[$key]['enrolled_students'] += (int) ($row['enrolled_students'] ?? 0);

    $day = (string) ($row['day_of_week'] ?? '');
    if ($day !== '' && !in_array($day, $classes[$key]['days'], true)) {
        $classes[$key]['days'][] = $day;
    }

    $classes[$key]['schedule_rows'][] = [
        'id' => $schedule_id,
        'day' => $day,
        'start_time' => (string) ($row['start_time'] ?? ''),
        'end_time' => (string) ($row['end_time'] ?? ''),
    ];
}

if (!empty($schedule_ids)) {
    $students_by_schedule = [];
    $placeholders = implode(',', array_fill(0, count($schedule_ids), '?'));
    $enrollment_stmt = $conn->prepare("\n        SELECT
            e.schedule_id,
            e.student_id,
            u.first_name,
            u.last_name
        FROM enrollments e
        LEFT JOIN users u ON u.id = e.student_id
        WHERE e.schedule_id IN ($placeholders)
          AND LOWER(COALESCE(e.status, '')) IN ('approved', 'enrolled', 'active')
    ");
    $enrollment_stmt->execute(array_values($schedule_ids));

    foreach ($enrollment_stmt->fetchAll(PDO::FETCH_ASSOC) as $student_row) {
        $sid = (int) ($student_row['schedule_id'] ?? 0);
        $student_id = (int) ($student_row['student_id'] ?? 0);
        if ($sid <= 0 || $student_id <= 0) {
            continue;
        }

        $group_key = $schedule_group_key_map[$sid] ?? '';
        if ($group_key === '' || !isset($classes[$group_key])) {
            continue;
        }

        if (!isset($students_by_schedule[$sid])) {
            $students_by_schedule[$sid] = [];
        }

        $student_name = trim((string) ($student_row['first_name'] ?? '') . ' ' . (string) ($student_row['last_name'] ?? ''));
        if ($student_name === '') {
            $student_name = 'Student #' . $student_id;
        }

        $students_by_schedule[$sid][$student_id] = $student_name;
        $classes[$group_key]['student_ids'][$student_id] = $student_id;
    }
}

$students_by_schedule_modal = [];
foreach ($students_by_schedule ?? [] as $sid => $student_map) {
    asort($student_map, SORT_NATURAL | SORT_FLAG_CASE);
    $students_by_schedule_modal[(string) $sid] = array_values($student_map);
}

foreach ($classes as &$class) {
    ins_cls_sort_days($class['days'], $day_order);
    usort($class['schedule_rows'], function ($a, $b) use ($day_order) {
        $cmp = ($day_order[(string) ($a['day'] ?? '')] ?? 99) <=> ($day_order[(string) ($b['day'] ?? '')] ?? 99);
        if ($cmp !== 0) {
            return $cmp;
        }
        return strcmp((string) ($a['start_time'] ?? ''), (string) ($b['start_time'] ?? ''));
    });

    $abbr = array_map('ins_cls_day_abbrev', $class['days']);
    $class['days_label'] = implode('', $abbr);
    if ($class['days_label'] === '') {
        $class['days_label'] = '-';
    }

    $class['enrolled_students'] = !empty($class['student_ids'])
        ? count($class['student_ids'])
        : (int) ($class['enrolled_students'] ?? 0);

    $class['meeting_count'] = count($class['schedule_rows']);
    $class['available_slots'] = max(0, ((int) $class['max_students']) - ((int) $class['enrolled_students']));

    if ((string) ($class['end_time'] ?? '') === '') {
        $class['end_time'] = date('H:i:s', strtotime((string) $class['start_time'] . ' +2 hours'));
    }

    if ($class['max_students'] > 0) {
        $class['progress'] = (int) round(min(100, (($class['enrolled_students'] / $class['max_students']) * 100)));
    } else {
        $class['progress'] = 0;
    }
}
unset($class);

$class_list = array_values($classes);
usort($class_list, function ($a, $b) {
    $cmp = strcmp((string) ($a['course_code'] ?? ''), (string) ($b['course_code'] ?? ''));
    if ($cmp !== 0) {
        return $cmp;
    }
    return strcmp((string) ($a['start_time'] ?? ''), (string) ($b['start_time'] ?? ''));
});

$total_classes = count($class_list);
$total_students = array_sum(array_map(static function ($row) {
    return (int) ($row['enrolled_students'] ?? 0);
}, $class_list));
$avg_class_size = $total_classes > 0 ? (int) round($total_students / $total_classes) : 0;

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
    <title>My Classes - PCT</title>

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

    <style>
        body {
            background: radial-gradient(circle at 10% 5%, #f7faf9 0%, #f3f6f7 45%, #edf1f4 100%);
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

        .summary-card {
            transition: transform 0.22s ease, box-shadow 0.22s ease, border-color 0.22s ease;
        }

        .summary-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 14px 28px rgba(15, 23, 42, 0.08);
            border-color: #d7dde6;
        }

        .class-toggle-icon {
            transition: transform 0.2s ease;
        }

        .class-detail {
            display: none;
        }

        .class-card.open .class-detail {
            display: block;
        }

        .class-card.open .class-toggle-icon {
            transform: rotate(180deg);
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
                        <?php $is_active = $item['key'] === 'classes'; ?>
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
                            <span class="font-semibold text-slate-800">My Classes</span>
                        </div>
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
                <section class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <article class="summary-card rounded-3xl border border-slate-200 bg-white p-5">
                        <div class="flex items-center gap-3">
                            <div class="h-11 w-11 rounded-2xl bg-emerald-50 text-emerald-600 inline-flex items-center justify-center text-xl"><i class="bi bi-book"></i></div>
                            <div>
                                <div class="text-[34px] leading-none font-semibold text-slate-700"><?php echo $total_classes; ?></div>
                                <div class="text-sm text-slate-400">Total Classes</div>
                            </div>
                        </div>
                    </article>

                    <article class="summary-card rounded-3xl border border-slate-200 bg-white p-5">
                        <div class="flex items-center gap-3">
                            <div class="h-11 w-11 rounded-2xl bg-indigo-50 text-indigo-500 inline-flex items-center justify-center text-xl"><i class="bi bi-people"></i></div>
                            <div>
                                <div class="text-[34px] leading-none font-semibold text-slate-700"><?php echo $total_students; ?></div>
                                <div class="text-sm text-slate-400">Total Students</div>
                            </div>
                        </div>
                    </article>

                    <article class="summary-card rounded-3xl border border-slate-200 bg-white p-5">
                        <div class="flex items-center gap-3">
                            <div class="h-11 w-11 rounded-2xl bg-amber-50 text-amber-500 inline-flex items-center justify-center text-xl"><i class="bi bi-mortarboard"></i></div>
                            <div>
                                <div class="text-[34px] leading-none font-semibold text-slate-700"><?php echo $avg_class_size; ?></div>
                                <div class="text-sm text-slate-400">Average Class Size</div>
                            </div>
                        </div>
                    </article>
                </section>

                <section class="space-y-3">
                    <?php if (empty($class_list)): ?>
                        <div class="rounded-2xl border border-slate-200 bg-white px-4 py-6 text-sm text-slate-500">You don't have classes assigned yet.</div>
                    <?php else: ?>
                        <?php foreach ($class_list as $class): ?>
                            <article class="class-card rounded-3xl border border-slate-200 bg-white px-5 py-4">
                                <div class="grid grid-cols-[auto,1fr,140px,32px] gap-4 items-center">
                                    <div class="h-12 w-12 rounded-2xl bg-emerald-600 text-white inline-flex items-center justify-center text-sm font-semibold">
                                        <?php echo htmlspecialchars(ins_cls_course_chip((string) $class['course_code'])); ?>
                                    </div>

                                    <div class="min-w-0">
                                        <div class="text-[30px] leading-tight font-semibold text-slate-700 truncate"><?php echo htmlspecialchars((string) $class['course_name']); ?></div>
                                        <div class="mt-1 text-sm text-slate-400 flex flex-wrap gap-3 items-center">
                                            <span><i class="bi bi-clock"></i> <?php echo htmlspecialchars((string) $class['days_label']); ?> <?php echo htmlspecialchars(ins_cls_time_label((string) $class['start_time'])); ?>-<?php echo htmlspecialchars(ins_cls_time_label((string) $class['end_time'])); ?></span>
                                            <span><i class="bi bi-geo-alt"></i> <?php echo htmlspecialchars((string) $class['room_number']); ?></span>
                                            <span><i class="bi bi-people"></i> <?php echo (int) $class['enrolled_students']; ?> students</span>
                                        </div>
                                    </div>

                                    <div class="justify-self-end w-full">
                                        <div class="flex items-center justify-between text-sm text-slate-400">
                                            <span class="font-semibold">Progress</span>
                                            <span class="font-semibold text-slate-500"><?php echo (int) $class['progress']; ?>%</span>
                                        </div>
                                        <div class="mt-1 h-2 rounded-full bg-slate-100">
                                            <div class="h-2 rounded-full bg-emerald-500" style="width: <?php echo (int) $class['progress']; ?>%;"></div>
                                        </div>
                                    </div>

                                    <button type="button" class="js-class-toggle inline-flex h-8 w-8 items-center justify-center rounded-lg border border-slate-200 text-slate-400 hover:text-slate-600 hover:bg-slate-50" aria-expanded="false" aria-label="Toggle class details">
                                        <i class="bi bi-chevron-down class-toggle-icon"></i>
                                    </button>
                                </div>

                                <div class="class-detail mt-4 border-t border-slate-100 pt-4">
                                    <div class="flex flex-wrap items-center gap-2 text-xs">
                                        <span class="inline-flex items-center gap-1 rounded-full bg-slate-100 px-2.5 py-1 font-semibold text-slate-600">
                                            <i class="bi bi-calendar-week"></i>
                                            <?php echo (int) $class['meeting_count']; ?> meeting<?php echo ((int) $class['meeting_count'] === 1) ? '' : 's'; ?>
                                        </span>
                                        <span class="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2.5 py-1 font-semibold text-emerald-700">
                                            <i class="bi bi-person-check"></i>
                                            <?php echo (int) $class['available_slots']; ?> slots left
                                        </span>
                                        <a href="my_schedule.php" class="inline-flex items-center gap-1 rounded-full border border-slate-200 px-2.5 py-1 font-semibold text-slate-600 hover:bg-slate-50">
                                            <i class="bi bi-calendar3"></i>
                                            Open Weekly Schedule
                                        </a>
                                    </div>

                                    <div class="mt-3 grid grid-cols-1 md:grid-cols-2 gap-2">
                                        <?php foreach ($class['schedule_rows'] as $meeting): ?>
                                            <?php
                                                $meeting_start = (string) ($meeting['start_time'] ?? '');
                                                $meeting_end = (string) ($meeting['end_time'] ?? '');
                                                if ($meeting_end === '') {
                                                    $meeting_end = date('H:i:s', strtotime($meeting_start . ' +2 hours'));
                                                }
                                            ?>
                                            <div class="rounded-xl border border-slate-200 bg-slate-50/70 px-3 py-2.5 flex items-center justify-between gap-3">
                                                <div>
                                                    <div class="text-sm font-semibold text-slate-700"><?php echo htmlspecialchars((string) ($meeting['day'] ?? 'Day TBA')); ?></div>
                                                    <div class="text-xs text-slate-500"><?php echo htmlspecialchars(ins_cls_time_label($meeting_start)); ?> - <?php echo htmlspecialchars(ins_cls_time_label($meeting_end)); ?></div>
                                                </div>
                                                <button
                                                    type="button"
                                                    class="js-open-students-modal inline-flex items-center gap-1 rounded-lg bg-emerald-600 px-2.5 py-1.5 text-xs font-semibold text-white hover:bg-emerald-700"
                                                    data-schedule-id="<?php echo (int) ($meeting['id'] ?? 0); ?>"
                                                    data-course-name="<?php echo htmlspecialchars((string) ($class['course_name'] ?? 'Class')); ?>"
                                                    data-day="<?php echo htmlspecialchars((string) ($meeting['day'] ?? 'Day TBA')); ?>"
                                                    data-time="<?php echo htmlspecialchars(ins_cls_time_label($meeting_start) . ' - ' . ins_cls_time_label($meeting_end)); ?>"
                                                >
                                                    <i class="bi bi-people"></i>
                                                    Students
                                                </button>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </section>
            </main>
        </div>
    </div>

    <div id="studentsModal" class="fixed inset-0 z-50 hidden" aria-hidden="true">
        <div class="absolute inset-0 bg-slate-900/50" data-students-modal-close></div>
        <div class="absolute inset-0 p-4 flex items-center justify-center">
            <div class="w-full max-w-lg rounded-3xl border border-slate-200 bg-white shadow-2xl overflow-hidden">
                <div class="px-5 py-4 border-b border-slate-200 flex items-start justify-between gap-4">
                    <div>
                        <div class="text-xs font-semibold tracking-[0.18em] text-emerald-600">ENROLLED STUDENTS</div>
                        <h3 id="studentsModalCourse" class="mt-1 text-xl font-semibold text-slate-900">Class</h3>
                        <p id="studentsModalMeta" class="text-sm text-slate-500"></p>
                    </div>
                    <button id="studentsModalCloseBtn" type="button" class="inline-flex h-9 w-9 items-center justify-center rounded-xl border border-slate-200 text-slate-600 hover:bg-slate-50" aria-label="Close students modal">
                        <i class="bi bi-x-lg"></i>
                    </button>
                </div>

                <div class="px-5 py-5">
                    <div id="studentsModalCount" class="text-sm font-semibold text-emerald-700">0 students</div>
                    <ul id="studentsModalList" class="mt-3 max-h-72 overflow-auto space-y-2 pr-1 text-sm text-slate-700">
                        <li class="rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-slate-500">No students enrolled yet.</li>
                    </ul>
                </div>

                <div class="px-5 pb-5 flex justify-end">
                    <button type="button" class="inline-flex items-center rounded-xl bg-emerald-600 text-white px-4 py-2 text-sm font-semibold hover:bg-emerald-700" data-students-modal-close>Close</button>
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
            const toggleButtons = document.querySelectorAll('.js-class-toggle');
            if (!toggleButtons.length) {
                return;
            }

            toggleButtons.forEach(function (button) {
                button.addEventListener('click', function () {
                    const card = button.closest('.class-card');
                    if (!card) {
                        return;
                    }

                    const isOpen = card.classList.contains('open');
                    card.classList.toggle('open', !isOpen);
                    button.setAttribute('aria-expanded', isOpen ? 'false' : 'true');
                });
            });
        })();

        (function () {
            const modal = document.getElementById('studentsModal');
            const openButtons = document.querySelectorAll('.js-open-students-modal');
            const closeTriggers = document.querySelectorAll('[data-students-modal-close], #studentsModalCloseBtn');
            const courseEl = document.getElementById('studentsModalCourse');
            const metaEl = document.getElementById('studentsModalMeta');
            const countEl = document.getElementById('studentsModalCount');
            const listEl = document.getElementById('studentsModalList');
            const studentsBySchedule = <?php echo json_encode($students_by_schedule_modal, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?> || {};

            if (!modal || !openButtons.length) {
                return;
            }

            function openModal() {
                modal.classList.remove('hidden');
                modal.setAttribute('aria-hidden', 'false');
            }

            function closeModal() {
                modal.classList.add('hidden');
                modal.setAttribute('aria-hidden', 'true');
            }

            function renderStudents(scheduleId, courseName, day, timeLabel) {
                const students = studentsBySchedule[String(scheduleId)] || [];
                if (courseEl) {
                    courseEl.textContent = courseName || 'Class';
                }
                if (metaEl) {
                    metaEl.textContent = [day || 'Day TBA', timeLabel || 'Time TBA'].join(' | ');
                }
                if (countEl) {
                    countEl.textContent = students.length + ' student' + (students.length === 1 ? '' : 's');
                }

                if (!listEl) {
                    return;
                }

                listEl.innerHTML = '';
                if (!students.length) {
                    const li = document.createElement('li');
                    li.className = 'rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-slate-500';
                    li.textContent = 'No students enrolled yet.';
                    listEl.appendChild(li);
                    return;
                }

                students.forEach(function (studentName) {
                    const li = document.createElement('li');
                    li.className = 'rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-slate-700';
                    li.textContent = studentName;
                    listEl.appendChild(li);
                });
            }

            openButtons.forEach(function (button) {
                button.addEventListener('click', function (event) {
                    event.preventDefault();
                    event.stopPropagation();

                    const scheduleId = button.getAttribute('data-schedule-id') || '0';
                    const courseName = button.getAttribute('data-course-name') || 'Class';
                    const day = button.getAttribute('data-day') || 'Day TBA';
                    const timeLabel = button.getAttribute('data-time') || 'Time TBA';

                    renderStudents(scheduleId, courseName, day, timeLabel);
                    openModal();
                });
            });

            closeTriggers.forEach(function (trigger) {
                trigger.addEventListener('click', function () {
                    closeModal();
                });
            });

            document.addEventListener('keydown', function (event) {
                if (event.key === 'Escape' && !modal.classList.contains('hidden')) {
                    closeModal();
                }
            });
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
    </script>
</body>
</html>
