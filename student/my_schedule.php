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
    return $ts === false ? $time : date('g:i A', $ts);
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

function student_class_duration(string $start_date, string $end_date, string $start_time, string $end_time): string {
    $sd = $start_date !== '' ? strtotime($start_date) : false;
    $ed = $end_date !== '' ? strtotime($end_date) : false;

    if ($sd !== false && $ed !== false) {
        return date('F d, Y', $sd) . ' - ' . date('F d, Y', $ed);
    }

    return 'N/A';
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

function student_subject_badge(string $subjectCode): string {
    if (preg_match('/(\d{2,3})/', $subjectCode, $m)) {
        return $m[1];
    }
    $clean = preg_replace('/[^A-Za-z0-9]/', '', $subjectCode);
    return strtoupper(substr((string)$clean, 0, 3));
}

$student = [];
$schedule = [];
$week_days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
$schedule_by_day = [];
foreach ($week_days as $dayName) {
    $schedule_by_day[$dayName] = [];
}

$semester_label = '2nd Semester 2025-2026';

try {
    $stmt = $conn->prepare("SELECT id, student_id, first_name, last_name, username, email, year_level FROM users WHERE id = ? AND role = 'student' LIMIT 1");
    $stmt->execute([$_SESSION['user_id']]);
    $student = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $schedule_cols = [];
    try {
        $stmtCols = $conn->query('DESCRIBE schedules');
        foreach (($stmtCols ? $stmtCols->fetchAll(PDO::FETCH_ASSOC) : []) as $colRow) {
            $schedule_cols[(string)($colRow['Field'] ?? '')] = true;
        }
    } catch (Throwable $e) {
        $schedule_cols = [];
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
    $subjects_enabled = ($subjects_table_exists && isset($schedule_cols['subject_id']));

    if ($has_end_time) {
        $end_expr = 's.end_time';
    } elseif ($has_duration_minutes) {
        $end_expr = 'ADDTIME(s.start_time, SEC_TO_TIME(COALESCE(s.duration_minutes, 120) * 60))';
    } else {
        $end_expr = 'ADDTIME(s.start_time, SEC_TO_TIME(120 * 60))';
    }

    $start_date_expr = isset($schedule_cols['start_date'])
        ? "DATE_FORMAT(COALESCE(s.start_date, DATE(s.created_at)), '%Y-%m-%d')"
        : "DATE_FORMAT(DATE(s.created_at), '%Y-%m-%d')";
    $end_date_expr = isset($schedule_cols['end_date'])
        ? "DATE_FORMAT(COALESCE(s.end_date, DATE_ADD(COALESCE(s.start_date, DATE(s.created_at)), INTERVAL 17 DAY)), '%Y-%m-%d')"
        : "DATE_FORMAT(DATE_ADD(DATE(s.created_at), INTERVAL 17 DAY), '%Y-%m-%d')";

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
            {$start_date_expr} AS start_date,
            {$end_date_expr} AS end_date,
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
    }
} catch (Throwable $e) {
    error_log('Student schedule page error: ' . $e->getMessage());
    $schedule = [];
}

$first_name = (string)($student['first_name'] ?? ($_SESSION['first_name'] ?? 'Student'));
$last_name = (string)($student['last_name'] ?? ($_SESSION['last_name'] ?? ''));
$username = (string)($student['username'] ?? ($_SESSION['username'] ?? 'student'));
$full_name = trim($first_name . ' ' . $last_name);
if ($full_name === '') {
    $full_name = 'Student';
}
$user_initials = student_initials($first_name, $last_name, $username);

$selected_day = in_array(date('l'), $week_days, true) ? date('l') : 'Monday';
$day_counts = [];
foreach ($week_days as $dayName) {
    $day_counts[$dayName] = count($schedule_by_day[$dayName] ?? []);
}

$schedule_modal_map = [];
foreach ($schedule as $row) {
    $schedule_id = (int)($row['schedule_id'] ?? 0);
    if ($schedule_id <= 0 || isset($schedule_modal_map[$schedule_id])) {
        continue;
    }

    $start_time = (string)($row['start_time'] ?? '');
    $end_time = (string)($row['end_time'] ?? '');
    if ($end_time === '') {
        $end_time = student_time_add_minutes($start_time, 120);
    }
    $duration_label = student_class_duration((string)($row['start_date'] ?? ''), (string)($row['end_date'] ?? ''), $start_time, $end_time);

    $schedule_modal_map[$schedule_id] = [
        'subject_name' => (string)($row['subject_name'] ?? ''),
        'subject_code' => (string)($row['subject_code'] ?? ''),
        'schedule_day' => (string)($row['schedule_day'] ?? ''),
        'time_range' => student_time_display($start_time) . ' - ' . student_time_display($end_time),
        'duration_label' => $duration_label,
        'room_number' => (string)($row['room_number'] ?? 'TBA'),
        'instructor_name' => (string)($row['instructor_name'] ?? 'TBA'),
    ];
}

$palette = [
    ['badge' => 'bg-emerald-500', 'bar' => 'bg-emerald-400', 'soft' => 'bg-emerald-50 text-emerald-700'],
    ['badge' => 'bg-indigo-500', 'bar' => 'bg-indigo-400', 'soft' => 'bg-indigo-50 text-indigo-700'],
    ['badge' => 'bg-amber-500', 'bar' => 'bg-amber-400', 'soft' => 'bg-amber-50 text-amber-700'],
    ['badge' => 'bg-pink-500', 'bar' => 'bg-pink-400', 'soft' => 'bg-pink-50 text-pink-700'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" type="image/png" href="/PCTClassSchedulingSystem/pctlogo.png">
<meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Schedule - PCT Student</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/html2canvas@1.4.1/dist/html2canvas.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/jspdf@2.5.1/dist/jspdf.umd.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>
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
                    <a href="dashboard.php" class="sidebar-nav-link flex items-center gap-2 rounded-xl px-3 py-2.5 text-sm text-emerald-100/90 hover:bg-emerald-800/50 hover:text-white"><span class="sidebar-nav-content inline-flex items-center gap-2"><i class="bi bi-grid"></i><span class="sidebar-label">Dashboard</span></span></a>
                    <a href="my_schedule.php" class="sidebar-nav-link flex items-center justify-between rounded-xl bg-emerald-700/45 px-3 py-2.5 text-sm font-medium text-emerald-50">
                        <span class="sidebar-nav-content inline-flex items-center gap-2"><i class="bi bi-calendar3"></i><span class="sidebar-label">My Schedule</span></span>
                        <span class="sidebar-active-dot h-1.5 w-1.5 rounded-full bg-emerald-200"></span>
                    </a>
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
                            <span class="text-slate-600">My Schedule</span>
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
                                                                <div class="text-xs text-slate-500 truncate"><?php echo htmlspecialchars($it['subtitle'] ?? ''); ?></div>
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

            <main id="scheduleExportArea" class="px-4 sm:px-6 py-6 space-y-5">
                <section class="flex items-start justify-between gap-3">
                    <div>
                        <h1 class="text-4xl leading-tight font-semibold text-slate-800">My Schedule</h1>
                        <p class="mt-1 text-xl text-slate-400"><?php echo htmlspecialchars($semester_label); ?> &middot; Weekly View</p>
                    </div>
                    <div class="relative">
                        <button id="exportBtn" type="button" class="inline-flex items-center gap-2 rounded-xl bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700" aria-haspopup="menu" aria-expanded="false" aria-controls="exportMenu">
                            <i class="bi bi-download"></i>
                            Export
                            <i class="bi bi-chevron-down text-xs"></i>
                        </button>
                        <div id="exportMenu" class="absolute right-0 mt-2 hidden min-w-[190px] overflow-hidden rounded-xl border border-slate-200 bg-white shadow-xl" role="menu" aria-label="Export options">
                            <button type="button" class="export-option flex w-full items-center gap-2 px-3 py-2 text-left text-sm text-slate-700 hover:bg-slate-50" data-export-format="excel" role="menuitem">
                                <i class="bi bi-file-earmark-spreadsheet text-emerald-600"></i>
                                Save as Excel
                            </button>
                            <button type="button" class="export-option flex w-full items-center gap-2 px-3 py-2 text-left text-sm text-slate-700 hover:bg-slate-50" data-export-format="pdf" role="menuitem">
                                <i class="bi bi-filetype-pdf text-rose-500"></i>
                                Save as PDF
                            </button>
                            <button type="button" class="export-option flex w-full items-center gap-2 px-3 py-2 text-left text-sm text-slate-700 hover:bg-slate-50" data-export-format="png" role="menuitem">
                                <i class="bi bi-filetype-png text-emerald-500"></i>
                                Save as PNG
                            </button>
                            <button type="button" class="export-option flex w-full items-center gap-2 px-3 py-2 text-left text-sm text-slate-700 hover:bg-slate-50" data-export-format="jpeg" role="menuitem">
                                <i class="bi bi-file-earmark-image text-amber-500"></i>
                                Save as JPEG
                            </button>
                        </div>
                    </div>
                </section>

                <section class="rounded-2xl border border-slate-200 bg-white shadow-sm p-2 sm:p-3">
                    <div class="flex items-center gap-2">
                        <button id="dayPrevBtn" type="button" class="inline-flex h-10 w-10 items-center justify-center rounded-xl text-slate-500 hover:bg-slate-100" aria-label="Previous day">
                            <i class="bi bi-chevron-left"></i>
                        </button>
                        <div class="flex-1 grid grid-cols-6 gap-1">
                            <?php foreach ($week_days as $dayIndex => $dayName): ?>
                                <button
                                    type="button"
                                    class="day-pill rounded-xl px-3 py-2 text-sm font-semibold <?php echo $dayName === $selected_day ? 'bg-emerald-600 text-white' : 'text-slate-500 hover:bg-slate-100'; ?>"
                                    data-day="<?php echo htmlspecialchars($dayName); ?>"
                                >
                                    <?php echo htmlspecialchars(student_day_short($dayName)); ?>
                                </button>
                            <?php endforeach; ?>
                        </div>
                        <button id="dayNextBtn" type="button" class="inline-flex h-10 w-10 items-center justify-center rounded-xl text-slate-500 hover:bg-slate-100" aria-label="Next day">
                            <i class="bi bi-chevron-right"></i>
                        </button>
                    </div>
                </section>

                <section class="space-y-3">
                    <?php foreach ($week_days as $dayName): ?>
                        <div class="day-panel <?php echo $dayName === $selected_day ? '' : 'hidden'; ?>" data-day-panel="<?php echo htmlspecialchars($dayName); ?>">
                            <?php $daySchedules = $schedule_by_day[$dayName] ?? []; ?>
                            <?php if (empty($daySchedules)): ?>
                                <div class="rounded-2xl border border-slate-200 bg-white px-5 py-6 text-sm text-slate-500">
                                    No schedule available for <?php echo htmlspecialchars($dayName); ?>.
                                </div>
                            <?php else: ?>
                                <div class="space-y-3">
                                    <?php foreach ($daySchedules as $idx => $item): ?>
                                        <?php $tone = $palette[$idx % count($palette)]; ?>
                                        <?php
                                            $startTime = (string)($item['start_time'] ?? '');
                                            $endTime = (string)($item['end_time'] ?? '');
                                            if ($endTime === '') {
                                                $endTime = student_time_add_minutes($startTime, 120);
                                            }
                                            $durationLabel = student_class_duration((string)($item['start_date'] ?? ''), (string)($item['end_date'] ?? ''), $startTime, $endTime);
                                        ?>
                                        <button
                                            type="button"
                                            class="js-open-schedule-modal w-full rounded-2xl border border-slate-200 bg-white shadow-sm px-4 sm:px-5 py-5 text-left transition duration-200 hover:-translate-y-0.5 hover:shadow-md"
                                            data-schedule-id="<?php echo (int)($item['schedule_id'] ?? 0); ?>"
                                        >
                                            <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
                                                <div class="flex items-center gap-4 min-w-0">
                                                    <span class="h-14 w-1 rounded-full <?php echo htmlspecialchars($tone['bar']); ?>"></span>
                                                    <span class="inline-flex h-11 min-w-11 items-center justify-center rounded-2xl px-3 text-sm font-semibold text-white <?php echo htmlspecialchars($tone['badge']); ?>">
                                                        <?php echo htmlspecialchars(student_subject_badge((string)($item['subject_code'] ?? 'N/A'))); ?>
                                                    </span>
                                                    <div class="min-w-0">
                                                        <div class="text-3xl leading-tight font-semibold text-slate-700 truncate"><?php echo htmlspecialchars((string)($item['subject_name'] ?? 'Subject')); ?></div>
                                                        <div class="mt-1 text-sm font-semibold text-slate-400 truncate"><?php echo htmlspecialchars((string)($item['subject_code'] ?? 'N/A')); ?></div>
                                                    </div>
                                                </div>

                                                <div class="flex items-center gap-4 text-sm text-slate-500">
                                                    <span class="inline-flex items-center gap-1.5 font-semibold"><i class="bi bi-clock"></i><?php echo htmlspecialchars(student_time_display($startTime)); ?> - <?php echo htmlspecialchars(student_time_display($endTime)); ?></span>
                                                    <span class="inline-flex items-center gap-1.5 font-semibold"><i class="bi bi-geo-alt"></i><?php echo htmlspecialchars((string)($item['room_number'] ?? 'TBA')); ?></span>
                                                    <span class="inline-flex items-center gap-1.5 font-semibold"><i class="bi bi-person"></i><?php echo htmlspecialchars((string)($item['instructor_name'] ?? 'TBA')); ?></span>
                                                    <i class="bi bi-chevron-right text-slate-300"></i>
                                                </div>
                                            </div>
                                            <div class="mt-2 text-xs font-semibold text-emerald-700 flex items-center gap-1.5">
                                                <i class="bi bi-hourglass-split"></i>
                                                <span><?php echo htmlspecialchars($durationLabel); ?></span>
                                            </div>
                                        </button>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </section>
            </main>
        </div>
    </div>

    <div id="scheduleDetailModal" class="fixed inset-0 z-50 hidden" aria-hidden="true">
        <div class="absolute inset-0 bg-slate-900/50" data-modal-close></div>
        <div class="absolute inset-0 p-4 flex items-center justify-center">
            <div class="w-full max-w-xl rounded-3xl border border-slate-200 bg-white shadow-2xl overflow-hidden">
                <div class="px-5 py-4 border-b border-slate-200 flex items-center justify-between">
                    <div>
                        <div class="text-xs font-semibold tracking-[0.18em] text-emerald-600">SUBJECT DETAILS</div>
                        <h3 id="scheduleModalSubjectName" class="mt-1 text-2xl font-semibold text-slate-900">Subject</h3>
                        <p id="scheduleModalSubjectCode" class="text-sm text-slate-500"></p>
                    </div>
                    <button id="scheduleModalCloseBtn" type="button" class="inline-flex h-9 w-9 items-center justify-center rounded-xl border border-slate-200 text-slate-600 hover:bg-slate-50" aria-label="Close subject details">
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
                        <div id="scheduleModalDuration" class="mt-1 text-xs font-semibold text-emerald-700">-</div>
                    </div>
                    <div class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3">
                        <div class="text-xs font-semibold tracking-wide text-slate-500">ROOM</div>
                        <div id="scheduleModalRoom" class="mt-1 text-base font-semibold text-slate-800">-</div>
                    </div>
                    <div class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3">
                        <div class="text-xs font-semibold tracking-wide text-slate-500">INSTRUCTOR</div>
                        <div id="scheduleModalInstructor" class="mt-1 text-base font-semibold text-slate-800">-</div>
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

            const exportBtn = document.getElementById('exportBtn');
            const exportMenu = document.getElementById('exportMenu');
            const exportOptions = Array.from(document.querySelectorAll('.export-option'));

            function setExportMenuOpen(open) {
                if (!exportMenu || !exportBtn) {
                    return;
                }
                exportMenu.classList.toggle('hidden', !open);
                exportBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
            }

            function buildExportName(ext) {
                const now = new Date();
                const pad = function (n) { return String(n).padStart(2, '0'); };
                const stamp = now.getFullYear() + '-' + pad(now.getMonth() + 1) + '-' + pad(now.getDate()) + '_' + pad(now.getHours()) + '-' + pad(now.getMinutes());
                return 'student_schedule_' + stamp + '.' + ext;
            }

            function triggerDownload(dataUrl, filename) {
                const link = document.createElement('a');
                link.href = dataUrl;
                link.download = filename;
                document.body.appendChild(link);
                link.click();
                document.body.removeChild(link);
            }

            function toCsvValue(value) {
                const text = String(value == null ? '' : value);
                if (text.includes('"') || text.includes(',') || text.includes('\n')) {
                    return '"' + text.replace(/"/g, '""') + '"';
                }
                return text;
            }

            function scheduleRowsForExport() {
                const details = scheduleDetails || {};
                const rows = Object.keys(details).map(function (k) {
                    return details[k] || {};
                });

                const dayRank = {
                    'Monday': 1,
                    'Tuesday': 2,
                    'Wednesday': 3,
                    'Thursday': 4,
                    'Friday': 5,
                    'Saturday': 6,
                    'Sunday': 7,
                };

                rows.sort(function (a, b) {
                    return (dayRank[a.schedule_day] || 99) - (dayRank[b.schedule_day] || 99);
                });

                return rows.map(function (row, idx) {
                    return {
                        No: idx + 1,
                        SubjectCode: row.subject_code || '',
                        SubjectName: row.subject_name || '',
                        Day: row.schedule_day || '',
                        Time: row.time_range || '',
                        ClassDuration: row.duration_label || '',
                        Room: row.room_number || '',
                        Instructor: row.instructor_name || '',
                    };
                });
            }

            function exportExcelFile() {
                const rows = scheduleRowsForExport();
                if (rows.length === 0) {
                    throw new Error('No schedule rows to export.');
                }

                if (window.XLSX && window.XLSX.utils && window.XLSX.utils.json_to_sheet) {
                    const ws = window.XLSX.utils.json_to_sheet(rows);
                    ws['!cols'] = [
                        { wch: 6 },
                        { wch: 16 },
                        { wch: 38 },
                        { wch: 14 },
                        { wch: 24 },
                        { wch: 36 },
                        { wch: 10 },
                        { wch: 28 },
                    ];

                    const wb = window.XLSX.utils.book_new();
                    window.XLSX.utils.book_append_sheet(wb, ws, 'Schedule');
                    window.XLSX.writeFile(wb, buildExportName('xlsx'));
                    return;
                }

                const headers = Object.keys(rows[0]);
                const csvLines = [headers.map(toCsvValue).join(',')];
                rows.forEach(function (row) {
                    const line = headers.map(function (key) { return toCsvValue(row[key]); }).join(',');
                    csvLines.push(line);
                });
                const csv = csvLines.join('\n');
                triggerDownload('data:text/csv;charset=utf-8,' + encodeURIComponent(csv), buildExportName('csv'));
            }

            async function captureScheduleCanvas() {
                const target = document.getElementById('scheduleExportArea');
                if (!target || typeof window.html2canvas !== 'function') {
                    throw new Error('Export library is not available.');
                }

                await new Promise(function (resolve) {
                    window.requestAnimationFrame(function () {
                        window.requestAnimationFrame(resolve);
                    });
                });

                return window.html2canvas(target, {
                    backgroundColor: '#f1f5f9',
                    scale: 2,
                    useCORS: true,
                    scrollX: 0,
                    scrollY: -window.scrollY,
                    windowWidth: document.documentElement.clientWidth,
                });
            }

            let exportingNow = false;
            async function exportSchedule(format) {
                if (exportingNow) {
                    return;
                }
                exportingNow = true;
                exportOptions.forEach(function (btn) { btn.disabled = true; });

                const originalLabel = exportBtn ? exportBtn.innerHTML : '';
                if (exportBtn) {
                    exportBtn.innerHTML = '<i class="bi bi-hourglass-split"></i> Exporting...';
                }

                try {
                    const canvas = await captureScheduleCanvas();

                    if (format === 'png') {
                        triggerDownload(canvas.toDataURL('image/png'), buildExportName('png'));
                    } else if (format === 'jpeg') {
                        triggerDownload(canvas.toDataURL('image/jpeg', 0.95), buildExportName('jpg'));
                    } else if (format === 'pdf') {
                        const jsPDFCtor = window.jspdf && window.jspdf.jsPDF;
                        if (!jsPDFCtor) {
                            throw new Error('PDF library is not available.');
                        }
                        const pdf = new jsPDFCtor({
                            orientation: canvas.width >= canvas.height ? 'landscape' : 'portrait',
                            unit: 'px',
                            format: [canvas.width, canvas.height],
                        });
                        pdf.addImage(canvas.toDataURL('image/png'), 'PNG', 0, 0, canvas.width, canvas.height, undefined, 'FAST');
                        pdf.save(buildExportName('pdf'));
                    } else if (format === 'excel') {
                        exportExcelFile();
                    }
                } catch (error) {
                    alert((error && error.message) ? error.message : 'Unable to export schedule right now.');
                } finally {
                    exportingNow = false;
                    exportOptions.forEach(function (btn) { btn.disabled = false; });
                    if (exportBtn) {
                        exportBtn.innerHTML = originalLabel;
                    }
                    setExportMenuOpen(false);
                }
            }

            exportBtn?.addEventListener('click', function (event) {
                event.preventDefault();
                event.stopPropagation();
                const open = exportMenu ? exportMenu.classList.contains('hidden') : false;
                setExportMenuOpen(open);
            });

            exportOptions.forEach(function (optionBtn) {
                optionBtn.addEventListener('click', function (event) {
                    event.preventDefault();
                    event.stopPropagation();
                    const fmt = String(optionBtn.getAttribute('data-export-format') || '').toLowerCase();
                    if (fmt === 'pdf' || fmt === 'png' || fmt === 'jpeg' || fmt === 'excel') {
                        exportSchedule(fmt);
                    }
                });
            });

            const weekDays = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
            const dayPills = Array.from(document.querySelectorAll('.day-pill'));
            const dayPanels = Array.from(document.querySelectorAll('.day-panel'));
            const prevBtn = document.getElementById('dayPrevBtn');
            const nextBtn = document.getElementById('dayNextBtn');

            function setDay(day) {
                dayPills.forEach(function (pill) {
                    const isActive = pill.dataset.day === day;
                    pill.classList.toggle('bg-emerald-600', isActive);
                    pill.classList.toggle('text-white', isActive);
                    pill.classList.toggle('text-slate-500', !isActive);
                    pill.classList.toggle('hover:bg-slate-100', !isActive);
                });

                dayPanels.forEach(function (panel) {
                    panel.classList.toggle('hidden', panel.dataset.dayPanel !== day);
                });
            }

            dayPills.forEach(function (pill) {
                pill.addEventListener('click', function () {
                    setDay(pill.dataset.day || 'Monday');
                });
            });

            function currentDayIndex() {
                const activePill = dayPills.find(function (pill) {
                    return pill.classList.contains('bg-emerald-600');
                });
                const activeDay = activePill?.dataset.day || 'Monday';
                const index = weekDays.indexOf(activeDay);
                return index >= 0 ? index : 0;
            }

            prevBtn?.addEventListener('click', function () {
                const idx = currentDayIndex();
                const nextIdx = (idx - 1 + weekDays.length) % weekDays.length;
                setDay(weekDays[nextIdx]);
            });

            nextBtn?.addEventListener('click', function () {
                const idx = currentDayIndex();
                const nextIdx = (idx + 1) % weekDays.length;
                setDay(weekDays[nextIdx]);
            });

            const modal = document.getElementById('scheduleDetailModal');
            const modalCloseBtn = document.getElementById('scheduleModalCloseBtn');
            const modalCloseTargets = Array.from(document.querySelectorAll('[data-modal-close]'));
            const modalTriggers = Array.from(document.querySelectorAll('.js-open-schedule-modal'));

            const modalSubjectName = document.getElementById('scheduleModalSubjectName');
            const modalSubjectCode = document.getElementById('scheduleModalSubjectCode');
            const modalDay = document.getElementById('scheduleModalDay');
            const modalTime = document.getElementById('scheduleModalTime');
            const modalDuration = document.getElementById('scheduleModalDuration');
            const modalRoom = document.getElementById('scheduleModalRoom');
            const modalInstructor = document.getElementById('scheduleModalInstructor');

            const scheduleDetails = <?php echo json_encode($schedule_modal_map, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

            function closeScheduleModal() {
                if (!modal) {
                    return;
                }
                modal.classList.add('hidden');
                modal.setAttribute('aria-hidden', 'true');
                document.body.classList.remove('overflow-hidden');
            }

            function openScheduleModal(scheduleId) {
                if (!modal) {
                    return;
                }

                const key = String(scheduleId || '');
                if (key === '' || !scheduleDetails[key]) {
                    return;
                }

                const detail = scheduleDetails[key];
                if (modalSubjectName) modalSubjectName.textContent = detail.subject_name || 'Subject details';
                if (modalSubjectCode) modalSubjectCode.textContent = detail.subject_code || 'No subject code';
                if (modalDay) modalDay.textContent = detail.schedule_day || '-';
                if (modalTime) modalTime.textContent = detail.time_range || '-';
                if (modalDuration) modalDuration.textContent = detail.duration_label || 'N/A';
                if (modalRoom) modalRoom.textContent = detail.room_number || 'TBA';
                if (modalInstructor) modalInstructor.textContent = detail.instructor_name || 'TBA';

                modal.classList.remove('hidden');
                modal.setAttribute('aria-hidden', 'false');
                document.body.classList.add('overflow-hidden');
            }

            modalTriggers.forEach(function (trigger) {
                trigger.addEventListener('click', function () {
                    openScheduleModal(trigger.getAttribute('data-schedule-id'));
                });
            });

            modalCloseBtn?.addEventListener('click', closeScheduleModal);
            modalCloseTargets.forEach(function (target) {
                target.addEventListener('click', closeScheduleModal);
            });

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
                if (!notifMenu) return;
                if (notifMenu.classList.contains('hidden')) notifOpen();
                else notifClose();
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
                if (!(target instanceof Element)) return;
                if (exportMenu?.contains(target) || exportBtn?.contains(target)) return;
                setExportMenuOpen(false);
                if (notifMenu?.contains(target) || notifBtn?.contains(target)) return;
                notifClose();
            });

            document.addEventListener('keydown', function (event) {
                if (event.key === 'Escape') {
                    setExportMenuOpen(false);
                    closeScheduleModal();
                    notifClose();
                    setSidebarOpen(false);
                }
            });
        })();
    </script>
</body>
</html>
