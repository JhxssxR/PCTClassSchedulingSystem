<?php
session_start();
require_once '../config/database.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'student') {
    header('Location: ../auth/login.php?role=student');
    exit();
}

require_once __DIR__ . '/notifications_data.php';

function student_day_short(string $day): string {
    $map = [
        'Monday' => 'Mon',
        'Tuesday' => 'Tue',
        'Wednesday' => 'Wed',
        'Thursday' => 'Thu',
        'Friday' => 'Fri',
        'Saturday' => 'Sat',
    ];
    return $map[$day] ?? $day;
}

function student_time_display(string $time): string {
    if ($time === '') {
        return '';
    }
    $ts = strtotime($time);
    if ($ts === false) {
        return $time;
    }
    return date('g:i A', $ts);
}

function student_time_add_minutes(string $time, int $minutes): string {
    if ($time === '') {
        return '';
    }
    $ts = strtotime('1970-01-01 ' . $time);
    if ($ts === false) {
        return '';
    }
    return date('H:i:s', $ts + ($minutes * 60));
}

function student_time_range_display(string $start, string $end): string {
    if ($start === '') {
        return '-';
    }
    $end_time = $end !== '' ? $end : student_time_add_minutes($start, 120);
    return student_time_display($start) . ' - ' . student_time_display($end_time);
}

function student_initials(string $first, string $last, string $fallback = 'S'): string {
    $a = strtoupper(substr(trim($first), 0, 1));
    $b = strtoupper(substr(trim($last), 0, 1));
    $initials = trim($a . $b);
    if ($initials === '') {
        $initials = strtoupper(substr(trim($fallback), 0, 2));
    }
    return $initials !== '' ? $initials : 'S';
}

$student = [];
$schedule = [];
$week_days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
$schedule_by_day = [];
foreach ($week_days as $day_name) {
    $schedule_by_day[$day_name] = [];
}

$semester_label = '2nd Sem 2025-2026';
$college_label = 'Information of Technology Education';
$enrollment_label = 'Regularly Enrolled';
$assigned_instructor = 'TBA';

