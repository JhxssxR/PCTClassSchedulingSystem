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
$week_days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
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

    $course_cols = [];
    try {
        $courseStmt = $conn->query('DESCRIBE courses');
        foreach (($courseStmt ? $courseStmt->fetchAll(PDO::FETCH_ASSOC) : []) as $colRow) {
            $course_cols[(string)($colRow['Field'] ?? '')] = true;
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
        ORDER BY FIELD(s.day_of_week, 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'), s.start_time
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
    error_log('Student subjects page error: ' . $e->getMessage());
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

$subject_map = [];
$total_units = 0;
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
        $total_units += (int)($row['units'] ?? 0);
    }

    $start = (string)($row['start_time'] ?? '');
    $end = (string)($row['end_time'] ?? '');
    if ($end === '') {
        $end = student_time_add_minutes($start, 120);
    }

    $subject_map[$key]['sessions'][] = student_day_short((string)($row['schedule_day'] ?? '')) . ' ' . student_time_display($start) . '-' . student_time_display($end);
}

$subjects = array_values($subject_map);
$subject_count = count($subjects);

$subject_modal_map = [];
foreach ($subjects as $idx => $subject) {
    $subject_modal_map[(string)$idx] = [
        'subject_name' => (string)($subject['subject_name'] ?? 'Subject'),
        'subject_code' => (string)($subject['subject_code'] ?? 'N/A'),
        'units' => (int)($subject['units'] ?? 0),
        'room_number' => (string)($subject['room_number'] ?? 'TBA'),
        'instructor_name' => (string)($subject['instructor_name'] ?? 'TBA'),
        'sessions' => array_values($subject['sessions'] ?? []),
    ];
}

$palette = [
    ['header' => 'bg-emerald-100', 'badge' => 'bg-emerald-500', 'text' => 'text-emerald-700'],
    ['header' => 'bg-indigo-100', 'badge' => 'bg-indigo-500', 'text' => 'text-indigo-700'],
    ['header' => 'bg-amber-100', 'badge' => 'bg-amber-500', 'text' => 'text-amber-700'],
    ['header' => 'bg-pink-100', 'badge' => 'bg-pink-500', 'text' => 'text-pink-700'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" type="image/png" href="/PCTClassSchedulingSystem/pctlogo.png">
<meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Subjects - PCT Student</title>
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
                    <a href="dashboard.php" class="sidebar-nav-link flex items-center gap-2 rounded-xl px-3 py-2.5 text-sm text-emerald-100/90 hover:bg-emerald-800/50 hover:text-white"><span class="sidebar-nav-content inline-flex items-center gap-2"><i class="bi bi-grid"></i><span class="sidebar-label">Dashboard</span></span></a>
                    <a href="my_schedule.php" class="sidebar-nav-link flex items-center gap-2 rounded-xl px-3 py-2.5 text-sm text-emerald-100/90 hover:bg-emerald-800/50 hover:text-white"><span class="sidebar-nav-content inline-flex items-center gap-2"><i class="bi bi-calendar3"></i><span class="sidebar-label">My Schedule</span></span></a>
                    <a href="my_subjects.php" class="sidebar-nav-link flex items-center justify-between rounded-xl bg-emerald-700/45 px-3 py-2.5 text-sm font-medium text-emerald-50">
                        <span class="sidebar-nav-content inline-flex items-center gap-2"><i class="bi bi-book"></i><span class="sidebar-label">My Subjects</span></span>
                        <span class="sidebar-active-dot h-1.5 w-1.5 rounded-full bg-emerald-200"></span>
                    </a>
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
                            <span class="text-slate-600">My Subjects</span>
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

            <main class="px-4 sm:px-6 py-6 space-y-5">
                <section class="flex items-start justify-between gap-3">
                    <div>
                        <h1 class="text-4xl leading-tight font-semibold text-slate-800">My Subjects</h1>
                        <p class="mt-1 text-xl text-slate-400"><?php echo (int)$subject_count; ?> subjects enrolled &middot; <?php echo htmlspecialchars($semester_label); ?></p>
                    </div>
                    <span class="inline-flex items-center rounded-full bg-emerald-50 px-4 py-2 text-sm font-semibold text-emerald-600 ring-1 ring-inset ring-emerald-200">
                        <?php echo (int)$total_units; ?> Units Total
                    </span>
                </section>

                <section class="grid grid-cols-1 xl:grid-cols-2 gap-4">
                    <?php if (empty($subjects)): ?>
                        <div class="xl:col-span-2 rounded-2xl border border-slate-200 bg-white px-5 py-6 text-sm text-slate-500">No subjects enrolled yet.</div>
                    <?php else: ?>
                        <?php foreach ($subjects as $idx => $subject): ?>
                            <?php $tone = $palette[$idx % count($palette)]; ?>
                            <?php
                                $sessionText = implode(' / ', array_slice($subject['sessions'], 0, 2));
                                if ($sessionText === '') {
                                    $sessionText = 'TBA';
                                }
                            ?>
                            <button
                                type="button"
                                class="js-open-subject-modal w-full rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden text-left transition duration-200 hover:-translate-y-0.5 hover:shadow-md hover:border-slate-300"
                                data-subject-key="<?php echo htmlspecialchars((string)$idx); ?>"
                            >
                                <div class="px-4 sm:px-5 py-4 <?php echo htmlspecialchars($tone['header']); ?>">
                                    <div class="flex items-center justify-between gap-3">
                                        <div class="flex items-center gap-3 min-w-0">
                                            <span class="inline-flex h-11 min-w-11 items-center justify-center rounded-2xl px-3 text-sm font-semibold text-white <?php echo htmlspecialchars($tone['badge']); ?>">
                                                <?php echo htmlspecialchars(student_subject_badge((string)($subject['subject_code'] ?? 'N/A'))); ?>
                                            </span>
                                            <div class="min-w-0">
                                                <div class="text-3xl leading-tight font-semibold text-slate-700 truncate"><?php echo htmlspecialchars((string)($subject['subject_name'] ?? 'Subject')); ?></div>
                                                <div class="text-sm font-semibold text-slate-400 truncate"><?php echo htmlspecialchars((string)($subject['subject_code'] ?? 'N/A')); ?></div>
                                            </div>
                                        </div>
                                        <span class="inline-flex items-center rounded-full bg-white px-3 py-1 text-sm font-semibold text-slate-500 shadow-sm ring-1 ring-inset ring-slate-200">
                                            <?php echo (int)($subject['units'] ?? 0); ?> units
                                        </span>
                                    </div>
                                </div>

                                <div class="px-4 sm:px-5 py-4 space-y-2 text-sm text-slate-500">
                                    <div class="grid grid-cols-1 md:grid-cols-[1fr_auto] gap-2 items-center">
                                        <div class="grid grid-cols-1 md:grid-cols-2 gap-2">
                                            <span class="inline-flex items-center gap-1.5"><i class="bi bi-person"></i><?php echo htmlspecialchars((string)($subject['instructor_name'] ?? 'TBA')); ?></span>
                                            <span class="inline-flex items-center gap-1.5"><i class="bi bi-geo-alt"></i><?php echo htmlspecialchars((string)($subject['room_number'] ?? 'TBA')); ?></span>
                                        </div>
                                        <span class="text-slate-300"><i class="bi bi-chevron-right"></i></span>
                                    </div>
                                    <div class="inline-flex items-center gap-1.5"><i class="bi bi-easel"></i><?php echo htmlspecialchars($sessionText); ?></div>
                                </div>
                            </button>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </section>
            </main>
        </div>
    </div>

    <div id="subjectDetailModal" class="fixed inset-0 z-50 hidden" aria-hidden="true">
        <div class="absolute inset-0 bg-slate-900/50" data-subject-modal-close></div>
        <div class="absolute inset-0 p-4 flex items-center justify-center">
            <div class="w-full max-w-2xl rounded-3xl border border-slate-200 bg-white shadow-2xl overflow-hidden">
                <div class="px-5 py-4 border-b border-slate-200 flex items-center justify-between">
                    <div>
                        <div class="text-xs font-semibold tracking-[0.18em] text-emerald-600">SUBJECT DETAILS</div>
                        <h3 id="subjectModalName" class="mt-1 text-2xl font-semibold text-slate-900">Subject</h3>
                        <p id="subjectModalCode" class="text-sm text-slate-500"></p>
                    </div>
                    <button id="subjectModalCloseBtn" type="button" class="inline-flex h-9 w-9 items-center justify-center rounded-xl border border-slate-200 text-slate-600 hover:bg-slate-50" aria-label="Close subject details">
                        <i class="bi bi-x-lg"></i>
                    </button>
                </div>

                <div class="px-5 py-5 grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3">
                        <div class="text-xs font-semibold tracking-wide text-slate-500">INSTRUCTOR</div>
                        <div id="subjectModalInstructor" class="mt-1 text-base font-semibold text-slate-800">-</div>
                    </div>
                    <div class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3">
                        <div class="text-xs font-semibold tracking-wide text-slate-500">ROOM</div>
                        <div id="subjectModalRoom" class="mt-1 text-base font-semibold text-slate-800">-</div>
                    </div>
                    <div class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 sm:col-span-2">
                        <div class="text-xs font-semibold tracking-wide text-slate-500">SCHEDULE</div>
                        <ul id="subjectModalSessions" class="mt-2 space-y-1 text-sm text-slate-700"></ul>
                    </div>
                </div>

                <div class="px-5 pb-5 flex items-center justify-between">
                    <span id="subjectModalUnits" class="inline-flex items-center rounded-full bg-emerald-50 px-3 py-1 text-sm font-semibold text-emerald-600 ring-1 ring-inset ring-emerald-200">0 units</span>
                    <button type="button" class="inline-flex items-center rounded-xl bg-emerald-600 text-white px-4 py-2 text-sm font-semibold hover:bg-emerald-700" data-subject-modal-close>Close</button>
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

            const subjectModal = document.getElementById('subjectDetailModal');
            const subjectModalCloseBtn = document.getElementById('subjectModalCloseBtn');
            const subjectModalCloseTargets = Array.from(document.querySelectorAll('[data-subject-modal-close]'));
            const subjectModalTriggers = Array.from(document.querySelectorAll('.js-open-subject-modal'));

            const subjectNameEl = document.getElementById('subjectModalName');
            const subjectCodeEl = document.getElementById('subjectModalCode');
            const subjectInstructorEl = document.getElementById('subjectModalInstructor');
            const subjectRoomEl = document.getElementById('subjectModalRoom');
            const subjectSessionsEl = document.getElementById('subjectModalSessions');
            const subjectUnitsEl = document.getElementById('subjectModalUnits');

            const subjectDetails = <?php echo json_encode($subject_modal_map, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

            function closeSubjectModal() {
                if (!subjectModal) {
                    return;
                }
                subjectModal.classList.add('hidden');
                subjectModal.setAttribute('aria-hidden', 'true');
                document.body.classList.remove('overflow-hidden');
            }

            function openSubjectModal(subjectKey) {
                if (!subjectModal) {
                    return;
                }

                const key = String(subjectKey || '');
                if (key === '' || !subjectDetails[key]) {
                    return;
                }

                const detail = subjectDetails[key];
                if (subjectNameEl) subjectNameEl.textContent = detail.subject_name || 'Subject';
                if (subjectCodeEl) subjectCodeEl.textContent = detail.subject_code || 'No subject code';
                if (subjectInstructorEl) subjectInstructorEl.textContent = detail.instructor_name || 'TBA';
                if (subjectRoomEl) subjectRoomEl.textContent = detail.room_number || 'TBA';
                if (subjectUnitsEl) subjectUnitsEl.textContent = String(detail.units || 0) + ' units';

                if (subjectSessionsEl) {
                    subjectSessionsEl.innerHTML = '';
                    const sessions = Array.isArray(detail.sessions) ? detail.sessions : [];
                    if (sessions.length === 0) {
                        const li = document.createElement('li');
                        li.className = 'text-slate-400';
                        li.textContent = 'TBA';
                        subjectSessionsEl.appendChild(li);
                    } else {
                        sessions.forEach(function (sessionLabel) {
                            const li = document.createElement('li');
                            li.className = 'inline-flex items-center gap-1.5';
                            const icon = document.createElement('i');
                            icon.className = 'bi bi-easel text-slate-400';
                            const text = document.createElement('span');
                            text.textContent = String(sessionLabel);
                            li.appendChild(icon);
                            li.appendChild(text);
                            subjectSessionsEl.appendChild(li);
                        });
                    }
                }

                subjectModal.classList.remove('hidden');
                subjectModal.setAttribute('aria-hidden', 'false');
                document.body.classList.add('overflow-hidden');
            }

            subjectModalTriggers.forEach(function (trigger) {
                trigger.addEventListener('click', function () {
                    openSubjectModal(trigger.getAttribute('data-subject-key'));
                });
            });

            subjectModalCloseBtn?.addEventListener('click', closeSubjectModal);
            subjectModalCloseTargets.forEach(function (target) {
                target.addEventListener('click', closeSubjectModal);
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
                if (notifMenu?.contains(target) || notifBtn?.contains(target)) return;
                notifClose();
            });

            document.addEventListener('keydown', function (event) {
                if (event.key === 'Escape') {
                    closeSubjectModal();
                    notifClose();
                    setSidebarOpen(false);
                }
            });
        })();
    </script>
</body>
</html>
