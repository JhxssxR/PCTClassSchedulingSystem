<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is an admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'super_admin') {
    header('Location: ../auth/login.php');
    exit();
}

require_once __DIR__ . '/notifications_data.php';

$initial_search = trim((string)($_GET['search'] ?? ''));

// Subjects table may not exist on older DBs
$subjects_table_exists_stmt = $conn->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'subjects'");
$subjects_table_exists_stmt->execute();
$subjects_table_exists = ((int)$subjects_table_exists_stmt->fetchColumn() > 0);

// Schedules schema compatibility (some DB versions don't store end_time)
$schedule_cols_stmt = $conn->prepare('DESCRIBE schedules');
$schedule_cols_stmt->execute();
$schedule_cols = [];
foreach ($schedule_cols_stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $schedule_cols[$r['Field']] = true;
}

if (isset($schedule_cols['end_time'])) {
    $end_time_expr = 's.end_time';
} elseif (isset($schedule_cols['duration_minutes'])) {
    $end_time_expr = 'ADDTIME(s.start_time, SEC_TO_TIME(s.duration_minutes * 60))';
} else {
    $end_time_expr = 'ADDTIME(s.start_time, SEC_TO_TIME(120 * 60))';
}
$has_start_date = isset($schedule_cols['start_date']);
$has_end_date = isset($schedule_cols['end_date']);
$date_fields_select = '';
if ($has_start_date) {
    $date_fields_select .= 's.start_date, ';
}
if ($has_end_date) {
    $date_fields_select .= 's.end_date, ';
}

// Fetch schedules as "Classes" (matches view_class.php)
$subjects_enabled = ($subjects_table_exists && isset($schedule_cols['subject_id']));
$subject_fields_select = $subjects_enabled
    ? ', s.subject_id, subj.subject_code, subj.subject_name'
    : '';
$subject_join_sql = $subjects_enabled
    ? ' LEFT JOIN subjects subj ON subj.id = s.subject_id'
    : '';
$order_by_name = $subjects_enabled
    ? 'COALESCE(subj.subject_name, c.course_name)'
    : 'c.course_name';
