<?php
require_once '../config/database.php';
require_once '../includes/session.php';

require_role('instructor');
require_once __DIR__ . '/notifications_data.php';

function ins_format_time(string $time): string {
    return date('g:i A', strtotime($time));
}

function ins_time_add_minutes_raw(string $time, int $minutes): string {
    $ts = strtotime('1970-01-01 ' . $time);
    if ($ts === false) {
        return '';
    }
    return date('H:i:s', $ts + ($minutes * 60));
}

function ins_time_plus_two_hours(string $time): string {
    $end = ins_time_add_minutes_raw($time, 120);
    return $end !== '' ? date('g:i A', strtotime($end)) : '';
}

function ins_class_duration(string $start_date, string $end_date, string $start_time, string $end_time): string {
    $sd = $start_date !== '' ? strtotime($start_date) : false;
    $ed = $end_date !== '' ? strtotime($end_date) : false;

    if ($sd !== false && $ed !== false) {
        return date('F d, Y', $sd) . ' - ' . date('F d, Y', $ed);
    }

    return 'N/A';
}

function ins_day_short(string $day): string {
    $map = [
        'Monday' => 'Mon',
        'Tuesday' => 'Tue',
        'Wednesday' => 'Wed',
        'Thursday' => 'Thu',
        'Friday' => 'Fri',
        'Saturday' => 'Sat',
    ];
    return $map[$day] ?? substr($day, 0, 3);
}

function ins_type_label(?string $room_type): string {
    $type = strtolower((string) $room_type);
    if ($type === 'laboratory') {
        return 'Lab';
    }
    if ($type === 'conference') {
        return 'Conference';
    }
    return 'Lecture';
}

$instructor_id = (int) ($_SESSION['user_id'] ?? 0);
$stmt = $conn->prepare('SELECT first_name, last_name, email FROM users WHERE id = ? LIMIT 1');
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
foreach ($schedule_cols_stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $schedule_cols[$r['Field']] = true;
}

