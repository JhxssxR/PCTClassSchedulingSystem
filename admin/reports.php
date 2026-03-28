<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is an admin
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'super_admin') {
    header('Location: ../auth/login.php');
    exit();
}

require_once __DIR__ . '/notifications_data.php';

function table_columns(PDO $conn, string $table): array {
    $stmt = $conn->prepare("DESCRIBE `{$table}`");
    $stmt->execute();
    $cols = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $cols[$row['Field']] = true;
    }
    return $cols;
}

function enrollment_status_map(PDO $conn): array {
    $stmt = $conn->prepare("SHOW COLUMNS FROM enrollments LIKE 'status'");
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $type = (string)($row['Type'] ?? '');

    $allowed = [];
    if (preg_match("/^enum\((.*)\)$/i", $type, $m)) {
        $vals = str_getcsv($m[1], ',', "'");
        foreach ($vals as $v) {
            $allowed[strtolower(trim($v))] = true;
        }
    }

    $active = isset($allowed['enrolled']) ? 'enrolled' : (isset($allowed['approved']) ? 'approved' : 'enrolled');
    return [
        'active' => $active,
        'pending' => 'pending',
        'rejected' => 'rejected',
        'dropped' => 'dropped',
    ];
}

$db_error = null;
$active_status = 'enrolled';
$has_capacity = false;
$enrollment_stats = [];
$course_stats = [];
$instructor_stats = [];
$classroom_stats = [];