try {
    $stmt = $conn->prepare("SELECT id, student_id, first_name, last_name, username, email, year_level FROM users WHERE id = ? AND role = 'student' LIMIT 1");
    $stmt->execute([$_SESSION['user_id']]);
    $student = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $schedule_cols = [];
    try {
        $stmt_cols = $conn->query('DESCRIBE schedules');
        foreach (($stmt_cols ? $stmt_cols->fetchAll(PDO::FETCH_ASSOC) : []) as $col_row) {
            $schedule_cols[(string)($col_row['Field'] ?? '')] = true;
        }
    } catch (Throwable $e) {
        $schedule_cols = [];
    }

    $course_cols = [];
    try {
        $course_stmt = $conn->query('DESCRIBE courses');
        foreach (($course_stmt ? $course_stmt->fetchAll(PDO::FETCH_ASSOC) : []) as $col_row) {
            $course_cols[(string)($col_row['Field'] ?? '')] = true;
        }
    } catch (Throwable $e) {
        $course_cols = [];
    }

    $subjects_table_exists = false;
    try {
        $subjects_exists_stmt = $conn->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'subjects'");
        $subjects_exists_stmt->execute();
        $subjects_table_exists = ((int)$subjects_exists_stmt->fetchColumn() > 0);
    } catch (Throwable $e) {
        $subjects_table_exists = false;
    }

    $has_end_time = isset($schedule_cols['end_time']);
    $has_duration_minutes = isset($schedule_cols['duration_minutes']);
    $has_units = isset($course_cols['units']);
    $subjects_enabled = ($subjects_table_exists && isset($schedule_cols['subject_id']));

    if ($has_end_time) {
        $end_expr = 's.end_time';
    } elseif ($has_duration_minutes) {
        $end_expr = 'ADDTIME(s.start_time, SEC_TO_TIME(COALESCE(s.duration_minutes, 120) * 60))';
    } else {
        $end_expr = 'ADDTIME(s.start_time, SEC_TO_TIME(120 * 60))';
    }

    $units_expr = $has_units ? 'COALESCE(c.units, 3)' : '3';
    $subject_code_expr = $subjects_enabled
        ? "COALESCE(subj.subject_code, 'N/A')"
        : "COALESCE(c.course_code, 'N/A')";
    $subject_name_expr = $subjects_enabled
        ? "COALESCE(subj.subject_name, 'Untitled Subject')"
        : "COALESCE(c.course_name, 'Untitled Subject')";
    $subject_join_sql = $subjects_enabled
        ? 'LEFT JOIN subjects subj ON subj.id = s.subject_id'
        : '';

    $sql = "
        SELECT
            s.id AS schedule_id,
            {$subject_code_expr} AS subject_code,
            {$subject_name_expr} AS subject_name,
            s.day_of_week AS schedule_day,
            TIME_FORMAT(s.start_time, '%H:%i:%s') AS start_time,
            TIME_FORMAT({$end_expr}, '%H:%i:%s') AS end_time,
            {$units_expr} AS units,
            COALESCE(cr.room_number, 'TBA') AS room_number,
            TRIM(CONCAT(COALESCE(i.first_name, ''), ' ', COALESCE(i.last_name, ''))) AS instructor_name
        FROM enrollments e
        JOIN schedules s ON e.schedule_id = s.id
        LEFT JOIN courses c ON s.course_id = c.id
        {$subject_join_sql}
        LEFT JOIN classrooms cr ON s.classroom_id = cr.id
        LEFT JOIN users i ON s.instructor_id = i.id
        WHERE e.student_id = :student_id
          AND e.status IN ('approved', 'enrolled')
          AND s.status = 'active'
        ORDER BY FIELD(s.day_of_week, 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'), s.start_time
    ";

    $stmt = $conn->prepare($sql);
    $stmt->execute(['student_id' => (int)$_SESSION['user_id']]);
    $schedule = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    foreach ($schedule as $row) {
        $d = (string)($row['schedule_day'] ?? '');
        if (!isset($schedule_by_day[$d])) {
            $schedule_by_day[$d] = [];
        }
        $schedule_by_day[$d][] = $row;

        if ($assigned_instructor === 'TBA') {
            $candidate = trim((string)($row['instructor_name'] ?? ''));
            if ($candidate !== '') {
                $assigned_instructor = $candidate;
            }
        }
    }
} catch (Throwable $e) {
    error_log('Student dashboard error: ' . $e->getMessage());
    $student = [];
    $schedule = [];
}

$first_name = (string)($student['first_name'] ?? ($_SESSION['first_name'] ?? 'Student'));
$last_name = (string)($student['last_name'] ?? ($_SESSION['last_name'] ?? ''));
$username = (string)($student['username'] ?? ($_SESSION['username'] ?? 'student'));
$full_name = trim($first_name . ' ' . $last_name);
if ($full_name === '') {
    $full_name = 'Student';
}

$student_id_label = trim((string)($student['student_id'] ?? ''));
if ($student_id_label === '') {
    $student_id_label = 'ID-' . str_pad((string)((int)($_SESSION['user_id'] ?? 0)), 4, '0', STR_PAD_LEFT);
}

$year_level_raw = (string)($student['year_level'] ?? '2');
if (is_numeric($year_level_raw)) {
    $year_level_label = 'Year ' . (int)$year_level_raw;
} else {
    $year_level_label = $year_level_raw !== '' ? $year_level_raw : 'Year 2';
}

$current_hour = (int)date('G');
if ($current_hour < 12) {
    $greeting = 'Good morning';
} elseif ($current_hour < 18) {
    $greeting = 'Good afternoon';
} else {
    $greeting = 'Good evening';
}

$enrolled_classes = count($schedule);
$total_units = 0;
foreach ($schedule as $row) {
    $total_units += (int)($row['units'] ?? 0);
}

$today_name = date('l');
$today_schedule = $schedule_by_day[$today_name] ?? [];
$today_date_label = date('l, F j, Y');

$week_schedule = [];
foreach ($week_days as $day_name) {
    foreach (($schedule_by_day[$day_name] ?? []) as $row) {
        $week_schedule[] = $row;
    }
}

$subject_map = [];
foreach ($schedule as $row) {
    $key = strtolower((string)($row['subject_code'] ?? '') . '|' . (string)($row['subject_name'] ?? ''));
    if (!isset($subject_map[$key])) {
        $subject_map[$key] = [
            'subject_code' => (string)($row['subject_code'] ?? 'N/A'),
            'subject_name' => (string)($row['subject_name'] ?? 'Untitled Subject'),
            'units' => (int)($row['units'] ?? 0),
            'sessions' => [],
            'room_number' => (string)($row['room_number'] ?? 'TBA'),
            'instructor_name' => (string)($row['instructor_name'] ?? 'TBA'),
        ];
    }

    $subject_map[$key]['sessions'][] = student_day_short((string)($row['schedule_day'] ?? '')) . ' ' . student_time_range_display((string)($row['start_time'] ?? ''), (string)($row['end_time'] ?? ''));
}

$subjects = array_values($subject_map);

$subject_palettes = [
    ['badge' => 'bg-emerald-500', 'soft' => 'bg-emerald-50 text-emerald-700'],
    ['badge' => 'bg-indigo-500', 'soft' => 'bg-indigo-50 text-indigo-700'],
    ['badge' => 'bg-amber-500', 'soft' => 'bg-amber-50 text-amber-700'],
    ['badge' => 'bg-pink-500', 'soft' => 'bg-pink-50 text-pink-700'],
];

$user_initials = student_initials($first_name, $last_name, $username);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Dashboard - PCT</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
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
<body class="bg-slate-100 text-slate-800 antialiased">
    <div class="min-h-screen">
        <aside id="studentSidebar" class="sidebar-shell fixed inset-y-0 left-0 z-40 -translate-x-full lg:translate-x-0 transition-transform duration-300 bg-gradient-to-b from-emerald-950 via-emerald-900 to-emerald-950 text-emerald-50 border-r border-emerald-800/60">
            <div class="sidebar-brand-row h-20 px-5 flex items-center gap-3 border-b border-emerald-800/70">
                <img src="../pctlogo.png" alt="PCT Logo" class="h-10 w-10 rounded-full object-contain bg-white/10" />
                <div class="sidebar-brand-copy">
                    <div class="text-sm font-semibold leading-tight">PCT Student</div>
                    <div class="text-xs text-emerald-200/80">Student Portal</div>
                </div>
            </div>

            <div class="px-4 py-4">
                <div class="sidebar-nav-title px-2 text-[11px] tracking-widest text-emerald-200/60">NAVIGATION</div>
                <nav class="mt-3 space-y-1.5">
                    <a href="dashboard.php" class="sidebar-nav-link flex items-center justify-between rounded-xl bg-emerald-700/45 px-3 py-2.5 text-sm font-medium text-emerald-50">
                        <span class="sidebar-nav-content inline-flex items-center gap-2"><i class="bi bi-grid"></i><span class="sidebar-label">Dashboard</span></span>
                        <span class="sidebar-active-dot h-1.5 w-1.5 rounded-full bg-emerald-200"></span>
                    </a>
                    <a href="my_schedule.php" class="sidebar-nav-link flex items-center gap-2 rounded-xl px-3 py-2.5 text-sm text-emerald-100/90 hover:bg-emerald-800/50 hover:text-white"><span class="sidebar-nav-content inline-flex items-center gap-2"><i class="bi bi-calendar3"></i><span class="sidebar-label">My Schedule</span></span></a>
                    <a href="my_subjects.php" class="sidebar-nav-link flex items-center gap-2 rounded-xl px-3 py-2.5 text-sm text-emerald-100/90 hover:bg-emerald-800/50 hover:text-white"><span class="sidebar-nav-content inline-flex items-center gap-2"><i class="bi bi-book"></i><span class="sidebar-label">My Subjects</span></span></a>
                </nav>
            </div>

            <div class="absolute inset-x-0 bottom-0 p-4 border-t border-emerald-800/70">
                <a href="../auth/logout.php" class="sidebar-logout-link inline-flex items-center gap-2 rounded-xl px-3 py-2 text-sm font-semibold text-rose-200 hover:bg-rose-500/10 hover:text-rose-100">
                    <i class="bi bi-box-arrow-left"></i>
                    <span class="sidebar-label">Logout</span>
                </a>
            </div>
        </aside>

        <div id="sidebarOverlay" class="fixed inset-0 z-30 hidden bg-slate-900/45 lg:hidden"></div>

        <div id="contentWrap" class="min-h-screen transition-all duration-300">
            <header class="sticky top-0 z-20 h-16 border-b border-slate-200 bg-white/90 backdrop-blur">
                <div class="h-full px-4 sm:px-6 flex items-center justify-between">
                    <div class="flex items-center gap-3 text-sm text-slate-500">
                        <button id="sidebarBtn" type="button" class="inline-flex h-10 w-10 items-center justify-center rounded-xl border border-slate-200 text-slate-600 hover:bg-slate-50" aria-label="Toggle menu">
                            <i class="bi bi-list text-lg"></i>
                        </button>
                        <span class="inline-flex items-center gap-2">
                            <i class="bi bi-eye text-emerald-500"></i>
                            Student
                            <span class="text-slate-300">/</span>
                            <span class="text-slate-600">Dashboard</span>
                        </span>
                    </div>

                    <div class="flex items-center gap-3">
                        <div class="relative">
                            <button id="notifBtn" type="button" class="relative inline-flex h-10 w-10 items-center justify-center rounded-xl border border-slate-200 bg-white text-slate-600 hover:bg-slate-50" aria-haspopup="menu" aria-expanded="false" aria-controls="notifMenu">
                                <i class="bi bi-bell"></i>
                                <span id="notifDot" class="absolute -right-1 -top-1 min-w-5 h-5 px-1 rounded-full bg-rose-500 text-white text-[10px] font-bold flex items-center justify-center ring-2 ring-white <?php echo (($notif_has_new ?? false) ? '' : 'hidden'); ?>">
                                    <?php echo htmlspecialchars($notif_badge_label ?? ''); ?>
                                </span>
                            </button>

                            <div id="notifMenu" class="absolute right-0 mt-2 w-80 hidden" role="menu" aria-label="Notifications">
                                <div class="rounded-2xl border border-slate-200 bg-white shadow-xl overflow-hidden">
                                    <div class="px-4 py-3 border-b border-slate-200 flex items-center justify-between">
                                        <div class="text-sm font-semibold text-slate-900">Notifications</div>
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
                                                <?php foreach ($notif_items as $it): ?>
                                                    <a href="<?php echo htmlspecialchars($it['href'] ?? '#'); ?>" class="block rounded-xl border border-slate-200 bg-white p-3 hover:bg-slate-50">
                                                        <div class="flex items-start gap-3">
                                                            <div class="inline-flex h-8 w-8 items-center justify-center rounded-lg bg-slate-100 text-slate-600">
                                                                <i class="bi <?php echo htmlspecialchars($it['icon'] ?? 'bi-bell'); ?>"></i>
                                                            </div>
                                                            <div class="min-w-0">
                                                                <div class="text-xs font-semibold text-slate-900 truncate"><?php echo htmlspecialchars($it['title'] ?? 'Notification'); ?></div>
                                                                <div class="text-xs text-slate-500 line-clamp-2"><?php echo htmlspecialchars($it['subtitle'] ?? ''); ?></div>
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

                        <div class="hidden sm:flex items-center gap-3 text-slate-700">
                            <div class="h-8 w-8 rounded-md bg-emerald-600 text-white text-xs font-semibold flex items-center justify-center"><?php echo htmlspecialchars($user_initials); ?></div>
                            <div class="text-sm font-semibold"><?php echo htmlspecialchars($full_name); ?></div>
                        </div>
                    </div>
                </div>
            </header>

            <main class="px-4 sm:px-6 py-5 space-y-4">
                <section class="rounded-3xl bg-gradient-to-r from-emerald-950 via-emerald-900 to-emerald-700 text-white shadow-sm border border-emerald-800/50">
                    <div class="px-5 sm:px-6 py-5">
                        <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                            <div class="flex items-start gap-4">
                                <div class="h-14 w-14 rounded-2xl bg-emerald-500/30 border border-emerald-300/40 text-2xl font-semibold flex items-center justify-center">
                                    <?php echo htmlspecialchars($user_initials); ?>
                                </div>
                                <div>
                                    <div class="text-[11px] uppercase tracking-[0.2em] text-emerald-200"><?php echo htmlspecialchars($greeting); ?></div>
                                    <h1 class="text-3xl font-semibold leading-tight"><?php echo htmlspecialchars($full_name); ?></h1>
                                    <div class="mt-1 text-sm text-emerald-100/90">Student ID: <?php echo htmlspecialchars($student_id_label); ?> &nbsp;&middot;&nbsp; DIT &nbsp;&middot;&nbsp; <?php echo htmlspecialchars($year_level_label); ?></div>
                                </div>
                            </div>

                            <div class="flex flex-wrap items-center gap-2">
                                <a href="my_schedule.php" class="inline-flex items-center gap-2 rounded-xl border border-emerald-300/30 bg-white/10 px-3 py-2 text-xs font-semibold text-emerald-50 hover:bg-white/20">
                                    <i class="bi bi-calendar3"></i>
                                    My Schedule
                                </a>
                                <a href="my_subjects.php" class="inline-flex items-center gap-2 rounded-xl border border-emerald-300/30 bg-white/10 px-3 py-2 text-xs font-semibold text-emerald-50 hover:bg-white/20">
                                    <i class="bi bi-book"></i>
                                    My Subjects
                                </a>
                            </div>
                        </div>
                    </div>

                    <div class="border-t border-emerald-700/60 px-5 sm:px-6 py-3 grid grid-cols-2 lg:grid-cols-4 gap-3 text-[11px] text-emerald-100/90">
                        <div>Semester: <span class="text-white"><?php echo htmlspecialchars($semester_label); ?></span></div>
                        <div>College: <span class="text-white"><?php echo htmlspecialchars($college_label); ?></span></div>
                        <div>Status: <span class="text-white"><?php echo htmlspecialchars($enrollment_label); ?></span></div>
                        <div>Instructor: <span class="text-white"><?php echo htmlspecialchars($assigned_instructor); ?></span></div>
                    </div>
                </section>

                <section class="grid grid-cols-1 xl:grid-cols-2 gap-3">
                    <div class="rounded-2xl border border-slate-200 bg-white shadow-sm p-4 transition duration-200 hover:-translate-y-0.5 hover:shadow-md hover:border-slate-300">
                        <div class="flex items-center justify-between">
                            <span class="inline-flex h-8 w-8 items-center justify-center rounded-xl bg-emerald-50 text-emerald-600"><i class="bi bi-grid"></i></span>
                            <span class="inline-flex items-center rounded-full bg-emerald-50 px-2.5 py-1 text-[11px] font-semibold text-emerald-600 ring-1 ring-inset ring-emerald-200"><i class="bi bi-arrow-up-right mr-1"></i>Active</span>
                        </div>
                        <div class="mt-3 text-4xl leading-none font-semibold text-slate-800"><?php echo (int)$enrolled_classes; ?></div>
                        <div class="mt-2 text-sm text-slate-500">Enrolled Classes</div>
                        <div class="text-xs text-slate-400">This semester</div>
                    </div>

                    <div class="rounded-2xl border border-slate-200 bg-white shadow-sm p-4 transition duration-200 hover:-translate-y-0.5 hover:shadow-md hover:border-slate-300">
                        <div class="flex items-center justify-between">
                            <span class="inline-flex h-8 w-8 items-center justify-center rounded-xl bg-indigo-50 text-indigo-600"><i class="bi bi-bullseye"></i></span>
                            <span class="inline-flex items-center rounded-full bg-indigo-50 px-2.5 py-1 text-[11px] font-semibold text-indigo-600 ring-1 ring-inset ring-indigo-200"><i class="bi bi-arrow-up-right mr-1"></i>+ <?php echo (int)$total_units; ?> units</span>
                        </div>
                        <div class="mt-3 text-4xl leading-none font-semibold text-slate-800"><?php echo (int)$total_units; ?></div>
                        <div class="mt-2 text-sm text-slate-500">Total Units</div>
                        <div class="text-xs text-slate-400">Enrolled this sem</div>
                    </div>
                </section>

                <section id="todayClassesPanel" class="rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden">
                    <div class="px-4 sm:px-5 py-4 border-b border-slate-100 flex items-start justify-between gap-3">
                        <div>
                            <div class="text-lg font-semibold text-slate-700">Today's Classes</div>
                            <div class="text-xs text-slate-400"><?php echo htmlspecialchars($today_date_label); ?></div>
                        </div>
                        <div class="inline-flex rounded-xl bg-slate-100 p-1">
                            <button id="todayTabBtn" type="button" class="rounded-lg bg-emerald-600 text-white px-3 py-1.5 text-xs font-semibold">Today</button>
                            <button id="weekTabBtn" type="button" class="rounded-lg text-slate-500 px-3 py-1.5 text-xs font-semibold hover:bg-white">Week</button>
                        </div>
                    </div>

                    <div id="todayPanel" class="px-4 sm:px-5 py-3">
                        <?php if (empty($today_schedule)): ?>
                            <div class="rounded-xl border border-slate-200 bg-slate-50 px-4 py-4 text-sm text-slate-500">No classes scheduled for today.</div>
                        <?php else: ?>
                            <div class="space-y-2">
                                <?php foreach ($today_schedule as $idx => $cls): ?>
                                    <?php
                                        $start = (string)($cls['start_time'] ?? '');
                                        $end = (string)($cls['end_time'] ?? '');
                                        $start_ts = strtotime(date('Y-m-d') . ' ' . $start);
                                        $end_ts = strtotime(date('Y-m-d') . ' ' . ($end !== '' ? $end : student_time_add_minutes($start, 120)));
                                        $now_ts = time();
                                        $is_ongoing = ($start_ts !== false && $end_ts !== false && $now_ts >= $start_ts && $now_ts <= $end_ts);
                                        $badge_classes = $is_ongoing
                                            ? 'bg-emerald-50 text-emerald-600 ring-1 ring-inset ring-emerald-200'
                                            : 'bg-slate-100 text-slate-600 ring-1 ring-inset ring-slate-200';
                                        $badge_text = $is_ongoing ? 'Ongoing' : 'Upcoming';
                                    ?>
                                    <div class="grid grid-cols-[88px,1fr,auto] gap-3 items-center rounded-xl px-2 py-2 hover:bg-slate-50">
                                        <div class="text-xs font-semibold text-slate-400"><?php echo htmlspecialchars(student_time_display($start)); ?></div>
                                        <div class="min-w-0">
                                            <div class="flex items-center gap-2">
                                                <span class="inline-flex h-6 min-w-6 items-center justify-center rounded-full px-2 text-[10px] font-semibold <?php echo htmlspecialchars($subject_palettes[$idx % count($subject_palettes)]['soft']); ?>"><?php echo htmlspecialchars((string)($cls['subject_code'] ?? '---')); ?></span>
                                                <div class="text-sm font-medium text-slate-700 truncate"><?php echo htmlspecialchars((string)($cls['subject_name'] ?? 'Class')); ?></div>
                                            </div>
                                            <div class="mt-0.5 text-[11px] text-slate-400 truncate"><i class="bi bi-geo-alt"></i> <?php echo htmlspecialchars((string)($cls['room_number'] ?? 'TBA')); ?> &nbsp; <i class="bi bi-person"></i> <?php echo htmlspecialchars((string)($cls['instructor_name'] ?? 'TBA')); ?></div>
                                        </div>
                                        <span class="inline-flex items-center rounded-full px-3 py-1 text-[11px] font-semibold <?php echo htmlspecialchars($badge_classes); ?>"><?php echo htmlspecialchars($badge_text); ?></span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <div class="mt-2 flex items-center justify-between text-[11px] text-slate-400">
                                <span><?php echo count($today_schedule); ?> class(es) scheduled today</span>
                                <a href="my_schedule.php" class="font-semibold text-emerald-600 hover:text-emerald-700">Full schedule <i class="bi bi-chevron-right text-[10px]"></i></a>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div id="weekPanel" class="hidden px-4 sm:px-5 py-3">
                        <?php if (empty($week_schedule)): ?>
                            <div class="rounded-xl border border-slate-200 bg-slate-50 px-4 py-4 text-sm text-slate-500">No weekly schedule available.</div>
                        <?php else: ?>
                            <div class="space-y-2">
                                <?php foreach (array_slice($week_schedule, 0, 8) as $idx => $cls): ?>
                                    <div class="grid grid-cols-[80px,1fr,auto] gap-3 items-center rounded-xl px-2 py-2 hover:bg-slate-50">
                                        <div class="text-xs font-semibold text-slate-400"><?php echo htmlspecialchars(student_day_short((string)($cls['schedule_day'] ?? ''))); ?></div>
                                        <div class="min-w-0">
                                            <div class="text-sm font-medium text-slate-700 truncate"><?php echo htmlspecialchars((string)($cls['subject_name'] ?? 'Class')); ?></div>
                                            <div class="text-[11px] text-slate-400 truncate"><?php echo htmlspecialchars(student_time_range_display((string)($cls['start_time'] ?? ''), (string)($cls['end_time'] ?? ''))); ?> &nbsp; <i class="bi bi-geo-alt"></i> <?php echo htmlspecialchars((string)($cls['room_number'] ?? 'TBA')); ?></div>
                                        </div>
                                        <span class="inline-flex items-center rounded-full bg-slate-100 text-slate-600 px-3 py-1 text-[11px] font-semibold"><?php echo htmlspecialchars((string)($cls['subject_code'] ?? '---')); ?></span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </section>

                <section>
                    <div id="subjectsPanel" class="rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden">
                        <div class="px-4 sm:px-5 py-4 border-b border-slate-100 flex items-center justify-between gap-2">
                            <div>
                                <div class="text-lg font-semibold text-slate-700">Enrolled Subjects</div>
                                <div class="text-xs text-slate-400"><?php echo htmlspecialchars($semester_label); ?></div>
                            </div>
                            <a href="my_subjects.php" class="inline-flex items-center gap-1 rounded-xl bg-emerald-50 px-3 py-1.5 text-[11px] font-semibold text-emerald-600 ring-1 ring-inset ring-emerald-200">View all <i class="bi bi-chevron-right text-[10px]"></i></a>
                        </div>
                        <div class="p-4 sm:p-5 space-y-3">
                            <?php if (empty($subjects)): ?>
                                <div class="rounded-xl border border-slate-200 bg-slate-50 px-4 py-4 text-sm text-slate-500">No enrolled subjects yet.</div>
                            <?php else: ?>
                                <?php foreach ($subjects as $idx => $subject): ?>
                                    <?php $palette = $subject_palettes[$idx % count($subject_palettes)]; ?>
                                    <div class="flex items-center gap-3">
                                        <div class="h-8 min-w-8 px-2 rounded-xl text-white text-[11px] font-semibold flex items-center justify-center <?php echo htmlspecialchars($palette['badge']); ?>"><?php echo htmlspecialchars((string)($subject['subject_code'] ?? '---')); ?></div>
                                        <div class="min-w-0 flex-1">
                                            <div class="text-sm font-medium text-slate-700 truncate"><?php echo htmlspecialchars((string)($subject['subject_name'] ?? 'Subject')); ?></div>
                                            <div class="text-[11px] text-slate-400 truncate"><?php echo htmlspecialchars(implode(' / ', array_slice($subject['sessions'], 0, 2))); ?> &nbsp; <i class="bi bi-geo-alt"></i> <?php echo htmlspecialchars((string)($subject['room_number'] ?? 'TBA')); ?> &nbsp; <i class="bi bi-person"></i> <?php echo htmlspecialchars((string)($subject['instructor_name'] ?? 'TBA')); ?></div>
                                        </div>
                                        <div class="flex items-center gap-2">
                                            <span class="rounded-full bg-slate-100 px-2.5 py-1 text-[10px] font-semibold text-slate-500 ring-1 ring-inset ring-slate-200"><?php echo (int)($subject['units'] ?? 0); ?> units</span>
                                            <span class="rounded-full bg-emerald-50 px-2.5 py-1 text-[10px] font-semibold text-emerald-600 ring-1 ring-inset ring-emerald-200">Ongoing</span>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </section>
            </main>
        </div>
    </div>

    <script>
        (function () {
            const sidebar = document.getElementById('studentSidebar');
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

            const todayBtn = document.getElementById('todayTabBtn');
            const weekBtn = document.getElementById('weekTabBtn');
            const todayPanel = document.getElementById('todayPanel');
            const weekPanel = document.getElementById('weekPanel');

            function setToday() {
                todayPanel?.classList.remove('hidden');
                weekPanel?.classList.add('hidden');
                todayBtn?.classList.add('bg-emerald-600', 'text-white');
                todayBtn?.classList.remove('text-slate-500');
                weekBtn?.classList.remove('bg-emerald-600', 'text-white');
                weekBtn?.classList.add('text-slate-500');
            }

            function setWeek() {
                weekPanel?.classList.remove('hidden');
                todayPanel?.classList.add('hidden');
                weekBtn?.classList.add('bg-emerald-600', 'text-white');
                weekBtn?.classList.remove('text-slate-500');
                todayBtn?.classList.remove('bg-emerald-600', 'text-white');
                todayBtn?.classList.add('text-slate-500');
            }

            todayBtn?.addEventListener('click', setToday);
            weekBtn?.addEventListener('click', setWeek);

            const notifBtn = document.getElementById('notifBtn');
            const notifMenu = document.getElementById('notifMenu');
            const notifDot = document.getElementById('notifDot');
            const notifMarkRead = document.getElementById('notifMarkRead');
            const notifDelete = document.getElementById('notifDelete');
            const notifList = document.getElementById('notifList');
            const notifScrollUp = document.getElementById('notifScrollUp');
            const notifScrollDown = document.getElementById('notifScrollDown');
            const notifScrollStep = 96;

            function notifOpen() {
                notifMenu?.classList.remove('hidden');
                notifBtn?.setAttribute('aria-expanded', 'true');
                window.requestAnimationFrame(updateNotifScrollButtons);
            }

            function notifClose() {
                notifMenu?.classList.add('hidden');
                notifBtn?.setAttribute('aria-expanded', 'false');
            }

            function notifToggle() {
                if (!notifMenu) {
                    return;
                }
                if (notifMenu.classList.contains('hidden')) {
                    notifOpen();
                } else {
                    notifClose();
                }
            }

            async function postNotifAction(action) {
                try {
                    const res = await fetch('notifications_seen.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: 'action=' + encodeURIComponent(action),
                    });
                    return res.ok;
                } catch (_) {
                    return false;
                }
            }

            function updateNotifScrollButtons() {
                if (!notifList || !notifScrollUp || !notifScrollDown) return;
                const maxScroll = Math.max(0, notifList.scrollHeight - notifList.clientHeight);
                const atTop = notifList.scrollTop <= 1;
                const atBottom = notifList.scrollTop >= (maxScroll - 1);

                notifScrollUp.disabled = atTop;
                notifScrollDown.disabled = atBottom;

                notifScrollUp.classList.toggle('opacity-40', atTop);
                notifScrollDown.classList.toggle('opacity-40', atBottom);
            }

            notifBtn?.addEventListener('click', function (event) {
                event.preventDefault();
                event.stopPropagation();
                notifToggle();
            });

            notifMarkRead?.addEventListener('click', async function (event) {
                event.preventDefault();
                event.stopPropagation();
                const ok = await postNotifAction('seen');
                if (ok) {
                    notifDot?.classList.add('hidden');
                    window.location.reload();
                }
            });

            notifDelete?.addEventListener('click', async function (event) {
                event.preventDefault();
                event.stopPropagation();
                const ok = await postNotifAction('delete');
                if (ok) {
                    notifDot?.classList.add('hidden');
                    window.location.reload();
                }
            });

            notifScrollUp?.addEventListener('click', function (event) {
                event.preventDefault();
                event.stopPropagation();
                notifList?.scrollBy({ top: -notifScrollStep, behavior: 'smooth' });
            });

            notifScrollDown?.addEventListener('click', function (event) {
                event.preventDefault();
                event.stopPropagation();
                notifList?.scrollBy({ top: notifScrollStep, behavior: 'smooth' });
            });

            notifList?.addEventListener('scroll', updateNotifScrollButtons);
            updateNotifScrollButtons();

            document.addEventListener('click', function (event) {
                const target = event.target;
                if (!(target instanceof Element)) {
                    return;
                }
                if (notifMenu?.contains(target) || notifBtn?.contains(target)) {
                    return;
                }
                notifClose();
            });

            document.addEventListener('keydown', function (event) {
                if (event.key === 'Escape') {
                    notifClose();
                    setSidebarOpen(false);
                }
            });
        })();
    </script>
</body>
</html>
