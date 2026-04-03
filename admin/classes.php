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
foreach ($classes as $row) {
    if (($row['status'] ?? '') === 'active') {
        $active_count++;
    }
}

function time_range_label($start, $end) {
    if (!$start || !$end) return '';
    return date('g:i A', strtotime($start)) . ' - ' . date('g:i A', strtotime($end));
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
                        <table class="min-w-full text-sm">
                            <thead class="text-xs uppercase tracking-wider text-slate-500 bg-slate-50">
                                <tr>
                                    <th class="px-5 py-3 text-left font-semibold">Subject</th>
                                    <th class="px-5 py-3 text-left font-semibold">Subject</th>
                                    <th class="px-5 py-3 text-left font-semibold">Instructor</th>
                                    <th class="px-5 py-3 text-left font-semibold">Room</th>
                                    <th class="px-5 py-3 text-left font-semibold">Schedule</th>
                                    <th class="px-5 py-3 text-left font-semibold">Enrollment</th>
                                    <th class="px-5 py-3 text-left font-semibold">Status</th>
                                    <th class="px-5 py-3 text-right font-semibold">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($classes as $class): ?>
                                    <?php
                                        $enrolled = (int)($class['enrolled_students'] ?? 0);
                                        $max = (int)($class['max_students'] ?? 0);
                                        $pct = 0;
                                        if ($max > 0) {
                                            $pct = (int)round(($enrolled / $max) * 100);
                                            if ($pct < 0) $pct = 0;
                                            if ($pct > 100) $pct = 100;
                                        }
                                        $display_code = $class['subject_code'] ?? $class['course_code'] ?? '';
                                        $display_name = $class['subject_name'] ?? $class['course_name'] ?? '';
                                    ?>
                                    <tr class="class-row border-t border-slate-100 hover:bg-slate-50/60" data-search="<?php echo htmlspecialchars(strtolower(($display_name) . ' ' . ($display_code) . ' ' . ($class['instructor_name'] ?? '') . ' ' . ($class['room_number'] ?? ''))); ?>">
                                        <td class="px-5 py-4">
                                            <div class="font-semibold text-slate-900"><?php echo htmlspecialchars($display_name); ?></div>
                                            <div class="text-xs text-slate-500"><?php echo htmlspecialchars($display_code); ?></div>
                                        </td>
                                        <td class="px-5 py-4">
                                            <span class="inline-flex items-center rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-700">
                                                <?php echo htmlspecialchars($display_code); ?>
                                            </span>
                                        </td>
                                        <td class="px-5 py-4 text-slate-700"><?php echo htmlspecialchars($class['instructor_name'] ?? ''); ?></td>
                                        <td class="px-5 py-4 text-slate-700"><?php echo htmlspecialchars($class['room_number'] ?? ''); ?></td>
                                        <td class="px-5 py-4">
                                            <div class="text-slate-700"><?php echo htmlspecialchars($class['day_of_week'] ?? ''); ?></div>
                                            <div class="text-xs text-slate-500"><?php echo htmlspecialchars(time_range_label($class['start_time'] ?? '', $class['end_time'] ?? '')); ?></div>
                                        </td>
                                        <td class="px-5 py-4">
                                            <div class="flex items-center gap-3">
                                                <div class="min-w-[76px] text-slate-700">
                                                    <?php echo htmlspecialchars($enrolled . '/' . ($max > 0 ? $max : '—')); ?>
                                                </div>
                                                <div class="w-28 h-2 rounded-full bg-slate-100 overflow-hidden">
                                                    <div class="h-full bg-emerald-500" style="width: <?php echo (int)$pct; ?>%"></div>
                                                </div>
                                                <div class="w-10 text-right text-xs text-slate-500"><?php echo (int)$pct; ?>%</div>
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

                                <?php if (empty($classes)): ?>
                                    <tr>
                                        <td colspan="8" class="px-5 py-10 text-center text-slate-500">No classes found</td>
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
        <div class="relative mx-auto my-10 w-[92%] max-w-2xl">
            <div class="rounded-2xl bg-white shadow-xl border border-slate-200 overflow-hidden">
                <div class="px-5 py-4 border-b border-slate-200 flex items-center justify-between">
                    <div class="text-base font-semibold text-slate-900">Add Class</div>
                    <button type="button" class="h-9 w-9 inline-flex items-center justify-center rounded-xl hover:bg-slate-100" data-modal-close="addClassModal" aria-label="Close">
                        <i class="bi bi-x-lg"></i>
                    </button>
                </div>

                <form action="process_class.php" method="POST" class="p-5">
                    <input type="hidden" name="action" value="add">

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-semibold text-slate-700 mb-1">Course</label>
                            <select name="course_id" id="add_course_id" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm" required>
                                <option value="" selected disabled>Select course</option>
                                <?php foreach ($courses as $c): ?>
                                    <option value="<?php echo (int)$c['id']; ?>"><?php echo htmlspecialchars(($c['course_code'] ?? '') . ' — ' . ($c['course_name'] ?? '')); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div>
                            <label class="block text-sm font-semibold text-slate-700 mb-1">Year Level</label>
                            <select name="year_level" id="add_year_level" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm" required>
                                <option value="" selected disabled>Select year level</option>
                                <option value="1">1st Year</option>
                                <option value="2">2nd Year</option>
                                <option value="3">3rd Year</option>
                                <option value="4">4th Year</option>
                            </select>
                        </div>

                        <div>
                            <label class="block text-sm font-semibold text-slate-700 mb-1">Subject</label>
                            <select name="subject_id" id="add_subject_id" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm" <?php echo $subjects_enabled ? 'required' : ''; ?> <?php echo $subjects_enabled ? '' : 'disabled'; ?>>
                                <option value="" selected disabled>Select subject</option>
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
                                <option value="" selected disabled>Select instructor</option>
                                <?php foreach ($instructors as $ins): ?>
                                    <option value="<?php echo (int)$ins['id']; ?>"><?php echo htmlspecialchars(trim(($ins['last_name'] ?? '') . ', ' . ($ins['first_name'] ?? ''))); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div>
                            <label class="block text-sm font-semibold text-slate-700 mb-1">Room</label>
                            <select name="classroom_id" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm" required>
                                <option value="" selected disabled>Select room</option>
                                <?php foreach ($classrooms as $r): ?>
                                    <option value="<?php echo (int)$r['id']; ?>"><?php echo htmlspecialchars(($r['room_number'] ?? '') . ' (' . ($r['room_type'] ?? '') . ')'); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div>
                            <label class="block text-sm font-semibold text-slate-700 mb-1">Day</label>
                            <select name="day_of_week" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm" required>
                                <option value="" selected disabled>Select day</option>
                                <option>Monday</option>
                                <option>Tuesday</option>
                                <option>Wednesday</option>
                                <option>Thursday</option>
                                <option>Friday</option>
                                <option>Saturday</option>
                            </select>
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
                            <label class="block text-sm font-semibold text-slate-700 mb-1">Max students</label>
                            <input type="number" name="max_students" min="1" value="30" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm">
                        </div>

                        <div>
                            <label class="block text-sm font-semibold text-slate-700 mb-1">Status</label>
                            <select name="status" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm">
                                <option value="active" selected>Active</option>
                                <option value="inactive">Inactive</option>
                                <option value="cancelled">Cancelled</option>
                                <option value="completed">Completed</option>
                            </select>
                        </div>

                        <div>
                            <label class="block text-sm font-semibold text-slate-700 mb-1">Semester</label>
                            <input type="text" name="semester" value="1st Semester" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm">
                        </div>

                        <div>
                            <label class="block text-sm font-semibold text-slate-700 mb-1">Academic year</label>
                            <input type="text" name="academic_year" value="<?php echo htmlspecialchars(date('Y') . '-' . (date('Y') + 1)); ?>" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm">
                        </div>
                    </div>

                    <div class="mt-5 flex items-center justify-end gap-3">
                        <button type="button" class="inline-flex items-center rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-50" data-modal-close="addClassModal">Cancel</button>
                        <button type="submit" class="inline-flex items-center rounded-xl bg-emerald-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-emerald-700">Add Class</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Class Modal -->
    <div id="editClassModal" class="fixed inset-0 z-50 hidden" aria-hidden="true">
        <div class="absolute inset-0 bg-slate-900/50" data-modal-close="editClassModal"></div>
        <div class="relative mx-auto my-10 w-[92%] max-w-2xl">
            <div class="rounded-2xl bg-white shadow-xl border border-slate-200 overflow-hidden">
                <div class="px-5 py-4 border-b border-slate-200 flex items-center justify-between">
                    <div class="text-base font-semibold text-slate-900">Edit Class</div>
                    <button type="button" class="h-9 w-9 inline-flex items-center justify-center rounded-xl hover:bg-slate-100" data-modal-close="editClassModal" aria-label="Close">
                        <i class="bi bi-x-lg"></i>
                    </button>
                </div>

                <form action="process_class.php" method="POST" class="p-5">
                    <input type="hidden" name="action" value="edit">
                    <input type="hidden" name="schedule_id" id="edit_schedule_id">

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-semibold text-slate-700 mb-1">Course</label>
                            <select name="course_id" id="edit_course_id" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm" required>
                                <option value="" disabled>Select course</option>
                                <?php foreach ($courses as $c): ?>
                                    <option value="<?php echo (int)$c['id']; ?>"><?php echo htmlspecialchars(($c['course_code'] ?? '') . ' — ' . ($c['course_name'] ?? '')); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div>
                            <label class="block text-sm font-semibold text-slate-700 mb-1">Year Level</label>
                            <select name="year_level" id="edit_year_level" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm" required>
                                <option value="" disabled>Select year level</option>
                                <option value="1">1st Year</option>
                                <option value="2">2nd Year</option>
                                <option value="3">3rd Year</option>
                                <option value="4">4th Year</option>
                            </select>
                        </div>

                        <div>
                            <label class="block text-sm font-semibold text-slate-700 mb-1">Subject</label>
                            <select name="subject_id" id="edit_subject_id" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm" <?php echo $subjects_enabled ? 'required' : ''; ?> <?php echo $subjects_enabled ? '' : 'disabled'; ?>>
                                <option value="" disabled>Select subject</option>
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
                                <option value="" disabled>Select instructor</option>
                                <?php foreach ($instructors as $ins): ?>
                                    <option value="<?php echo (int)$ins['id']; ?>"><?php echo htmlspecialchars(trim(($ins['last_name'] ?? '') . ', ' . ($ins['first_name'] ?? ''))); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div>
                            <label class="block text-sm font-semibold text-slate-700 mb-1">Room</label>
                            <select name="classroom_id" id="edit_classroom_id" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm" required>
                                <option value="" disabled>Select room</option>
                                <?php foreach ($classrooms as $r): ?>
                                    <option value="<?php echo (int)$r['id']; ?>"><?php echo htmlspecialchars(($r['room_number'] ?? '') . ' (' . ($r['room_type'] ?? '') . ')'); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div>
                            <label class="block text-sm font-semibold text-slate-700 mb-1">Day</label>
                            <select name="day_of_week" id="edit_day_of_week" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm" required>
                                <option value="" disabled>Select day</option>
                                <option>Monday</option>
                                <option>Tuesday</option>
                                <option>Wednesday</option>
                                <option>Thursday</option>
                                <option>Friday</option>
                                <option>Saturday</option>
                            </select>
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
                            <label class="block text-sm font-semibold text-slate-700 mb-1">Max students</label>
                            <input type="number" name="max_students" id="edit_max_students" min="1" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm">
                        </div>

                        <div>
                            <label class="block text-sm font-semibold text-slate-700 mb-1">Status</label>
                            <select name="status" id="edit_status" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm">
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                                <option value="cancelled">Cancelled</option>
                                <option value="completed">Completed</option>
                            </select>
                        </div>

                        <div>
                            <label class="block text-sm font-semibold text-slate-700 mb-1">Semester</label>
                            <input type="text" name="semester" id="edit_semester" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm">
                        </div>

                        <div>
                            <label class="block text-sm font-semibold text-slate-700 mb-1">Academic year</label>
                            <input type="text" name="academic_year" id="edit_academic_year" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm">
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

            document.getElementById('addClassBtn')?.addEventListener('click', function () {
                openModal('addClassModal');
            });
            document.querySelectorAll('[data-modal-close]')?.forEach(function (btn) {
                btn.addEventListener('click', function () {
                    const target = btn.getAttribute('data-modal-close');
                    if (target) closeModal(target);
                });
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
            addYear?.addEventListener('change', function () {
                filterSubjectsByYear(addSubj, addYear.value);
            });

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
                document.getElementById('edit_day_of_week').value = classData.day_of_week || '';
                document.getElementById('edit_start_time').value = classData.start_time || '';
                document.getElementById('edit_end_time').value = classData.end_time || '';
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