$stmt = $conn->prepare("
    SELECT
        s.id,
        s.course_id,
        s.instructor_id,
        s.classroom_id,
        s.status,
        s.day_of_week,
        s.start_time,
        {$end_time_expr} AS end_time,
        {$date_fields_select}
        s.max_students,
        s.semester,
        s.academic_year,
        s.year_level,
        c.course_code,
        c.course_name
        {$subject_fields_select},
        CONCAT(i.first_name, ' ', i.last_name) AS instructor_name,
        cr.room_number,
        (SELECT COUNT(*) FROM enrollments e
            WHERE e.schedule_id = s.id AND e.status IN ('approved', 'enrolled')
        ) AS enrolled_students
    FROM schedules s
    JOIN courses c ON c.id = s.course_id
    {$subject_join_sql}
    JOIN users i ON i.id = s.instructor_id
    JOIN classrooms cr ON cr.id = s.classroom_id
    ORDER BY (s.status = 'active') DESC, {$order_by_name}, s.day_of_week, s.start_time
");
$stmt->execute();
$classes = $stmt->fetchAll(PDO::FETCH_ASSOC);

$day_order_map = array_flip(['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday']);

$core_group_stats = [];
foreach ($classes as $class_row) {
    $core_key = implode('|', [
        (string)($class_row['course_id'] ?? ''),
        (string)($class_row['subject_id'] ?? ''),
        (string)($class_row['instructor_id'] ?? ''),
        (string)($class_row['classroom_id'] ?? ''),
        (string)($class_row['start_time'] ?? ''),
        (string)($class_row['end_time'] ?? ''),
        (string)($class_row['status'] ?? ''),
        (string)($class_row['max_students'] ?? ''),
    ]);

    if (!isset($core_group_stats[$core_key])) {
        $core_group_stats[$core_key] = [
            'semester' => [],
            'academic_year' => [],
            'year_level' => [],
            'start_date' => [],
            'end_date' => [],
        ];
    }

    $semester = trim((string)($class_row['semester'] ?? ''));
    $academic_year = trim((string)($class_row['academic_year'] ?? ''));
    $year_level = trim((string)($class_row['year_level'] ?? ''));
    $start_date = trim((string)($class_row['start_date'] ?? ''));
    $end_date = trim((string)($class_row['end_date'] ?? ''));

    if ($semester !== '') {
        $core_group_stats[$core_key]['semester'][$semester] = true;
    }
    if ($academic_year !== '') {
        $core_group_stats[$core_key]['academic_year'][$academic_year] = true;
    }
    if ($year_level !== '') {
        $core_group_stats[$core_key]['year_level'][$year_level] = true;
    }
    if ($start_date !== '') {
        $core_group_stats[$core_key]['start_date'][$start_date] = true;
    }
    if ($end_date !== '') {
        $core_group_stats[$core_key]['end_date'][$end_date] = true;
    }
}

$classes_grouped_map = [];
foreach ($classes as $class_row) {
    $core_key = implode('|', [
        (string)($class_row['course_id'] ?? ''),
        (string)($class_row['subject_id'] ?? ''),
        (string)($class_row['instructor_id'] ?? ''),
        (string)($class_row['classroom_id'] ?? ''),
        (string)($class_row['start_time'] ?? ''),
        (string)($class_row['end_time'] ?? ''),
        (string)($class_row['status'] ?? ''),
        (string)($class_row['max_students'] ?? ''),
    ]);

    $stats = $core_group_stats[$core_key] ?? [
        'semester' => [],
        'academic_year' => [],
        'year_level' => [],
        'start_date' => [],
        'end_date' => [],
    ];

    $semester = trim((string)($class_row['semester'] ?? ''));
    $academic_year = trim((string)($class_row['academic_year'] ?? ''));
    $year_level = trim((string)($class_row['year_level'] ?? ''));
    $start_date = trim((string)($class_row['start_date'] ?? ''));
    $end_date = trim((string)($class_row['end_date'] ?? ''));

    if ($semester === '' && count($stats['semester']) === 1) {
        $semester = (string)array_key_first($stats['semester']);
        $class_row['semester'] = $semester;
    }
    if ($academic_year === '' && count($stats['academic_year']) === 1) {
        $academic_year = (string)array_key_first($stats['academic_year']);
        $class_row['academic_year'] = $academic_year;
    }
    if ($year_level === '' && count($stats['year_level']) === 1) {
        $year_level = (string)array_key_first($stats['year_level']);
        $class_row['year_level'] = $year_level;
    }
    if ($start_date === '' && count($stats['start_date']) === 1) {
        $start_date = (string)array_key_first($stats['start_date']);
        $class_row['start_date'] = $start_date;
    }
    if ($end_date === '' && count($stats['end_date']) === 1) {
        $end_date = (string)array_key_first($stats['end_date']);
        $class_row['end_date'] = $end_date;
    }

    $group_key = $core_key . '|' . $semester . '|' . $academic_year . '|' . $year_level . '|' . $start_date . '|' . $end_date;

    if (!isset($classes_grouped_map[$group_key])) {
        $class_row['group_size'] = 0;
        $class_row['combined_days_label'] = '';
        $class_row['__days'] = [];
        $class_row['__ids'] = [];
        $classes_grouped_map[$group_key] = $class_row;
    }

    $day_value = trim((string)($class_row['day_of_week'] ?? ''));
    if ($day_value !== '' && !in_array($day_value, $classes_grouped_map[$group_key]['__days'], true)) {
        $classes_grouped_map[$group_key]['__days'][] = $day_value;
    }

    $classes_grouped_map[$group_key]['__ids'][] = (int)($class_row['id'] ?? 0);
    $classes_grouped_map[$group_key]['group_size'] = count($classes_grouped_map[$group_key]['__days']);
    $classes_grouped_map[$group_key]['enrolled_students'] = max(
        (int)($classes_grouped_map[$group_key]['enrolled_students'] ?? 0),
        (int)($class_row['enrolled_students'] ?? 0)
    );
}

$classes_display = array_values($classes_grouped_map);
foreach ($classes_display as &$class_row) {
    usort($class_row['__days'], function ($a, $b) use ($day_order_map) {
        return (($day_order_map[$a] ?? 99) <=> ($day_order_map[$b] ?? 99));
    });

    $class_row['combined_days_label'] = !empty($class_row['__days'])
        ? implode(', ', $class_row['__days'])
        : (string)($class_row['day_of_week'] ?? '');

    if (!empty($class_row['__ids'])) {
        $class_row['id'] = (int)$class_row['__ids'][0];
    }

    unset($class_row['__days'], $class_row['__ids']);
}
unset($class_row);

// Final guard: merge rows that are the same class but split by legacy metadata drift.
$merged_display_map = [];
foreach ($classes_display as $class_row) {
    $merge_key = implode('|', [
        (string)($class_row['course_id'] ?? ''),
        (string)($class_row['subject_id'] ?? ''),
        (string)($class_row['instructor_id'] ?? ''),
        (string)($class_row['classroom_id'] ?? ''),
        (string)($class_row['start_time'] ?? ''),
        (string)($class_row['end_time'] ?? ''),
        (string)($class_row['status'] ?? ''),
        (string)($class_row['max_students'] ?? ''),
    ]);

    $days = array_filter(array_map('trim', explode(',', (string)($class_row['combined_days_label'] ?? ''))));
    $ids = [];
    if (!empty($class_row['id'])) {
        $ids[] = (int)$class_row['id'];
    }

    if (!isset($merged_display_map[$merge_key])) {
        $class_row['__days'] = array_values(array_unique($days));
        $class_row['__ids'] = $ids;
        $merged_display_map[$merge_key] = $class_row;
        continue;
    }

    $existing = &$merged_display_map[$merge_key];
    $existing['__days'] = array_values(array_unique(array_merge($existing['__days'] ?? [], $days)));
    $existing['__ids'] = array_values(array_unique(array_merge($existing['__ids'] ?? [], $ids)));
    $existing['enrolled_students'] = max(
        (int)($existing['enrolled_students'] ?? 0),
        (int)($class_row['enrolled_students'] ?? 0)
    );

    foreach (['semester', 'academic_year', 'year_level'] as $f) {
        if (trim((string)($existing[$f] ?? '')) === '' && trim((string)($class_row[$f] ?? '')) !== '') {
            $existing[$f] = $class_row[$f];
        }
    }

    $existing_start = trim((string)($existing['start_date'] ?? ''));
    $new_start = trim((string)($class_row['start_date'] ?? ''));
    if ($new_start !== '' && ($existing_start === '' || strtotime($new_start) < strtotime($existing_start))) {
        $existing['start_date'] = $new_start;
    }

    $existing_end = trim((string)($existing['end_date'] ?? ''));
    $new_end = trim((string)($class_row['end_date'] ?? ''));
    if ($new_end !== '' && ($existing_end === '' || strtotime($new_end) > strtotime($existing_end))) {
        $existing['end_date'] = $new_end;
    }
    unset($existing);
}

$classes_display = array_values($merged_display_map);
foreach ($classes_display as &$class_row) {
    usort($class_row['__days'], function ($a, $b) use ($day_order_map) {
        return (($day_order_map[$a] ?? 99) <=> ($day_order_map[$b] ?? 99));
    });

    $class_row['combined_days_label'] = !empty($class_row['__days'])
        ? implode(', ', $class_row['__days'])
        : (string)($class_row['day_of_week'] ?? '');
    $class_row['group_size'] = count($class_row['__days']);

    if (!empty($class_row['__ids'])) {
        $class_row['id'] = (int)$class_row['__ids'][0];
    }

    unset($class_row['__days'], $class_row['__ids']);
}
unset($class_row);

// Select options for modals
$subjects = [];
if ($subjects_enabled) {
    // year_level used to filter in Add/Edit Class modals
    $subjects = $conn->query("SELECT id, subject_code, subject_name, year_level FROM subjects ORDER BY subject_code")
        ->fetchAll(PDO::FETCH_ASSOC);
}

$courses = $conn->query("SELECT id, course_code, course_name FROM courses ORDER BY course_code")
    ->fetchAll(PDO::FETCH_ASSOC);
$instructors = $conn->query("SELECT id, first_name, last_name, email FROM users WHERE role = 'instructor' ORDER BY last_name, first_name")
    ->fetchAll(PDO::FETCH_ASSOC);
$classrooms = $conn->query("SELECT id, room_number, room_type FROM classrooms ORDER BY room_number")
    ->fetchAll(PDO::FETCH_ASSOC);

$active_count = 0;
foreach ($classes_display as $row) {
    if (($row['status'] ?? '') === 'active') {
        $active_count++;
    }
}

function time_range_label($start, $end) {
    if (!$start || !$end) return '';
    return date('g:i A', strtotime($start)) . ' - ' . date('g:i A', strtotime($end));
}

function date_range_label($start_date, $end_date) {
    $start = trim((string)$start_date);
    $end = trim((string)$end_date);
    if ($start === '' && $end === '') return '';
    if ($start !== '' && $end !== '') {
        return date('M j, Y', strtotime($start)) . ' - ' . date('M j, Y', strtotime($end));
    }
    if ($start !== '') {
        return 'Starts ' . date('M j, Y', strtotime($start));
    }
    return 'Ends ' . date('M j, Y', strtotime($end));
}

function status_badge_classes($status) {
    $status = strtolower((string)$status);
    if ($status === 'active') {
        return 'bg-emerald-50 text-emerald-700 ring-1 ring-inset ring-emerald-200';
    }
    if ($status === 'cancelled') {
        return 'bg-rose-50 text-rose-700 ring-1 ring-inset ring-rose-200';
    }
    if ($status === 'completed') {
        return 'bg-slate-100 text-slate-700 ring-1 ring-inset ring-slate-200';
    }
    if ($status === 'inactive') {
        return 'bg-slate-100 text-slate-700 ring-1 ring-inset ring-slate-200';
    }
    return 'bg-slate-100 text-slate-700 ring-1 ring-inset ring-slate-200';
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" type="image/png" href="/PCTClassSchedulingSystem/pctlogo.png">
<meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Classes - PCT Class Scheduling</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
</head>
<body class="bg-slate-50 text-slate-900">
    <?php
        $user_initials = 'SA';
        $full_name = 'Super Admin';
        if (!empty($_SESSION['first_name']) || !empty($_SESSION['last_name'])) {
            $full_name = trim(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? ''));
            $first = strtoupper(substr($_SESSION['first_name'] ?? '', 0, 1));
            $last = strtoupper(substr($_SESSION['last_name'] ?? '', 0, 1));
            $user_initials = trim($first . $last);
            if ($user_initials === '') {
                $user_initials = 'SA';
            }
        }
    ?>

    <div class="min-h-screen">
        <!-- Sidebar -->
        <aside id="sidebar" class="fixed inset-y-0 left-0 z-40 w-72 -translate-x-full lg:translate-x-0 transition-transform bg-emerald-950 text-emerald-50 border-r border-emerald-900/40">
            <div class="h-16 px-6 flex items-center gap-3 border-b border-emerald-900/40">
                <img src="../pctlogo.png" alt="PCT Logo" class="h-9 w-9 rounded-full bg-emerald-50/10 object-contain" />
                <div class="leading-tight">
                    <div class="text-sm font-semibold">PCT Super Admin</div>
                    <div class="text-xs text-emerald-100/70">Management Portal</div>
                </div>
            </div>

            <div class="px-4 py-4">
                <div class="text-[11px] tracking-widest text-emerald-100/60 px-3 mb-2">NAVIGATION</div>
                <nav class="space-y-1">
                    <a href="dashboard.php" class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-emerald-50/80 hover:text-emerald-50 hover:bg-emerald-900/30">
                        <i class="bi bi-speedometer2"></i>
                        <span class="text-sm font-medium">Dashboard</span>
                    </a>
                    <a href="users.php" class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-emerald-50/80 hover:text-emerald-50 hover:bg-emerald-900/30">
                        <i class="bi bi-people"></i>
                        <span class="text-sm font-medium">All Users</span>
                    </a>
                    <a href="instructors.php" class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-emerald-50/80 hover:text-emerald-50 hover:bg-emerald-900/30">
                        <i class="bi bi-person-video3"></i>
                        <span class="text-sm font-medium">Instructors</span>
                    </a>
                    <a href="students.php" class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-emerald-50/80 hover:text-emerald-50 hover:bg-emerald-900/30">
                        <i class="bi bi-mortarboard"></i>
                        <span class="text-sm font-medium">Students</span>
                    </a>
                    <a href="classes.php" class="flex items-center gap-3 px-3 py-2.5 rounded-xl bg-emerald-900/40 text-emerald-50">
                        <i class="bi bi-book"></i>
                        <span class="text-sm font-medium">Classes</span>
                    </a>
                    <a href="classrooms.php" class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-emerald-50/80 hover:text-emerald-50 hover:bg-emerald-900/30">
                        <i class="bi bi-door-open"></i>
                        <span class="text-sm font-medium">Classrooms</span>
                    </a>
                    <a href="subjects.php" class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-emerald-50/80 hover:text-emerald-50 hover:bg-emerald-900/30">
                        <i class="bi bi-journal-bookmark"></i>
                        <span class="text-sm font-medium">Subjects</span>
                    </a>
                    <a href="courses.php" class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-emerald-50/80 hover:text-emerald-50 hover:bg-emerald-900/30">
                        <i class="bi bi-journal-text"></i>
                        <span class="text-sm font-medium">Courses</span>
                    </a>
                    <a href="schedules.php" class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-emerald-50/80 hover:text-emerald-50 hover:bg-emerald-900/30">
                        <i class="bi bi-calendar3"></i>
                        <span class="text-sm font-medium">Schedules</span>
                    </a>
                    <a href="enrollments.php" class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-emerald-50/80 hover:text-emerald-50 hover:bg-emerald-900/30">
                        <i class="bi bi-person-plus"></i>
                        <span class="text-sm font-medium">Enrollments</span>
                    </a>
                    <a href="reports.php" class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-emerald-50/80 hover:text-emerald-50 hover:bg-emerald-900/30">
                        <i class="bi bi-file-earmark-text"></i>
                        <span class="text-sm font-medium">Reports</span>
                    </a>
                    <a href="settings.php" class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-emerald-50/80 hover:text-emerald-50 hover:bg-emerald-900/30">
                        <i class="bi bi-gear"></i>
                        <span class="text-sm font-medium">Settings</span>
                    </a>
                </nav>
            </div>

            <div class="absolute bottom-0 left-0 right-0 p-4">
                <a href="../auth/logout.php" class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-rose-200 hover:text-rose-100 hover:bg-rose-500/15 border border-transparent hover:border-rose-400/20">
                    <i class="bi bi-box-arrow-right text-rose-300"></i>
                    <span class="text-sm font-semibold">Logout</span>
                </a>
            </div>
        </aside>

        <!-- Overlay (mobile) -->
        <div id="sidebarOverlay" class="fixed inset-0 z-30 hidden bg-slate-900/40 lg:hidden"></div>

        <!-- Main -->
        <div id="contentWrap" class="min-h-screen transition-all duration-300">
            <header class="sticky top-0 z-20 h-16 bg-white/90 backdrop-blur border-b border-slate-200">
                <div class="h-full px-4 sm:px-6 flex items-center justify-between">
                    <div class="flex items-center gap-3">
                        <button id="sidebarBtn" type="button" class="inline-flex h-10 w-10 items-center justify-center rounded-xl border border-slate-200 bg-white hover:bg-slate-50">
                            <i class="bi bi-list text-xl"></i>
                        </button>
                        <div class="flex items-center gap-2 text-sm">
                            <span class="text-slate-500">Super Admin</span>
                            <span class="text-slate-300">/</span>
                            <span class="font-semibold text-slate-900">Classes</span>
                        </div>
                    </div>

                    <div class="flex items-center gap-3">
                        <div class="relative hidden sm:block">
                            <button id="notifBtn" type="button" class="inline-flex h-10 w-10 items-center justify-center rounded-xl border border-slate-200 bg-white hover:bg-slate-50" aria-label="Notifications" aria-haspopup="menu" aria-expanded="false" aria-controls="notifMenu">
                                <span class="relative">
                                    <i class="bi bi-bell text-lg text-slate-700"></i>
                                    <span id="notifDot" class="absolute -right-1 -top-1 min-w-5 h-5 px-1 rounded-full bg-rose-500 text-white text-[10px] font-bold flex items-center justify-center ring-2 ring-white <?php echo (($notif_unread_total ?? 0) > 0) ? '' : 'hidden'; ?>"><?php echo htmlspecialchars($notif_badge_label ?? ''); ?></span>
                                </span>
                            </button>

                            <div id="notifMenu" class="absolute right-0 mt-2 w-80 hidden" role="menu" aria-label="Notifications">
                                <div class="rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden">
                                    <div class="px-4 py-3 border-b border-slate-200 flex items-start justify-between gap-3">
                                        <div>
                                            <div class="text-sm font-semibold text-slate-900">Notifications</div>
                                            <div class="text-xs text-slate-500">Updates and reminders</div>
                                        </div>
                                        <div class="flex items-center gap-3">
                                            <button id="notifMarkRead" type="button" class="text-xs font-semibold text-emerald-600 hover:text-emerald-700">Mark as read</button>
                                            <button id="notifDelete" type="button" class="text-xs font-semibold text-rose-600 hover:text-rose-700">Delete</button>
                                        </div>
                                    </div>
                                    <div class="p-3">
                                        <?php if (empty($notif_items)): ?>
                                            <div class="rounded-xl border border-slate-200 bg-slate-50 p-3 text-xs text-slate-500">
                                                No new notifications.
                                            </div>
                                        <?php else: ?>
                                            <div class="space-y-2">
                                                <?php foreach ($notif_items as $it): ?>
                                                    <a href="<?php echo htmlspecialchars($it['href'] ?? '#'); ?>" class="block rounded-xl border border-slate-200 bg-white p-3 hover:bg-slate-50">
                                                        <div class="flex items-start gap-3">
                                                            <div class="inline-flex h-8 w-8 items-center justify-center rounded-lg bg-slate-100 text-slate-600">
                                                                <i class="bi <?php echo htmlspecialchars($it['icon'] ?? 'bi-bell'); ?>"></i>
                                                            </div>
                                                            <div class="min-w-0">
                                                                <div class="text-sm font-semibold text-slate-900 truncate"><?php echo htmlspecialchars($it['title'] ?? 'Notification'); ?></div>
                                                                <div class="text-xs text-slate-500 truncate"><?php echo htmlspecialchars($it['subtitle'] ?? ''); ?></div>
                                                            </div>
                                                        </div>
                                                    </a>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php endif; ?>

                                        <div class="mt-3 text-xs text-slate-500">
                                            <?php
                                                $ni = (int)($notif_new_instructors ?? 0);
                                                $ne = (int)($notif_new_enrollments ?? 0);
                                                echo htmlspecialchars($ni . ' new instructor(s), ' . $ne . ' new enrollment(s) since last check.');
                                            ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="hidden sm:flex items-center gap-3">
                            <div class="h-10 w-10 rounded-full bg-emerald-600 text-white flex items-center justify-center font-semibold">
                                <?php echo htmlspecialchars($user_initials); ?>
                            </div>
                            <div class="text-left">
                                <div class="text-sm font-semibold text-slate-900"><?php echo htmlspecialchars($full_name); ?></div>
                                <div class="text-xs text-slate-500">PCT System</div>
                            </div>
                        </div>
                    </div>
                </div>
            </header>

            <main class="px-4 sm:px-6 py-6">
                <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-4">
                    <div>
                        <h1 class="text-2xl font-semibold text-slate-900">Classes</h1>
                        <p class="text-sm text-slate-500"><?php echo (int)$active_count; ?> classes currently active</p>
                    </div>

                    <div class="flex items-center gap-3">
                        <button id="addClassBtn" type="button" class="inline-flex items-center gap-2 rounded-xl bg-emerald-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-emerald-700">
                            <i class="bi bi-plus-lg"></i>
                            <span>Add Class</span>
                        </button>
                    </div>
                </div>

                <?php if (isset($_SESSION['success']) || isset($_SESSION['error'])): ?>
                    <div class="mt-4">
                        <?php if (isset($_SESSION['success'])): ?>
                            <div class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-emerald-800">
                                <?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?>
                            </div>
                        <?php endif; ?>
                        <?php if (isset($_SESSION['error'])): ?>
                            <div class="mt-3 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-rose-800">
                                <?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <div class="mt-6 rounded-2xl border border-slate-200 bg-white shadow-sm">
                    <div class="p-4 sm:p-5 border-b border-slate-200">
                        <div class="relative max-w-md">
                            <i class="bi bi-search absolute left-3 top-1/2 -translate-y-1/2 text-slate-400"></i>
                            <input id="classSearch" type="text" placeholder="Search by name, code, instructor…" value="<?php echo htmlspecialchars($initial_search); ?>" class="w-full rounded-xl border border-slate-200 bg-slate-50 pl-10 pr-3 py-2.5 text-sm outline-none focus:bg-white focus:ring-2 focus:ring-emerald-200" />
                        </div>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="min-w-full table-fixed text-sm">
                            <thead class="text-xs uppercase tracking-wider text-slate-500 bg-slate-50">
                                <tr>
                                    <th class="px-5 py-3 text-left font-semibold">Subject</th>
                                    <th class="px-5 py-3 text-left font-semibold">Instructor</th>
                                    <th class="px-5 py-3 text-left font-semibold">Room</th>
                                    <th class="px-5 py-3 text-left font-semibold w-[38%]">Schedule</th>
                                    <th class="px-5 py-3 text-left font-semibold w-[10%]">Enrollment</th>
                                    <th class="px-5 py-3 text-left font-semibold">Status</th>
                                    <th class="px-5 py-3 text-right font-semibold">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($classes_display as $class): ?>
                                    <?php
                                        $enrolled = (int)($class['enrolled_students'] ?? 0);
                                        $max = (int)($class['max_students'] ?? 0);
                                        $pct = 0;
                                        if ($max > 0) {
                                            $pct = (int)round(($enrolled / $max) * 100);
                                            if ($pct < 0) $pct = 0;
                                            if ($pct > 100) $pct = 100;
                                        }
                                        $display_code = trim((string)($class['subject_code'] ?? ''));
                                        if ($display_code === '') {
                                            $display_code = trim((string)($class['course_code'] ?? ''));
                                        }
                                        if ($display_code === '') {
                                            $display_code = 'N/A';
                                        }

                                        $display_name = trim((string)($class['subject_name'] ?? ''));
                                        if ($display_name === '') {
                                            $display_name = trim((string)($class['course_name'] ?? ''));
                                        }
                                        if ($display_name === '') {
                                            $display_name = 'Untitled Class';
                                        }
                                    ?>
                                    <tr class="class-row border-t border-slate-100 hover:bg-slate-50/60" data-search="<?php echo htmlspecialchars(strtolower(($display_name) . ' ' . ($display_code) . ' ' . ($class['instructor_name'] ?? '') . ' ' . ($class['room_number'] ?? '') . ' ' . ($class['combined_days_label'] ?? ''))); ?>">
                                        <td class="px-5 py-4 w-[38%] align-top">
                                            <div class="font-semibold text-slate-900"><?php echo htmlspecialchars($display_name); ?></div>
                                            <div class="text-xs text-slate-500"><?php echo htmlspecialchars($display_code); ?></div>
                                        </td>
                                        <td class="px-5 py-4 text-slate-700"><?php echo htmlspecialchars($class['instructor_name'] ?? ''); ?></td>
                                        <td class="px-5 py-4 text-slate-700"><?php echo htmlspecialchars($class['room_number'] ?? ''); ?></td>
                                        <td class="px-5 py-4">
                                            <div class="text-slate-700"><?php echo htmlspecialchars($class['combined_days_label'] ?? ''); ?></div>
                                            <div class="text-xs text-slate-500"><?php echo htmlspecialchars(time_range_label($class['start_time'] ?? '', $class['end_time'] ?? '')); ?></div>
                                            <?php $range_label = date_range_label($class['start_date'] ?? '', $class['end_date'] ?? ''); ?>
                                            <?php if ($range_label !== ''): ?>
                                                <div class="text-xs text-slate-500"><?php echo htmlspecialchars($range_label); ?></div>
                                            <?php endif; ?>
                                            <?php if ((int)($class['group_size'] ?? 1) > 1): ?>
                                                <div class="text-[11px] text-slate-400"><?php echo (int)$class['group_size']; ?> linked schedules</div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-5 py-4 w-[10%]">
                                            <div class="flex items-center gap-2.5">
                                                <div class="min-w-[56px] text-sm text-slate-700">
                                                    <?php echo htmlspecialchars($enrolled . '/' . ($max > 0 ? $max : '—')); ?>
                                                </div>
                                                <div class="w-20 h-2 rounded-full bg-slate-100 overflow-hidden">
                                                    <div class="h-full bg-emerald-500" style="width: <?php echo (int)$pct; ?>%"></div>
                                                </div>
                                                <div class="w-8 text-right text-xs text-slate-500"><?php echo (int)$pct; ?>%</div>
                                            </div>
                                        </td>
                                        <td class="px-5 py-4">
                                            <span class="inline-flex items-center gap-2 rounded-full px-3 py-1 text-xs font-semibold <?php echo status_badge_classes($class['status'] ?? ''); ?>">
                                                <span class="h-1.5 w-1.5 rounded-full bg-current"></span>
                                                <?php echo htmlspecialchars(ucfirst((string)($class['status'] ?? ''))); ?>
                                            </span>
                                        </td>
                                        <td class="px-5 py-4">
                                            <div class="flex items-center justify-end gap-2 text-slate-500">
                                                <a class="h-9 w-9 inline-flex items-center justify-center rounded-xl hover:bg-slate-100" href="view_class.php?id=<?php echo (int)$class['id']; ?>" title="View">
                                                    <i class="bi bi-eye"></i>
                                                </a>
                                                <button type="button" class="h-9 w-9 inline-flex items-center justify-center rounded-xl hover:bg-slate-100" onclick='editClass(<?php echo json_encode($class, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>)' title="Edit">
                                                    <i class="bi bi-pencil"></i>
                                                </button>
                                                <button type="button" class="h-9 w-9 inline-flex items-center justify-center rounded-xl hover:bg-slate-100" onclick="deleteClass(<?php echo (int)$class['id']; ?>)" title="Delete">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>

                                <?php if (empty($classes_display)): ?>
                                    <tr>
                                        <td colspan="7" class="px-5 py-10 text-center text-slate-500">No classes found</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <!-- Add Class Modal -->
    <div id="addClassModal" class="fixed inset-0 z-50 hidden" aria-hidden="true">
        <div class="absolute inset-0 bg-slate-900/50" data-modal-close="addClassModal"></div>
        <div class="relative mx-auto my-10 w-[92%] max-w-3xl">
            <div class="rounded-2xl bg-white shadow-xl border border-slate-200 overflow-hidden">
                <div class="px-5 py-4 border-b border-slate-200 flex items-center justify-between">
                    <div class="text-base font-semibold text-slate-900">Add Class</div>
                    <button type="button" class="h-9 w-9 inline-flex items-center justify-center rounded-xl hover:bg-slate-100" data-modal-close="addClassModal" aria-label="Close">
                        <i class="bi bi-x-lg"></i>
                    </button>
                </div>

                <form id="addClassForm" action="process_class.php" method="POST" class="p-5">
                    <input type="hidden" name="action" value="add">

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-semibold text-slate-700 mb-1">Course</label>
                            <select name="course_id" id="add_course_id" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm" required>
                                <option value="">Select course</option>
                                <?php foreach ($courses as $c): ?>
                                    <option value="<?php echo (int)$c['id']; ?>"><?php echo htmlspecialchars(($c['course_code'] ?? '') . ' — ' . ($c['course_name'] ?? '')); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div>
                            <label class="block text-sm font-semibold text-slate-700 mb-1">Year Level</label>
                            <select name="year_level" id="add_year_level" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm" required>
                                <option value="">Select year level</option>
                                <option value="1">1st Year</option>
                                <option value="2">2nd Year</option>
                                <option value="3">3rd Year</option>
                                <option value="4">4th Year</option>
                            </select>
                        </div>

                        <div>
                            <label class="block text-sm font-semibold text-slate-700 mb-1">Subject</label>
                            <select name="subject_id" id="add_subject_id" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm" <?php echo $subjects_enabled ? 'required' : ''; ?> <?php echo $subjects_enabled ? '' : 'disabled'; ?>>
                                <option value="">Select subject</option>
                                <?php foreach ($subjects as $s): ?>
                                    <option value="<?php echo (int)$s['id']; ?>" data-year="<?php echo htmlspecialchars((string)($s['year_level'] ?? '')); ?>"><?php echo htmlspecialchars(($s['subject_code'] ?? '') . ' — ' . ($s['subject_name'] ?? '')); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <?php if (!$subjects_enabled): ?>
                                <div class="mt-1 text-xs text-amber-700">Subjects table missing — run <span class="font-mono">fix_subjects.php</span>.</div>
                            <?php endif; ?>
                        </div>

                        <div>
                            <label class="block text-sm font-semibold text-slate-700 mb-1">Instructor</label>
                            <select name="instructor_id" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm" required>
                                <option value="">Select instructor</option>
                                <?php foreach ($instructors as $ins): ?>
                                    <option value="<?php echo (int)$ins['id']; ?>"><?php echo htmlspecialchars(($ins['last_name'] ?? '') . ', ' . ($ins['first_name'] ?? '') . (!empty($ins['email']) ? (' (' . $ins['email'] . ')') : '')); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div>
                            <label class="block text-sm font-semibold text-slate-700 mb-1">Classroom</label>
                            <select name="classroom_id" id="add_classroom_id" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm" required>
                                <option value="">Select room</option>
                                <?php foreach ($classrooms as $r): ?>
                                    <option value="<?php echo (int)$r['id']; ?>" data-room-type="<?php echo htmlspecialchars(strtolower((string)($r['room_type'] ?? 'lecture'))); ?>"><?php echo htmlspecialchars($r['room_number'] ?? ''); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div class="mt-2">
                                <div id="addRoomTypeText" class="mb-2 text-xs text-slate-500">No room selected</div>
                                <div class="flex flex-wrap gap-2">
                                    <span data-room-type-indicator="lecture" class="inline-flex items-center rounded-full px-3 py-1.5 text-xs font-semibold border border-slate-200 bg-white text-slate-700 transition">Lecture Room</span>
                                    <span data-room-type-indicator="comlab" class="inline-flex items-center rounded-full px-3 py-1.5 text-xs font-semibold border border-slate-200 bg-white text-slate-700 transition">Comlab</span>
                                    <span data-room-type-indicator="conference" class="inline-flex items-center rounded-full px-3 py-1.5 text-xs font-semibold border border-slate-200 bg-white text-slate-700 transition">Seminar Hall</span>
                                    <span data-room-type-indicator="laboratory" class="inline-flex items-center rounded-full px-3 py-1.5 text-xs font-semibold border border-slate-200 bg-white text-slate-700 transition">Laboratory</span>
                                </div>
                            </div>
                        </div>

                        <div>
                            <label class="block text-sm font-semibold text-slate-700 mb-2">Days</label>
                            <div class="grid grid-cols-2 gap-2 rounded-xl border border-slate-200 bg-slate-50 p-3">
                                <?php foreach (['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'] as $d): ?>
                                    <label class="inline-flex items-center gap-2 rounded-lg bg-white border border-slate-200 px-2.5 py-2 text-sm text-slate-700 cursor-pointer hover:border-emerald-300">
                                        <input type="checkbox" name="day_of_week[]" value="<?php echo htmlspecialchars($d); ?>" class="add-day-checkbox h-4 w-4 rounded border-slate-300 text-emerald-600 focus:ring-emerald-500">
                                        <span><?php echo htmlspecialchars($d); ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                            <p class="mt-1 text-xs text-slate-500">Select one or more days for this class.</p>
                        </div>

                        <div>
                            <label class="block text-sm font-semibold text-slate-700 mb-1">Start time</label>
                            <input type="time" name="start_time" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm" required>
                        </div>

                        <div>
                            <label class="block text-sm font-semibold text-slate-700 mb-1">End time</label>
                            <input type="time" name="end_time" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm" required>
                        </div>

                        <div>
                            <label class="block text-sm font-semibold text-slate-700 mb-1">Start date</label>
                            <input type="date" name="start_date" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm" value="<?php echo htmlspecialchars(date('Y-m-d')); ?>">
                        </div>

                        <div>
                            <label class="block text-sm font-semibold text-slate-700 mb-1">End date</label>
                            <input type="date" name="end_date" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm" value="<?php echo htmlspecialchars(date('Y-m-d', strtotime('+17 days'))); ?>">
                        </div>

                        <div>
                            <label class="block text-sm font-semibold text-slate-700 mb-1">Max students</label>
                            <input type="number" name="max_students" min="1" value="30" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm" required>
                        </div>

                        <div>
                            <label class="block text-sm font-semibold text-slate-700 mb-1">Status</label>
                            <select name="status" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm">
                                <?php foreach ($status_options as $s): ?>
                                    <option value="<?php echo htmlspecialchars($s); ?>" <?php echo ($s === 'active') ? 'selected' : ''; ?>><?php echo htmlspecialchars(ucfirst($s)); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div>
                            <label class="block text-sm font-semibold text-slate-700 mb-1">Semester</label>
                            <input type="text" name="semester" value="1st Semester" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm" required>
                        </div>

                        <div>
                            <label class="block text-sm font-semibold text-slate-700 mb-1">Academic year</label>
                            <input type="text" name="academic_year" value="<?php echo htmlspecialchars(date('Y') . '-' . (date('Y') + 1)); ?>" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm" required>
                        </div>
                    </div>

                    <div class="mt-5 flex items-center justify-end gap-3">
                        <button type="button" class="inline-flex items-center rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-50" data-modal-close="addClassModal">Cancel</button>
                        <button type="submit" class="inline-flex items-center rounded-xl bg-emerald-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-emerald-700">Save Class</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Class Modal -->
    <div id="editClassModal" class="fixed inset-0 z-50 hidden" aria-hidden="true">
        <div class="absolute inset-0 bg-slate-900/50" data-modal-close="editClassModal"></div>
        <div class="relative mx-auto my-10 w-[92%] max-w-3xl">
            <div class="rounded-2xl bg-white shadow-xl border border-slate-200 overflow-hidden">
                <div class="px-5 py-4 border-b border-slate-200 flex items-center justify-between">
                    <div class="text-base font-semibold text-slate-900">Edit Class</div>
                    <button type="button" class="h-9 w-9 inline-flex items-center justify-center rounded-xl hover:bg-slate-100" data-modal-close="editClassModal" aria-label="Close">
                        <i class="bi bi-x-lg"></i>
                    </button>
                </div>

                <form id="editClassForm" action="process_class.php" method="POST" class="p-5">
                    <input type="hidden" name="action" value="edit">
                    <input type="hidden" name="schedule_id" id="edit_schedule_id" value="">

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-semibold text-slate-700 mb-1">Course</label>
                            <select name="course_id" id="edit_course_id" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm" required>
                                <option value="">Select course</option>
                                <?php foreach ($courses as $c): ?>
                                    <option value="<?php echo (int)$c['id']; ?>"><?php echo htmlspecialchars(($c['course_code'] ?? '') . ' — ' . ($c['course_name'] ?? '')); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div>
                            <label class="block text-sm font-semibold text-slate-700 mb-1">Year Level</label>
                            <select name="year_level" id="edit_year_level" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm" required>
                                <option value="">Select year level</option>
                                <option value="1">1st Year</option>
                                <option value="2">2nd Year</option>
                                <option value="3">3rd Year</option>
                                <option value="4">4th Year</option>
                            </select>
                        </div>

                        <div>
                            <label class="block text-sm font-semibold text-slate-700 mb-1">Subject</label>
                            <select name="subject_id" id="edit_subject_id" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm" <?php echo $subjects_enabled ? 'required' : ''; ?> <?php echo $subjects_enabled ? '' : 'disabled'; ?>>
                                <option value="">Select subject</option>
                                <?php foreach ($subjects as $s): ?>
                                    <option value="<?php echo (int)$s['id']; ?>" data-year="<?php echo htmlspecialchars((string)($s['year_level'] ?? '')); ?>"><?php echo htmlspecialchars(($s['subject_code'] ?? '') . ' — ' . ($s['subject_name'] ?? '')); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <?php if (!$subjects_enabled): ?>
                                <div class="mt-1 text-xs text-amber-700">Subjects table missing — run <span class="font-mono">fix_subjects.php</span>.</div>
                            <?php endif; ?>
                        </div>

                        <div>
                            <label class="block text-sm font-semibold text-slate-700 mb-1">Instructor</label>
                            <select name="instructor_id" id="edit_instructor_id" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm" required>
                                <option value="">Select instructor</option>
                                <?php foreach ($instructors as $ins): ?>
                                    <option value="<?php echo (int)$ins['id']; ?>"><?php echo htmlspecialchars(($ins['last_name'] ?? '') . ', ' . ($ins['first_name'] ?? '') . (!empty($ins['email']) ? (' (' . $ins['email'] . ')') : '')); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div>
                            <label class="block text-sm font-semibold text-slate-700 mb-1">Classroom</label>
                            <select name="classroom_id" id="edit_classroom_id" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm" required>
                                <option value="">Select room</option>
                                <?php foreach ($classrooms as $r): ?>
                                    <option value="<?php echo (int)$r['id']; ?>"><?php echo htmlspecialchars($r['room_number'] ?? ''); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div>
                            <label class="block text-sm font-semibold text-slate-700 mb-2">Days</label>
                            <div class="grid grid-cols-2 gap-2 rounded-xl border border-slate-200 bg-slate-50 p-3">
                                <?php foreach (['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'] as $d): ?>
                                    <label class="inline-flex items-center gap-2 rounded-lg bg-white border border-slate-200 px-2.5 py-2 text-sm text-slate-700 cursor-pointer hover:border-emerald-300">
                                        <input type="checkbox" name="day_of_week[]" value="<?php echo htmlspecialchars($d); ?>" class="edit-day-checkbox h-4 w-4 rounded border-slate-300 text-emerald-600 focus:ring-emerald-500">
                                        <span><?php echo htmlspecialchars($d); ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                            <p class="mt-1 text-xs text-slate-500">Select one or more days for this class.</p>
                        </div>

                        <div>
                            <label class="block text-sm font-semibold text-slate-700 mb-1">Start time</label>
                            <input type="time" name="start_time" id="edit_start_time" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm" required>
                        </div>

                        <div>
                            <label class="block text-sm font-semibold text-slate-700 mb-1">End time</label>
                            <input type="time" name="end_time" id="edit_end_time" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm" required>
                        </div>

                        <div>
                            <label class="block text-sm font-semibold text-slate-700 mb-1">Start date</label>
                            <input type="date" name="start_date" id="edit_start_date" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm" value="<?php echo htmlspecialchars(date('Y-m-d')); ?>">
                        </div>

                        <div>
                            <label class="block text-sm font-semibold text-slate-700 mb-1">End date</label>
                            <input type="date" name="end_date" id="edit_end_date" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm" value="<?php echo htmlspecialchars(date('Y-m-d', strtotime('+17 days'))); ?>">
                        </div>

                        <div>
                            <label class="block text-sm font-semibold text-slate-700 mb-1">Max students</label>
                            <input type="number" name="max_students" id="edit_max_students" min="1" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm" required>
                        </div>

                        <div>
                            <label class="block text-sm font-semibold text-slate-700 mb-1">Status</label>
                            <select name="status" id="edit_status" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm">
                                <?php foreach ($status_options as $s): ?>
                                    <option value="<?php echo htmlspecialchars($s); ?>"><?php echo htmlspecialchars(ucfirst($s)); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div>
                            <label class="block text-sm font-semibold text-slate-700 mb-1">Semester</label>
                            <input type="text" name="semester" id="edit_semester" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm" required>
                        </div>

                        <div>
                            <label class="block text-sm font-semibold text-slate-700 mb-1">Academic year</label>
                            <input type="text" name="academic_year" id="edit_academic_year" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm" required>
                        </div>
                    </div>

                    <div class="mt-5 flex items-center justify-end gap-3">
                        <button type="button" class="inline-flex items-center rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-50" data-modal-close="editClassModal">Cancel</button>
                        <button type="submit" class="inline-flex items-center rounded-xl bg-emerald-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-emerald-700">Update Class</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        (function () {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('sidebarOverlay');
            const btn = document.getElementById('sidebarBtn');
            const contentWrap = document.getElementById('contentWrap');
            let desktopExpanded = true;

            function isDesktop() {
                return window.innerWidth >= 1024;
            }

            function ensureSidebarCompactStyles() {
                if (document.getElementById('adminSidebarCompactStyles')) return;
                const style = document.createElement('style');
                style.id = 'adminSidebarCompactStyles';
                style.textContent = '#sidebar.sidebar-compact .leading-tight,#sidebar.sidebar-compact nav a span,#sidebar.sidebar-compact .absolute.bottom-0 a span{display:none;}#sidebar.sidebar-compact .px-4.py-4 > div:first-child{display:none;}#sidebar.sidebar-compact .h-16,#sidebar.sidebar-compact nav a,#sidebar.sidebar-compact .absolute.bottom-0 a{justify-content:center;padding-left:.5rem;padding-right:.5rem;}#sidebar.sidebar-compact nav a i{font-size:1.38rem;}';
                document.head.appendChild(style);
            }

            function setSidebarOpen(open) {
                if (!sidebar) return;

                if (isDesktop()) {
                    sidebar.classList.remove('-translate-x-full');
                    sidebar.classList.toggle('sidebar-compact', !open);
                    const desktopWidth = open ? 250 : 86;
                    sidebar.style.width = desktopWidth + 'px';
                    if (contentWrap) {
                        contentWrap.style.marginLeft = desktopWidth + 'px';
                    }
                } else {
                    sidebar.classList.remove('sidebar-compact');
                    sidebar.style.width = '250px';
                    sidebar.classList.toggle('-translate-x-full', !open);
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
                if (!sidebar) return false;
                return isDesktop()
                    ? !sidebar.classList.contains('sidebar-compact')
                    : !sidebar.classList.contains('-translate-x-full');
            }

            btn?.addEventListener('click', function () {
                const currentlyOpen = isSidebarOpen();
                if (isDesktop()) {
                    desktopExpanded = !currentlyOpen;
                    setSidebarOpen(desktopExpanded);
                    return;
                }
                setSidebarOpen(!currentlyOpen);
            });

            overlay?.addEventListener('click', function () {
                setSidebarOpen(false);
            });

            window.openSidebar = function () { setSidebarOpen(true); };
            window.closeSidebar = function () { setSidebarOpen(false); };

            ensureSidebarCompactStyles();
            window.addEventListener('resize', applyLayoutState);
            applyLayoutState();

            function openModal(id) {
                const el = document.getElementById(id);
                if (!el) return;
                el.classList.remove('hidden');
                el.setAttribute('aria-hidden', 'false');
                document.body.classList.add('overflow-hidden');
            }
            function closeModal(id) {
                const el = document.getElementById(id);
                if (!el) return;
                el.classList.add('hidden');
                el.setAttribute('aria-hidden', 'true');
                document.body.classList.remove('overflow-hidden');
            }

            const addClassForm = document.getElementById('addClassForm');
            const editClassForm = document.getElementById('editClassForm');
            const addDayCheckboxes = Array.from(document.querySelectorAll('.add-day-checkbox'));
            const editDayCheckboxes = Array.from(document.querySelectorAll('.edit-day-checkbox'));

            document.getElementById('addClassBtn')?.addEventListener('click', function () {
                openModal('addClassModal');
            });
            document.querySelectorAll('[data-modal-close]')?.forEach(function (btn) {
                btn.addEventListener('click', function () {
                    const target = btn.getAttribute('data-modal-close');
                    if (target) closeModal(target);
                });
            });

            addClassForm?.addEventListener('submit', function (event) {
                const selectedCount = addDayCheckboxes.filter(function (cb) { return cb.checked; }).length;
                if (selectedCount === 0) {
                    event.preventDefault();
                    alert('Please select at least one day.');
                }
            });

            editClassForm?.addEventListener('submit', function (event) {
                const selectedCount = editDayCheckboxes.filter(function (cb) { return cb.checked; }).length;
                if (selectedCount === 0) {
                    event.preventDefault();
                    alert('Please select at least one day.');
                }
            });

            function filterSubjectsByYear(selectEl, yearLevel) {
                if (!selectEl) return;
                const year = String(yearLevel || '');
                const opts = Array.from(selectEl.querySelectorAll('option'));
                opts.forEach(function (opt) {
                    const y = opt.getAttribute('data-year');
                    if (!y) return; // keep placeholder etc.
                    opt.hidden = (year !== '' && y !== year);
                });
                // If currently selected option is hidden, clear selection
                const selected = selectEl.options[selectEl.selectedIndex];
                if (selected && selected.hidden) {
                    selectEl.value = '';
                }
            }

            const addYear = document.getElementById('add_year_level');
            const addSubj = document.getElementById('add_subject_id');
            const addClassroom = document.getElementById('add_classroom_id');
            const addRoomTypeText = document.getElementById('addRoomTypeText');
            const addRoomTypePills = Array.from(document.querySelectorAll('[data-room-type-indicator]'));

            function normalizeRoomType(type) {
                const t = String(type || '').toLowerCase().trim();
                if (t === 'computer') return 'comlab';
                return t;
            }

            function roomTypeLabel(type) {
                if (type === 'lecture') return 'Lecture Room';
                if (type === 'comlab') return 'Comlab';
                if (type === 'conference') return 'Seminar Hall';
                if (type === 'laboratory') return 'Laboratory';
                return 'Unknown';
            }

            function updateAddRoomTypeIndicator() {
                const selectedOption = addClassroom?.options?.[addClassroom.selectedIndex] || null;
                const selectedType = normalizeRoomType(selectedOption?.getAttribute('data-room-type') || '');

                addRoomTypePills.forEach(function (pill) {
                    const pillType = pill.getAttribute('data-room-type-indicator') || '';
                    const isActive = selectedType !== '' && pillType === selectedType;
                    pill.classList.toggle('bg-emerald-600', isActive);
                    pill.classList.toggle('text-white', isActive);
                    pill.classList.toggle('border-emerald-600', isActive);
                    pill.classList.toggle('bg-white', !isActive);
                    pill.classList.toggle('text-slate-700', !isActive);
                    pill.classList.toggle('border-slate-200', !isActive);
                });

                if (addRoomTypeText) {
                    addRoomTypeText.textContent = selectedType === ''
                        ? 'No room selected'
                        : ('Selected type: ' + roomTypeLabel(selectedType));
                }
            }

            addYear?.addEventListener('change', function () {
                filterSubjectsByYear(addSubj, addYear.value);
            });
            addClassroom?.addEventListener('change', updateAddRoomTypeIndicator);
            updateAddRoomTypeIndicator();

            const editYear = document.getElementById('edit_year_level');
            const editSubj = document.getElementById('edit_subject_id');
            editYear?.addEventListener('change', function () {
                filterSubjectsByYear(editSubj, editYear.value);
            });

            window.editClass = function (classData) {
                document.getElementById('edit_schedule_id').value = classData.id;
                if (document.getElementById('edit_course_id')) {
                    document.getElementById('edit_course_id').value = classData.course_id || '';
                }
                if (document.getElementById('edit_year_level')) {
                    document.getElementById('edit_year_level').value = classData.year_level || '';
                    filterSubjectsByYear(editSubj, document.getElementById('edit_year_level').value);
                }
                if (document.getElementById('edit_subject_id')) {
                    document.getElementById('edit_subject_id').value = classData.subject_id || '';
                }
                document.getElementById('edit_instructor_id').value = classData.instructor_id || '';
                document.getElementById('edit_classroom_id').value = classData.classroom_id || '';
                editDayCheckboxes.forEach(function (cb) { cb.checked = false; });
                const rawDays = String(classData.combined_days_label || classData.day_of_week || '');
                const selectedDays = rawDays.split(',').map(function (d) { return d.trim(); }).filter(Boolean);
                editDayCheckboxes.forEach(function (cb) {
                    if (selectedDays.includes(cb.value)) {
                        cb.checked = true;
                    }
                });
                document.getElementById('edit_start_time').value = classData.start_time || '';
                document.getElementById('edit_end_time').value = classData.end_time || '';
                document.getElementById('edit_start_date').value = classData.start_date || '<?php echo htmlspecialchars(date('Y-m-d')); ?>';
                document.getElementById('edit_end_date').value = classData.end_date || '<?php echo htmlspecialchars(date('Y-m-d', strtotime('+17 days'))); ?>';
                document.getElementById('edit_max_students').value = classData.max_students || 30;
                document.getElementById('edit_status').value = (classData.status || 'active');
                document.getElementById('edit_semester').value = classData.semester || '1st Semester';
                document.getElementById('edit_academic_year').value = classData.academic_year || '<?php echo htmlspecialchars(date('Y') . '-' . (date('Y') + 1)); ?>';
                openModal('editClassModal');
            }

            window.deleteClass = function (scheduleId) {
                const hard = confirm('Are you sure you want to delete this class? This action cannot be undone.');
                if (hard) {
                    const force = confirm('Use FORCE delete?\n\nOK: Remove this class and all its enrollments.\nCancel: Regular delete (will fail if active enrollments exist).');

                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.action = 'process_class.php';

                    const actionInput = document.createElement('input');
                    actionInput.type = 'hidden';
                    actionInput.name = 'action';
                    actionInput.value = force ? 'force_delete' : 'delete';

                    const idInput = document.createElement('input');
                    idInput.type = 'hidden';
                    idInput.name = 'schedule_id';
                    idInput.value = scheduleId;

                    form.appendChild(actionInput);
                    form.appendChild(idInput);
                    document.body.appendChild(form);
                    form.submit();
                }
            }

            // Expose a force-delete helper (can be wired to a UI button if desired)
            window.forceDeleteClass = function (scheduleId) {
                const ok = confirm('Force delete will remove ALL enrollments for this class, then delete it. Continue?');
                if (!ok) return;

                const form = document.createElement('form');
                form.method = 'POST';
                form.action = 'process_class.php';

                const actionInput = document.createElement('input');
                actionInput.type = 'hidden';
                actionInput.name = 'action';
                actionInput.value = 'force_delete';

                const idInput = document.createElement('input');
                idInput.type = 'hidden';
                idInput.name = 'schedule_id';
                idInput.value = scheduleId;

                form.appendChild(actionInput);
                form.appendChild(idInput);
                document.body.appendChild(form);
                form.submit();
            }

            const searchInput = document.getElementById('classSearch');
            const rows = Array.from(document.querySelectorAll('.class-row'));

            function applySearch() {
                const q = (searchInput?.value || '').trim().toLowerCase();
                rows.forEach(function (row) {
                    const hay = row.getAttribute('data-search') || '';
                    row.style.display = (!q || hay.includes(q)) ? '' : 'none';
                });
            }

            searchInput?.addEventListener('input', applySearch);
            applySearch();
        })();

        (function () {
            const btn = document.getElementById('notifBtn');
            const menu = document.getElementById('notifMenu');
            const dot = document.getElementById('notifDot');
            if (!btn || !menu) return;

            function isOpen() {
                return !menu.classList.contains('hidden');
            }

            const markBtn = document.getElementById('notifMarkRead');
            const delBtn = document.getElementById('notifDelete');

            async function postAction(action) {
                try {
                    const res = await fetch('notifications_seen.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: 'action=' + encodeURIComponent(action)
                    });
                    return res.ok;
                } catch (_) {
                    return false;
                }
            }

            function openMenu() {
                menu.classList.remove('hidden');
                btn.setAttribute('aria-expanded', 'true');
            }

            function closeMenu() {
                menu.classList.add('hidden');
                btn.setAttribute('aria-expanded', 'false');
            }

            function toggleMenu() {
                if (isOpen()) closeMenu();
                else openMenu();
            }

            btn.addEventListener('click', function (e) {
                e.preventDefault();
                e.stopPropagation();
                toggleMenu();
            });

            markBtn?.addEventListener('click', async function (e) {
                e.preventDefault();
                e.stopPropagation();
                const ok = await postAction('seen');
                if (ok) {
                    dot?.classList.add('hidden');
                    closeMenu();
                    location.reload();
                }
            });

            delBtn?.addEventListener('click', async function (e) {
                e.preventDefault();
                e.stopPropagation();
                const ok = await postAction('delete');
                if (ok) {
                    dot?.classList.add('hidden');
                    closeMenu();
                    location.reload();
                }
            });

            document.addEventListener('click', function (e) {
                if (!isOpen()) return;
                const target = e.target;
                if (!(target instanceof Element)) return;
                if (menu.contains(target) || btn.contains(target)) return;
                closeMenu();
            });

            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape') closeMenu();
            });
        })();
    </script>
</body>
</html> 