if (isset($schedule_cols['end_time'])) {
    $end_expr = 's.end_time';
} elseif (isset($schedule_cols['duration_minutes'])) {
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

$stmt = $conn->prepare("\n    SELECT\n        s.id,\n        s.day_of_week,\n        s.start_time,\n        TIME_FORMAT({$end_expr}, '%H:%i:%s') AS end_time,\n        {$start_date_expr} AS start_date,\n        {$end_date_expr} AS end_date,\n        s.max_students,\n        c.course_code,\n        c.course_name,\n        cl.room_number,\n        cl.room_type,\n        COUNT(CASE WHEN e.status = 'approved' THEN e.id END) AS enrolled_students\n    FROM schedules s\n    JOIN courses c ON s.course_id = c.id\n    JOIN classrooms cl ON s.classroom_id = cl.id\n    LEFT JOIN enrollments e ON s.id = e.schedule_id\n    WHERE s.instructor_id = :instructor_id\n      AND s.status = 'active'\n    GROUP BY s.id\n    ORDER BY FIELD(s.day_of_week, 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'), s.start_time\n");
$stmt->execute(['instructor_id' => $instructor_id]);
$all_schedules = $stmt->fetchAll(PDO::FETCH_ASSOC);

$today = date('l');
$today_schedule = [];
$schedule_by_day = [
    'Monday' => [],
    'Tuesday' => [],
    'Wednesday' => [],
    'Thursday' => [],
    'Friday' => [],
    'Saturday' => [],
];

foreach ($all_schedules as $row) {
    $day = (string) ($row['day_of_week'] ?? '');
    if (isset($schedule_by_day[$day])) {
        $schedule_by_day[$day][] = $row;
    }
    if ($day === $today) {
        $today_schedule[] = $row;
    }
}

$selected_day = 'Monday';
$week_days = array_keys($schedule_by_day);

$schedule_modal_map = [];
foreach ($all_schedules as $row) {
    $schedule_id = (int) ($row['id'] ?? 0);
    if ($schedule_id <= 0 || isset($schedule_modal_map[$schedule_id])) {
        continue;
    }

    $start_time = (string) ($row['start_time'] ?? '');
    $end_time = (string) ($row['end_time'] ?? '');
    if ($end_time === '') {
        $end_time = ins_time_add_minutes_raw($start_time, 120);
    }
    $time_range = ($start_time !== '' && $end_time !== '')
        ? (ins_format_time($start_time) . ' - ' . ins_format_time($end_time))
        : 'Time not set';
    $duration_label = ins_class_duration((string) ($row['start_date'] ?? ''), (string) ($row['end_date'] ?? ''), $start_time, $end_time);

    $schedule_modal_map[$schedule_id] = [
        'id' => $schedule_id,
        'course_name' => (string) ($row['course_name'] ?? ''),
        'course_code' => (string) ($row['course_code'] ?? ''),
        'day_of_week' => (string) ($row['day_of_week'] ?? ''),
        'time_range' => $time_range,
        'duration_label' => $duration_label,
        'room_number' => (string) ($row['room_number'] ?? 'TBA'),
        'room_type' => ins_type_label((string) ($row['room_type'] ?? 'lecture')),
        'enrolled_students' => (int) ($row['enrolled_students'] ?? 0),
        'max_students' => (int) ($row['max_students'] ?? 0),
    ];
}

$week_start_ts = strtotime('monday this week');
if ($week_start_ts === false) {
    $week_start_ts = time();
}
$week_end_ts = strtotime('saturday this week', $week_start_ts);
if ($week_end_ts === false) {
    $week_end_ts = strtotime('+5 days', $week_start_ts);
}
$week_range = date('F j', $week_start_ts) . ' - ' . date('j, Y', $week_end_ts);

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
    <title>My Schedule - PCT</title>

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
    <script src="https://cdn.jsdelivr.net/npm/html2canvas@1.4.1/dist/html2canvas.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/jspdf@2.5.1/dist/jspdf.umd.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>
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

        .day-tab.active {
            background-color: #059669;
            color: #ffffff;
            box-shadow: 0 10px 22px rgba(5, 150, 105, 0.25);
        }

        .day-panel {
            display: none;
        }

        .day-panel.active {
            display: block;
        }

        .schedule-export-mode {
            width: 1500px !important;
            max-width: none !important;
            margin: 0 auto !important;
            padding: 1.5rem !important;
        }

        .schedule-export-mode > section {
            display: none !important;
        }

        .schedule-export-mode > section.export-full-week-section {
            display: block !important;
        }

        .schedule-export-mode .export-controls,
        .schedule-export-mode #exportMenu,
        .schedule-export-mode .day-tab,
        .schedule-export-mode #weeklyPrevBtn,
        .schedule-export-mode #weeklyNextBtn {
            display: none !important;
        }

        .schedule-export-mode .day-panel {
            display: block !important;
            margin-top: 0.85rem;
        }

        .schedule-export-mode .weekly-export-grid {
            grid-template-columns: repeat(6, minmax(0, 1fr)) !important;
        }

        .schedule-export-mode .export-day-heading {
            margin-bottom: 0.65rem;
            font-size: 0.95rem;
            font-weight: 700;
            color: #0f172a;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }

        .schedule-export-mode .export-full-week-section .truncate {
            white-space: normal !important;
            overflow: visible !important;
            text-overflow: clip !important;
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
                        <?php $is_active = $item['key'] === 'schedule'; ?>
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
                            <span class="font-semibold text-slate-800">My Schedule</span>
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

            <main id="scheduleExportArea" class="px-4 sm:px-6 py-5 space-y-4">
                <section class="flex justify-end">
                    <div class="relative export-controls">
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

                <section class="rounded-3xl border border-slate-200 bg-white overflow-hidden">
                    <div class="bg-gradient-to-r from-emerald-950 via-emerald-900 to-emerald-700 px-5 py-4 text-white flex items-center justify-between">
                        <div>
                            <div class="text-[30px] leading-tight font-semibold">Today's Schedule</div>
                            <div class="text-sm text-emerald-100"><?php echo htmlspecialchars(date('l, F j, Y')); ?></div>
                        </div>
                        <span class="inline-flex items-center rounded-full bg-emerald-500/30 px-3 py-1 text-xs font-semibold text-emerald-50 border border-emerald-200/25"><?php echo count($today_schedule); ?> Classes</span>
                    </div>

                    <div class="px-5 py-3 divide-y divide-slate-100">
                        <?php if (empty($today_schedule)): ?>
                            <div class="rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-600">No classes scheduled for today.</div>
                        <?php else: ?>
                            <?php foreach ($today_schedule as $schedule): ?>
                                <?php
                                    $today_start = (string) ($schedule['start_time'] ?? '');
                                    $today_end = (string) ($schedule['end_time'] ?? '');
                                    if ($today_end === '') {
                                        $today_end = ins_time_add_minutes_raw($today_start, 120);
                                    }
                                    $today_duration = ins_class_duration((string) ($schedule['start_date'] ?? ''), (string) ($schedule['end_date'] ?? ''), $today_start, $today_end);
                                ?>
                                <button type="button" class="js-open-schedule-modal w-full text-left py-3 grid grid-cols-[150px,1fr,auto] gap-3 items-center rounded-xl hover:bg-slate-50/70 transition" data-schedule-id="<?php echo (int) ($schedule['id'] ?? 0); ?>">
                                    <div class="text-slate-500 text-sm font-semibold">
                                        <?php echo htmlspecialchars(ins_format_time($today_start)); ?> - <?php echo htmlspecialchars(ins_format_time($today_end)); ?>
                                    </div>
                                    <div>
                                        <div class="flex items-center gap-2">
                                            <span class="h-2.5 w-2.5 rounded-full bg-emerald-400"></span>
                                            <div class="text-lg font-medium text-slate-700"><?php echo htmlspecialchars((string) $schedule['course_name']); ?></div>
                                        </div>
                                        <div class="mt-1 text-sm text-slate-400 flex items-center gap-4">
                                            <span><i class="bi bi-geo-alt"></i> <?php echo htmlspecialchars((string) $schedule['room_number']); ?></span>
                                            <span><i class="bi bi-people"></i> <?php echo (int) ($schedule['enrolled_students'] ?? 0); ?> students</span>
                                        </div>
                                        <div class="mt-1 text-xs font-semibold text-emerald-700 flex items-center gap-1.5">
                                            <i class="bi bi-hourglass-split"></i>
                                            <span><?php echo htmlspecialchars($today_duration); ?></span>
                                        </div>
                                    </div>
                                    <span class="inline-flex items-center rounded-full px-3 py-1 text-xs font-semibold bg-violet-50 text-violet-600">
                                        <?php echo htmlspecialchars(ins_type_label((string) ($schedule['room_type'] ?? 'lecture'))); ?>
                                    </span>
                                </button>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </section>

                <section class="rounded-3xl border border-slate-200 bg-white p-5">
                    <div class="flex items-center justify-between">
                        <div>
                            <div class="text-[30px] font-semibold leading-tight text-slate-700">Weekly Schedule</div>
                            <div class="text-sm text-slate-400"><?php echo htmlspecialchars($week_range); ?></div>
                        </div>
                        <div class="flex items-center gap-2 text-slate-400">
                            <button id="weeklyPrevBtn" type="button" class="h-8 w-8 rounded-lg border border-slate-200 hover:bg-slate-50" aria-label="Previous day"><i class="bi bi-chevron-left"></i></button>
                            <button id="weeklyNextBtn" type="button" class="h-8 w-8 rounded-lg border border-slate-200 hover:bg-slate-50" aria-label="Next day"><i class="bi bi-chevron-right"></i></button>
                        </div>
                    </div>

                    <div class="mt-5 flex flex-wrap gap-2">
                        <?php foreach ($week_days as $day): ?>
                            <?php $day_count = count($schedule_by_day[$day]); ?>
                            <button type="button" class="day-tab <?php echo $day === $selected_day ? 'active' : 'bg-slate-100 text-slate-500'; ?> rounded-2xl px-4 py-2 text-sm font-semibold" data-day="<?php echo htmlspecialchars($day); ?>">
                                <span class="block leading-tight"><?php echo htmlspecialchars(ins_day_short($day)); ?></span>
                                <span class="block text-[11px] opacity-80"><?php echo $day_count; ?></span>
                            </button>
                        <?php endforeach; ?>
                    </div>

                    <div class="mt-4 space-y-3">
                        <?php foreach ($week_days as $day): ?>
                            <div class="day-panel <?php echo $day === $selected_day ? 'active' : ''; ?>" data-day-panel="<?php echo htmlspecialchars($day); ?>">
                                <?php if (empty($schedule_by_day[$day])): ?>
                                    <div class="rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-500">No classes on <?php echo htmlspecialchars($day); ?>.</div>
                                <?php else: ?>
                                    <div class="space-y-3">
                                        <?php foreach ($schedule_by_day[$day] as $schedule): ?>
                                            <?php
                                                $slot_start = (string) ($schedule['start_time'] ?? '');
                                                $slot_end = (string) ($schedule['end_time'] ?? '');
                                                if ($slot_end === '') {
                                                    $slot_end = ins_time_add_minutes_raw($slot_start, 120);
                                                }
                                                $slot_duration = ins_class_duration((string) ($schedule['start_date'] ?? ''), (string) ($schedule['end_date'] ?? ''), $slot_start, $slot_end);
                                            ?>
                                            <button type="button" class="js-open-schedule-modal block w-full text-left rounded-2xl border border-slate-200 bg-slate-50/70 px-4 py-3 grid grid-cols-[90px,1fr] gap-4 items-center hover:bg-slate-100/70 transition" data-schedule-id="<?php echo (int) ($schedule['id'] ?? 0); ?>">
                                                <div class="rounded-2xl border border-slate-200 bg-white text-center py-2">
                                                    <div class="text-sm font-semibold text-emerald-500"><?php echo htmlspecialchars(ins_format_time($slot_start)); ?></div>
                                                    <div class="text-xs text-slate-400 mt-2"><?php echo htmlspecialchars(ins_format_time($slot_end)); ?></div>
                                                </div>
                                                <div>
                                                    <div class="text-[30px] leading-tight font-medium text-slate-700"><?php echo htmlspecialchars((string) $schedule['course_name']); ?></div>
                                                    <div class="mt-1 text-sm text-slate-400 flex flex-wrap items-center gap-3">
                                                        <span><i class="bi bi-geo-alt"></i> <?php echo htmlspecialchars((string) $schedule['room_number']); ?></span>
                                                        <span><i class="bi bi-people"></i> <?php echo (int) ($schedule['enrolled_students'] ?? 0); ?> students</span>
                                                        <span class="rounded-full bg-blue-50 text-blue-600 px-2.5 py-0.5 text-xs font-semibold"><?php echo htmlspecialchars(ins_type_label((string) ($schedule['room_type'] ?? 'lecture'))); ?></span>
                                                    </div>
                                                    <div class="mt-1 text-xs font-semibold text-emerald-700 flex items-center gap-1.5">
                                                        <i class="bi bi-hourglass-split"></i>
                                                        <span><?php echo htmlspecialchars($slot_duration); ?></span>
                                                    </div>
                                                </div>
                                            </button>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </section>

                <section class="export-full-week-section rounded-3xl border border-slate-200 bg-white p-5">
                    <div>
                        <div class="text-[30px] font-semibold leading-tight text-slate-700">Full Week Overview</div>
                        <div class="text-sm text-slate-400">All scheduled classes</div>
                    </div>

                    <div class="weekly-export-grid mt-4 grid grid-cols-1 md:grid-cols-2 xl:grid-cols-6 border border-slate-200 rounded-2xl overflow-hidden">
                        <?php foreach ($week_days as $day): ?>
                            <?php $is_today_col = ($today === $day); ?>
                            <div class="p-3 border-r border-slate-200 last:border-r-0 <?php echo $is_today_col ? 'bg-emerald-50/50' : 'bg-white'; ?>">
                                <div class="text-sm font-semibold <?php echo $is_today_col ? 'text-emerald-600' : 'text-slate-500'; ?>"><?php echo htmlspecialchars($day); ?></div>
                                <div class="mt-3 space-y-2 min-h-[130px]">
                                    <?php if (empty($schedule_by_day[$day])): ?>
                                        <div class="text-sm italic text-slate-300">No classes</div>
                                    <?php else: ?>
                                        <?php foreach ($schedule_by_day[$day] as $schedule): ?>
                                            <?php
                                                $overview_start = (string) ($schedule['start_time'] ?? '');
                                                $overview_end = (string) ($schedule['end_time'] ?? '');
                                                if ($overview_end === '') {
                                                    $overview_end = ins_time_add_minutes_raw($overview_start, 120);
                                                }
                                                $overview_duration = ins_class_duration((string) ($schedule['start_date'] ?? ''), (string) ($schedule['end_date'] ?? ''), $overview_start, $overview_end);
                                            ?>
                                            <button type="button" class="js-open-schedule-modal block w-full text-left rounded-xl px-2.5 py-2 text-xs border hover:opacity-90 transition <?php echo $is_today_col ? 'bg-emerald-100/70 border-emerald-200 text-emerald-700' : 'bg-slate-100 border-slate-200 text-slate-500'; ?>" data-schedule-id="<?php echo (int) ($schedule['id'] ?? 0); ?>">
                                                <div class="font-semibold truncate"><?php echo htmlspecialchars((string) $schedule['course_name']); ?></div>
                                                <div class="mt-0.5"><?php echo htmlspecialchars(ins_format_time($overview_start)); ?></div>
                                                <div class="mt-0.5 font-semibold"><?php echo htmlspecialchars($overview_duration); ?></div>
                                            </button>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
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
                        <div id="scheduleModalDuration" class="mt-1 text-xs font-semibold text-emerald-700">-</div>
                    </div>
                    <div class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3">
                        <div class="text-xs font-semibold tracking-wide text-slate-500">ROOM</div>
                        <div id="scheduleModalRoom" class="mt-1 text-base font-semibold text-slate-800">-</div>
                        <div id="scheduleModalRoomType" class="text-xs text-slate-500 mt-1">-</div>
                    </div>
                    <div class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3">
                        <div class="text-xs font-semibold tracking-wide text-slate-500">ENROLLMENT</div>
                        <div id="scheduleModalEnrollment" class="mt-1 text-base font-semibold text-slate-800">-</div>
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
            const exportBtn = document.getElementById('exportBtn');
            const exportMenu = document.getElementById('exportMenu');
            const exportOptions = Array.from(document.querySelectorAll('.export-option'));
            const exportScheduleDetails = <?php echo json_encode($schedule_modal_map, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

            function setExportMenuOpen(open) {
                if (!exportBtn || !exportMenu) {
                    return;
                }
                exportMenu.classList.toggle('hidden', !open);
                exportBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
            }

            function buildExportName(ext) {
                const now = new Date();
                const pad = function (n) { return String(n).padStart(2, '0'); };
                const stamp = now.getFullYear() + '-' + pad(now.getMonth() + 1) + '-' + pad(now.getDate()) + '_' + pad(now.getHours()) + '-' + pad(now.getMinutes());
                return 'instructor_schedule_' + stamp + '.' + ext;
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
                const rows = Object.keys(exportScheduleDetails || {}).map(function (k) {
                    return exportScheduleDetails[k] || {};
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
                    const byDay = (dayRank[a.day_of_week] || 99) - (dayRank[b.day_of_week] || 99);
                    if (byDay !== 0) {
                        return byDay;
                    }
                    return String(a.time_range || '').localeCompare(String(b.time_range || ''));
                });

                return rows.map(function (row, idx) {
                    return {
                        No: idx + 1,
                        CourseCode: row.course_code || '',
                        CourseName: row.course_name || '',
                        Day: row.day_of_week || '',
                        Time: row.time_range || '',
                        ClassDuration: row.duration_label || '',
                        Room: row.room_number || '',
                        RoomType: row.room_type || '',
                        Enrollment: (row.max_students > 0)
                            ? (String(row.enrolled_students || 0) + ' / ' + String(row.max_students) + ' students')
                            : (String(row.enrolled_students || 0) + ' students'),
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
                        { wch: 12 },
                        { wch: 20 },
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

            function prepareClonedScheduleForExport(doc) {
                const clonedTarget = doc.getElementById('scheduleExportArea');
                if (!clonedTarget) {
                    return;
                }

                clonedTarget.classList.add('schedule-export-mode');
                if (doc.body) {
                    doc.body.style.background = '#f8fafc';
                }

                doc.querySelectorAll('.export-controls, #exportMenu').forEach(function (el) {
                    el.style.display = 'none';
                });

                const dayPanels = Array.from(doc.querySelectorAll('[data-day-panel]'));
                dayPanels.forEach(function (panel) {
                    panel.classList.remove('hidden');
                    panel.classList.add('active');
                    panel.style.display = 'block';

                    const dayName = String(panel.getAttribute('data-day-panel') || '').trim();
                    if (dayName !== '' && !panel.querySelector('.export-day-heading')) {
                        const heading = doc.createElement('div');
                        heading.className = 'export-day-heading';
                        heading.textContent = dayName;
                        panel.insertBefore(heading, panel.firstChild);
                    }
                });
            }

            function saveCanvasAsPdf(canvas, filename) {
                const jsPDFCtor = window.jspdf && window.jspdf.jsPDF;
                if (!jsPDFCtor) {
                    throw new Error('PDF library is not available.');
                }

                const pdf = new jsPDFCtor({
                    orientation: 'landscape',
                    unit: 'pt',
                    format: 'a4',
                    compress: true,
                });

                const pageWidth = pdf.internal.pageSize.getWidth();
                const pageHeight = pdf.internal.pageSize.getHeight();
                const margin = 20;
                const printableWidth = pageWidth - (margin * 2);
                const printableHeight = pageHeight - (margin * 2);
                const imageData = canvas.toDataURL('image/png');
                const scale = Math.min(printableWidth / canvas.width, printableHeight / canvas.height);
                const renderWidth = canvas.width * scale;
                const renderHeight = canvas.height * scale;
                const renderX = (pageWidth - renderWidth) / 2;
                const renderY = (pageHeight - renderHeight) / 2;

                pdf.addImage(imageData, 'PNG', renderX, renderY, renderWidth, renderHeight, undefined, 'FAST');

                pdf.save(filename);
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
                    backgroundColor: '#f8fafc',
                    scale: Math.min(2.5, Math.max(2, window.devicePixelRatio || 1)),
                    useCORS: true,
                    scrollX: 0,
                    scrollY: 0,
                    windowWidth: 1540,
                    windowHeight: Math.max(document.documentElement.clientHeight, target.scrollHeight),
                    onclone: function (doc) {
                        prepareClonedScheduleForExport(doc);
                    },
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
                    if (format === 'excel') {
                        exportExcelFile();
                    } else {
                        const canvas = await captureScheduleCanvas();

                        if (format === 'png') {
                            triggerDownload(canvas.toDataURL('image/png'), buildExportName('png'));
                        } else if (format === 'jpeg') {
                            triggerDownload(canvas.toDataURL('image/jpeg', 0.95), buildExportName('jpg'));
                        } else if (format === 'pdf') {
                            saveCanvasAsPdf(canvas, buildExportName('pdf'));
                        }
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
                const shouldOpen = exportMenu ? exportMenu.classList.contains('hidden') : false;
                setExportMenuOpen(shouldOpen);
            });

            exportOptions.forEach(function (optionBtn) {
                optionBtn.addEventListener('click', function (event) {
                    event.preventDefault();
                    event.stopPropagation();
                    const fmt = String(optionBtn.getAttribute('data-export-format') || '').toLowerCase();
                    if (fmt === 'excel' || fmt === 'pdf' || fmt === 'png' || fmt === 'jpeg') {
                        exportSchedule(fmt);
                    }
                });
            });

            document.addEventListener('click', function (event) {
                const target = event.target;
                if (!(target instanceof Element)) {
                    return;
                }
                if (exportMenu?.contains(target) || exportBtn?.contains(target)) {
                    return;
                }
                setExportMenuOpen(false);
            });

            document.addEventListener('keydown', function (event) {
                if (event.key === 'Escape') {
                    setExportMenuOpen(false);
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

        (function () {
            const tabs = Array.from(document.querySelectorAll('.day-tab'));
            const panels = Array.from(document.querySelectorAll('.day-panel'));
            const prevBtn = document.getElementById('weeklyPrevBtn');
            const nextBtn = document.getElementById('weeklyNextBtn');

            function getActiveDay() {
                const activeTab = tabs.find(function (tab) {
                    return tab.classList.contains('active');
                });
                return activeTab ? activeTab.getAttribute('data-day') : null;
            }

            function setActive(day) {
                tabs.forEach(function (tab) {
                    const on = tab.getAttribute('data-day') === day;
                    tab.classList.toggle('active', on);
                    tab.classList.toggle('bg-slate-100', !on);
                    tab.classList.toggle('text-slate-500', !on);
                });

                panels.forEach(function (panel) {
                    const on = panel.getAttribute('data-day-panel') === day;
                    panel.classList.toggle('active', on);
                });
            }

            tabs.forEach(function (tab) {
                tab.addEventListener('click', function () {
                    setActive(tab.getAttribute('data-day'));
                });
            });

            function shiftDay(offset) {
                if (tabs.length === 0) {
                    return;
                }

                const days = tabs.map(function (tab) {
                    return tab.getAttribute('data-day');
                });

                const current = getActiveDay() || days[0];
                const index = days.indexOf(current);
                const currentIndex = index >= 0 ? index : 0;
                const nextIndex = (currentIndex + offset + days.length) % days.length;
                setActive(days[nextIndex]);
            }

            if (prevBtn) {
                prevBtn.addEventListener('click', function () {
                    shiftDay(-1);
                });
            }

            if (nextBtn) {
                nextBtn.addEventListener('click', function () {
                    shiftDay(1);
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
            const durationEl = document.getElementById('scheduleModalDuration');
            const roomEl = document.getElementById('scheduleModalRoom');
            const roomTypeEl = document.getElementById('scheduleModalRoomType');
            const enrollmentEl = document.getElementById('scheduleModalEnrollment');

            const scheduleDetails = <?php echo json_encode($schedule_modal_map, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
            const requestedScheduleId = <?php echo isset($_GET['schedule_id']) ? (int) $_GET['schedule_id'] : 0; ?>;

            if (!modal) {
                return;
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

                courseNameEl.textContent = detail.course_name || 'Class details';
                courseCodeEl.textContent = detail.course_code || 'No course code';
                dayEl.textContent = detail.day_of_week || '-';
                timeEl.textContent = detail.time_range || '-';
                if (durationEl) durationEl.textContent = detail.duration_label || 'N/A';
                roomEl.textContent = detail.room_number || 'TBA';
                roomTypeEl.textContent = detail.room_type || '-';
                enrollmentEl.textContent = maxStudents > 0
                    ? (enrolled + ' / ' + maxStudents + ' students')
                    : (enrolled + ' students');

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

            if (requestedScheduleId > 0) {
                openModal(requestedScheduleId);
                const url = new URL(window.location.href);
                url.searchParams.delete('schedule_id');
                window.history.replaceState({}, '', url.toString());
            }
        })();
    </script>
</body>
</html>