try {
    $status_map = enrollment_status_map($conn);
    $active_status = $status_map['active'];

    $classroom_cols = table_columns($conn, 'classrooms');
    $has_capacity = isset($classroom_cols['capacity']);

    // Enrollment stats
    $stmt = $conn->prepare("SELECT
        COUNT(*) as total_enrollments,
        SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as active_enrollments,
        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_enrollments,
        SUM(CASE WHEN status = 'dropped' THEN 1 ELSE 0 END) as dropped_enrollments,
        SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected_enrollments
        FROM enrollments
    ");
    $stmt->execute([$active_status]);
    $enrollment_stats = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    // Course statistics
    $stmt = $conn->prepare("SELECT
        c.course_code,
        c.course_name,
        COUNT(DISTINCT s.id) as total_schedules,
        COUNT(DISTINCT e.id) as total_enrollments,
        COUNT(DISTINCT CASE WHEN e.status = ? THEN e.id END) as active_enrollments
        FROM courses c
        LEFT JOIN schedules s ON c.id = s.course_id
        LEFT JOIN enrollments e ON s.id = e.schedule_id
        GROUP BY c.id
        ORDER BY c.course_code
    ");
    $stmt->execute([$active_status]);
    $course_stats = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // Instructor statistics
    $stmt = $conn->prepare("SELECT
        CONCAT(i.first_name, ' ', i.last_name) as instructor_name,
        COUNT(DISTINCT s.id) as total_schedules,
        COUNT(DISTINCT e.id) as total_enrollments,
        COUNT(DISTINCT CASE WHEN e.status = ? THEN e.id END) as active_enrollments
        FROM users i
        LEFT JOIN schedules s ON i.id = s.instructor_id
        LEFT JOIN enrollments e ON s.id = e.schedule_id
        WHERE i.role = 'instructor'
        GROUP BY i.id
        ORDER BY i.last_name, i.first_name
    ");
    $stmt->execute([$active_status]);
    $instructor_stats = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // Classroom utilization
    $cap_expr = $has_capacity ? 'r.capacity' : '0';
    $stmt = $conn->prepare("SELECT
        r.room_number,
        {$cap_expr} as capacity,
        COUNT(DISTINCT s.id) as total_schedules,
        COUNT(DISTINCT e.id) as total_enrollments,
        COUNT(DISTINCT CASE WHEN e.status = ? THEN e.id END) as active_enrollments
        FROM classrooms r
        LEFT JOIN schedules s ON r.id = s.classroom_id
        LEFT JOIN enrollments e ON s.id = e.schedule_id
        GROUP BY r.id
        ORDER BY r.room_number
    ");
    $stmt->execute([$active_status]);
    $classroom_stats = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (PDOException $e) {
    $db_error = $e->getMessage();
    error_log('Error in admin reports: ' . $e->getMessage());
}

// Chart data
$chart_labels = ['Active', 'Pending', 'Dropped', 'Rejected'];
$chart_values = [
    (int)($enrollment_stats['active_enrollments'] ?? 0),
    (int)($enrollment_stats['pending_enrollments'] ?? 0),
    (int)($enrollment_stats['dropped_enrollments'] ?? 0),
    (int)($enrollment_stats['rejected_enrollments'] ?? 0),
];

$top_courses = array_slice($course_stats, 0, 8);
$top_course_labels = array_map(fn($r) => (string)($r['course_code'] ?? ''), $top_courses);
$top_course_values = array_map(fn($r) => (int)($r['active_enrollments'] ?? 0), $top_courses);

$user_initials = 'SA';
$full_name = 'Super Admin';
if (!empty($_SESSION['first_name']) || !empty($_SESSION['last_name'])) {
    $full_name = trim(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? ''));
    $first = strtoupper(substr((string)($_SESSION['first_name'] ?? ''), 0, 1));
    $last = strtoupper(substr((string)($_SESSION['last_name'] ?? ''), 0, 1));
    $user_initials = trim($first . $last);
    if ($user_initials === '') $user_initials = 'SA';
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports - PCT Class Scheduling</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
</head>
<body class="bg-slate-50 text-slate-900">

<!-- Tailwind color palette for charts (read via JS) -->
<div id="chartPalette" class="fixed left-0 top-0 opacity-0 pointer-events-none -z-10">
    <div id="c-emerald" class="h-1 w-1 bg-emerald-500"></div>
    <div id="c-emerald-dark" class="h-1 w-1 bg-emerald-600"></div>
    <div id="c-amber" class="h-1 w-1 bg-amber-500"></div>
    <div id="c-slate" class="h-1 w-1 bg-slate-500"></div>
    <div id="c-rose" class="h-1 w-1 bg-rose-500"></div>
</div>

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
                <a href="dashboard.php" class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-emerald-50/80 hover:text-emerald-50 hover:bg-emerald-900/30"><i class="bi bi-speedometer2"></i><span class="text-sm font-medium">Dashboard</span></a>
                <a href="users.php" class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-emerald-50/80 hover:text-emerald-50 hover:bg-emerald-900/30"><i class="bi bi-people"></i><span class="text-sm font-medium">All Users</span></a>
                <a href="instructors.php" class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-emerald-50/80 hover:text-emerald-50 hover:bg-emerald-900/30"><i class="bi bi-person-video3"></i><span class="text-sm font-medium">Instructors</span></a>
                <a href="students.php" class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-emerald-50/80 hover:text-emerald-50 hover:bg-emerald-900/30"><i class="bi bi-mortarboard"></i><span class="text-sm font-medium">Students</span></a>
                <a href="classes.php" class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-emerald-50/80 hover:text-emerald-50 hover:bg-emerald-900/30"><i class="bi bi-book"></i><span class="text-sm font-medium">Classes</span></a>
                <a href="classrooms.php" class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-emerald-50/80 hover:text-emerald-50 hover:bg-emerald-900/30"><i class="bi bi-door-open"></i><span class="text-sm font-medium">Classrooms</span></a>
                <a href="subjects.php" class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-emerald-50/80 hover:text-emerald-50 hover:bg-emerald-900/30"><i class="bi bi-journal-bookmark"></i><span class="text-sm font-medium">Subjects</span></a>
                <a href="courses.php" class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-emerald-50/80 hover:text-emerald-50 hover:bg-emerald-900/30"><i class="bi bi-journal-text"></i><span class="text-sm font-medium">Courses</span></a>
                <a href="schedules.php" class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-emerald-50/80 hover:text-emerald-50 hover:bg-emerald-900/30"><i class="bi bi-calendar3"></i><span class="text-sm font-medium">Schedules</span></a>
                <a href="enrollments.php" class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-emerald-50/80 hover:text-emerald-50 hover:bg-emerald-900/30"><i class="bi bi-person-plus"></i><span class="text-sm font-medium">Enrollments</span></a>
                <a href="reports.php" class="flex items-center gap-3 px-3 py-2.5 rounded-xl bg-emerald-900/40 text-emerald-50"><i class="bi bi-file-earmark-text"></i><span class="text-sm font-medium">Reports</span></a>
                <a href="settings.php" class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-emerald-50/80 hover:text-emerald-50 hover:bg-emerald-900/30"><i class="bi bi-gear"></i><span class="text-sm font-medium">Settings</span></a>
            </nav>
        </div>

        <div class="absolute bottom-0 left-0 right-0 p-4">
            <a href="../auth/logout.php" class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-rose-200 hover:text-rose-100 hover:bg-rose-500/15 border border-transparent hover:border-rose-400/20">
                <i class="bi bi-box-arrow-right text-rose-300"></i>
                <span class="text-sm font-semibold">Logout</span>
            </a>
        </div>
    </aside>

    <div id="sidebarOverlay" class="fixed inset-0 z-30 hidden bg-slate-900/40 lg:hidden"></div>

    <!-- Main -->
    <div class="lg:pl-72">
        <header class="sticky top-0 z-20 h-16 bg-white/90 backdrop-blur border-b border-slate-200">
            <div class="h-full px-4 sm:px-6 flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <button id="sidebarBtn" type="button" class="lg:hidden inline-flex h-10 w-10 items-center justify-center rounded-xl border border-slate-200 bg-white hover:bg-slate-50">
                        <i class="bi bi-list text-xl"></i>
                    </button>
                    <div class="flex items-center gap-2 text-sm">
                        <span class="text-slate-500">Super Admin</span>
                        <span class="text-slate-300">/</span>
                        <span class="font-semibold text-slate-900">Reports</span>
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
                                    <div class="flex items-center gap-2">
                                        <button id="notifMarkRead" type="button" class="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 bg-white px-2.5 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50">
                                            <i class="bi bi-check2"></i>
                                            <span>Mark as read</span>
                                        </button>
                                        <button id="notifDelete" type="button" class="inline-flex items-center justify-center rounded-lg border border-slate-200 bg-white h-8 w-8 text-slate-700 hover:bg-slate-50" aria-label="Delete notifications">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </div>
                                </div>
                                <div class="p-4">
                                    <?php if (empty($notif_items)): ?>
                                        <div class="rounded-xl border border-slate-200 bg-slate-50 p-3 text-sm text-slate-700">No new notifications.</div>
                                    <?php else: ?>
                                        <div class="space-y-2">
                                            <?php foreach ($notif_items as $it): ?>
                                                <a href="<?php echo htmlspecialchars($it['href'] ?? '#'); ?>" class="block rounded-xl border border-slate-200 bg-white p-3 hover:bg-slate-50">
                                                    <div class="flex items-start gap-3">
                                                        <div class="mt-0.5 inline-flex h-9 w-9 items-center justify-center rounded-xl bg-slate-100 text-slate-700">
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
                        <div class="h-10 w-10 rounded-full bg-emerald-600 text-white flex items-center justify-center font-semibold"><?php echo htmlspecialchars($user_initials); ?></div>
                        <div class="text-left">
                            <div class="text-sm font-semibold text-slate-900"><?php echo htmlspecialchars($full_name); ?></div>
                            <div class="text-xs text-slate-500">PCT System</div>
                        </div>
                    </div>
                </div>
            </div>
        </header>

        <main class="px-4 sm:px-6 py-6">
            <div>
                <h1 class="text-2xl font-semibold text-slate-900">Reports</h1>
                <p class="text-sm text-slate-500">Enrollment insights and utilization overview</p>
            </div>

            <?php if (!empty($db_error)): ?>
                <div class="mt-4 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-amber-800">
                    Some report data could not be loaded. Please verify your database schema.
                </div>
            <?php endif; ?>

            <!-- Stats -->
            <section class="mt-6 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                    <div class="text-xs uppercase tracking-wider text-slate-500">Total</div>
                    <div class="mt-2 text-2xl font-semibold text-slate-900"><?php echo (int)($enrollment_stats['total_enrollments'] ?? 0); ?></div>
                    <div class="mt-1 text-sm text-slate-500">All enrollments</div>
                </div>
                <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                    <div class="text-xs uppercase tracking-wider text-slate-500">Active</div>
                    <div class="mt-2 text-2xl font-semibold text-slate-900"><?php echo (int)($enrollment_stats['active_enrollments'] ?? 0); ?></div>
                    <div class="mt-1 text-sm text-slate-500">Status: <?php echo htmlspecialchars($active_status); ?></div>
                </div>
                <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                    <div class="text-xs uppercase tracking-wider text-slate-500">Pending</div>
                    <div class="mt-2 text-2xl font-semibold text-slate-900"><?php echo (int)($enrollment_stats['pending_enrollments'] ?? 0); ?></div>
                    <div class="mt-1 text-sm text-slate-500">Needs review</div>
                </div>
                <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                    <div class="text-xs uppercase tracking-wider text-slate-500">Dropped</div>
                    <div class="mt-2 text-2xl font-semibold text-slate-900"><?php echo (int)($enrollment_stats['dropped_enrollments'] ?? 0); ?></div>
                    <div class="mt-1 text-sm text-slate-500">No longer enrolled</div>
                </div>
            </section>

            <!-- Charts -->
            <section class="mt-6 grid grid-cols-1 lg:grid-cols-2 gap-5">
                <div class="rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden">
                    <div class="px-5 py-4 border-b border-slate-200">
                        <div class="text-base font-semibold text-slate-900">Enrollment Status</div>
                        <div class="text-sm text-slate-500">Distribution by status</div>
                    </div>
                    <div class="p-5">
                        <canvas id="statusChart" height="160"></canvas>
                    </div>
                </div>
                <div class="rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden">
                    <div class="px-5 py-4 border-b border-slate-200">
                        <div class="text-base font-semibold text-slate-900">Top Courses</div>
                        <div class="text-sm text-slate-500">Active enrollments by course</div>
                    </div>
                    <div class="p-5">
                        <canvas id="courseChart" height="160"></canvas>
                    </div>
                </div>
            </section>

            <!-- Tables -->
            <section class="mt-6 rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden">
                <div class="px-5 py-4 border-b border-slate-200">
                    <div class="text-base font-semibold text-slate-900">Course Statistics</div>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="text-xs uppercase tracking-wider text-slate-500 bg-slate-50">
                            <tr>
                                <th class="px-5 py-3 text-left font-semibold">Course</th>
                                <th class="px-5 py-3 text-left font-semibold">Schedules</th>
                                <th class="px-5 py-3 text-left font-semibold">Enrollments</th>
                                <th class="px-5 py-3 text-left font-semibold">Active</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($course_stats as $course): ?>
                                <tr class="border-t border-slate-100 hover:bg-slate-50/60">
                                    <td class="px-5 py-4">
                                        <div class="font-semibold text-slate-900"><?php echo htmlspecialchars((string)($course['course_code'] ?? '')); ?></div>
                                        <div class="text-xs text-slate-500"><?php echo htmlspecialchars((string)($course['course_name'] ?? '')); ?></div>
                                    </td>
                                    <td class="px-5 py-4 text-slate-700"><?php echo (int)($course['total_schedules'] ?? 0); ?></td>
                                    <td class="px-5 py-4 text-slate-700"><?php echo (int)($course['total_enrollments'] ?? 0); ?></td>
                                    <td class="px-5 py-4 text-slate-700"><?php echo (int)($course['active_enrollments'] ?? 0); ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (empty($course_stats)): ?>
                                <tr><td colspan="4" class="px-5 py-10 text-center text-slate-500">No course data</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="mt-6 grid grid-cols-1 lg:grid-cols-2 gap-5">
                <div class="rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden">
                    <div class="px-5 py-4 border-b border-slate-200">
                        <div class="text-base font-semibold text-slate-900">Instructor Statistics</div>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead class="text-xs uppercase tracking-wider text-slate-500 bg-slate-50">
                                <tr>
                                    <th class="px-5 py-3 text-left font-semibold">Instructor</th>
                                    <th class="px-5 py-3 text-left font-semibold">Schedules</th>
                                    <th class="px-5 py-3 text-left font-semibold">Active</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($instructor_stats as $ins): ?>
                                    <tr class="border-t border-slate-100 hover:bg-slate-50/60">
                                        <td class="px-5 py-4 font-semibold text-slate-900"><?php echo htmlspecialchars((string)($ins['instructor_name'] ?? '')); ?></td>
                                        <td class="px-5 py-4 text-slate-700"><?php echo (int)($ins['total_schedules'] ?? 0); ?></td>
                                        <td class="px-5 py-4 text-slate-700"><?php echo (int)($ins['active_enrollments'] ?? 0); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (empty($instructor_stats)): ?>
                                    <tr><td colspan="3" class="px-5 py-10 text-center text-slate-500">No instructor data</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden">
                    <div class="px-5 py-4 border-b border-slate-200">
                        <div class="text-base font-semibold text-slate-900">Classroom Utilization</div>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead class="text-xs uppercase tracking-wider text-slate-500 bg-slate-50">
                                <tr>
                                    <th class="px-5 py-3 text-left font-semibold">Room</th>
                                    <th class="px-5 py-3 text-left font-semibold">Capacity</th>
                                    <th class="px-5 py-3 text-left font-semibold">Active</th>
                                    <th class="px-5 py-3 text-left font-semibold">Utilization</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($classroom_stats as $room): ?>
                                    <?php
                                        $cap = (int)($room['capacity'] ?? 0);
                                        $active = (int)($room['active_enrollments'] ?? 0);
                                        $util = $cap > 0 ? round(($active / $cap) * 100, 1) : 0;
                                    ?>
                                    <tr class="border-t border-slate-100 hover:bg-slate-50/60">
                                        <td class="px-5 py-4 font-semibold text-slate-900"><?php echo htmlspecialchars((string)($room['room_number'] ?? '')); ?></td>
                                        <td class="px-5 py-4 text-slate-700"><?php echo $cap; ?></td>
                                        <td class="px-5 py-4 text-slate-700"><?php echo $active; ?></td>
                                        <td class="px-5 py-4 text-slate-700"><?php echo htmlspecialchars((string)$util); ?>%</td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (empty($classroom_stats)): ?>
                                    <tr><td colspan="4" class="px-5 py-10 text-center text-slate-500">No classroom data</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>
        </main>
    </div>
</div>

<script>
(function () {
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebarOverlay');
    const btn = document.getElementById('sidebarBtn');

    function openSidebar() {
        sidebar?.classList.remove('-translate-x-full');
        overlay?.classList.remove('hidden');
    }
    function closeSidebar() {
        sidebar?.classList.add('-translate-x-full');
        overlay?.classList.add('hidden');
    }
    btn?.addEventListener('click', function () {
        if (sidebar.classList.contains('-translate-x-full')) openSidebar();
        else closeSidebar();
    });
    overlay?.addEventListener('click', closeSidebar);

    const statusLabels = <?php echo json_encode($chart_labels); ?>;
    const statusValues = <?php echo json_encode($chart_values); ?>;
    const courseLabels = <?php echo json_encode($top_course_labels); ?>;
    const courseValues = <?php echo json_encode($top_course_values); ?>;

    function colorFrom(id) {
        const el = document.getElementById(id);
        if (!el) return undefined;
        return window.getComputedStyle(el).backgroundColor;
    }

    const emerald = colorFrom('c-emerald');
    const amber = colorFrom('c-amber');
    const slate = colorFrom('c-slate');
    const rose = colorFrom('c-rose');
    const emeraldDark = colorFrom('c-emerald-dark');

    const ctx1 = document.getElementById('statusChart');
    if (ctx1) {
        new Chart(ctx1, {
            type: 'doughnut',
            data: {
                labels: statusLabels,
                datasets: [{
                    data: statusValues,
                    backgroundColor: [emerald, amber, slate, rose],
                    borderWidth: 0,
                }]
            },
            options: {
                plugins: {
                    legend: { position: 'bottom' }
                }
            }
        });
    }

    const ctx2 = document.getElementById('courseChart');
    if (ctx2) {
        new Chart(ctx2, {
            type: 'bar',
            data: {
                labels: courseLabels,
                datasets: [{
                    label: 'Active enrollments',
                    data: courseValues,
                    backgroundColor: emeraldDark,
                    borderRadius: 8,
                }]
            },
            options: {
                responsive: true,
                plugins: { legend: { display: false } },
                scales: {
                    y: { beginAtZero: true, ticks: { precision: 0 } }
                }
            }
        });
    }
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
