<?php
require_once '../config/database.php';
require_once '../includes/session.php';

// Check if user is logged in and is an admin
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'super_admin') {
    header('Location: ../auth/login.php?role=super_admin');
    exit();
}

require_once __DIR__ . '/notifications_data.php';

function admin_dashboard_has_column(PDO $conn, string $table, string $column): bool {
    try {
        $stmt = $conn->query("SHOW COLUMNS FROM `$table` LIKE " . $conn->quote($column));
        return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return false;
    }
}

$has_enrolled_at = admin_dashboard_has_column($conn, 'enrollments', 'enrolled_at');
$has_created_at = admin_dashboard_has_column($conn, 'enrollments', 'created_at');
$has_enrollment_date = admin_dashboard_has_column($conn, 'enrollments', 'enrollment_date');
$has_student_id_code = admin_dashboard_has_column($conn, 'users', 'student_id');

$enrollment_date_expr = 'NOW()';
if ($has_enrolled_at && $has_created_at) {
    $enrollment_date_expr = 'COALESCE(e.enrolled_at, e.created_at)';
} elseif ($has_enrolled_at) {
    $enrollment_date_expr = 'e.enrolled_at';
} elseif ($has_created_at) {
    $enrollment_date_expr = 'e.created_at';
} elseif ($has_enrollment_date) {
    $enrollment_date_expr = 'e.enrollment_date';
}

$student_number_expr = "CONCAT('STU', LPAD(s.id, 6, '0'))";
if ($has_student_id_code) {
    $student_number_expr = "COALESCE(NULLIF(s.student_id, ''), CONCAT('STU', LPAD(s.id, 6, '0')))";
}

// Get system statistics
try {
    $stats = [
        'total_users' => (int)$conn->query("SELECT COUNT(*) FROM users")->fetchColumn(),
        'students' => (int)$conn->query("SELECT COUNT(*) FROM users WHERE role = 'student'")->fetchColumn(),
        'instructors' => (int)$conn->query("SELECT COUNT(*) FROM users WHERE role = 'instructor'")->fetchColumn(),
        'active_classes' => (int)$conn->query("SELECT COUNT(*) FROM schedules WHERE status = 'active'")->fetchColumn(),
        'total_enrollments' => (int)$conn->query("SELECT COUNT(*) FROM enrollments WHERE status IN ('enrolled', 'approved')")->fetchColumn(),
        'enrolled_students' => (int)$conn->query("SELECT COUNT(DISTINCT student_id) FROM enrollments WHERE status IN ('enrolled', 'approved')")->fetchColumn(),
        'courses' => (int)$conn->query("SELECT COUNT(*) FROM courses")->fetchColumn(),
    ];
} catch (PDOException $e) {
    error_log('Error in admin dashboard: ' . $e->getMessage());
    $stats = ['total_users' => 0, 'students' => 0, 'instructors' => 0, 'active_classes' => 0, 'total_enrollments' => 0, 'enrolled_students' => 0, 'courses' => 0];
}

// Enrollment trend (last 6 months)
$enrollment_trend_labels = [];
$enrollment_trend_counts = [];
try {
    $start = (new DateTime('first day of this month'))->modify('-5 months');
    $months = [];
    $cursor = clone $start;
    for ($i = 0; $i < 6; $i++) {
        $months[$cursor->format('Y-m')] = 0;
        $cursor->modify('+1 month');
    }

    $stmt = $conn->prepare("\n        SELECT\n            DATE_FORMAT(" . $enrollment_date_expr . ", '%Y-%m') AS ym,\n            COUNT(*) AS count\n        FROM enrollments e\n        WHERE " . $enrollment_date_expr . " >= ?\n        GROUP BY ym\n        ORDER BY ym ASC\n    ");
    $stmt->execute([$start->format('Y-m-d')]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (isset($months[$row['ym']])) {
            $months[$row['ym']] = (int)$row['count'];
        }
    }

    foreach ($months as $ym => $count) {
        $dt = DateTime::createFromFormat('Y-m', $ym);
        $enrollment_trend_labels[] = $dt ? $dt->format('M') : $ym;
        $enrollment_trend_counts[] = $count;
    }
} catch (PDOException $e) {
    error_log('Error fetching enrollment trend: ' . $e->getMessage());
}

// Classes per weekday (Mon-Fri)
$class_day_counts = ['Mon' => 0, 'Tue' => 0, 'Wed' => 0, 'Thu' => 0, 'Fri' => 0];
try {
    $stmt = $conn->query("\n        SELECT day_of_week, COUNT(*) AS count\n        FROM schedules\n        WHERE status = 'active'\n        GROUP BY day_of_week\n    ");
    $day_map = [
        'monday' => 'Mon',
        'tuesday' => 'Tue',
        'wednesday' => 'Wed',
        'thursday' => 'Thu',
        'friday' => 'Fri',
    ];

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $raw = strtolower(trim((string)($row['day_of_week'] ?? '')));
        if (isset($day_map[$raw])) {
            $class_day_counts[$day_map[$raw]] = (int)$row['count'];
        }
    }
} catch (PDOException $e) {
    error_log('Error fetching classes per day: ' . $e->getMessage());
}
$class_day_max = max(1, max($class_day_counts));

// Students by course (top 5)
$students_by_course = [];
try {
    $stmt = $conn->query("\n        SELECT\n            c.course_code,\n            COUNT(DISTINCT e.student_id) AS students\n        FROM enrollments e\n        JOIN schedules sch ON e.schedule_id = sch.id\n        JOIN courses c ON sch.course_id = c.id\n        WHERE e.status IN ('enrolled', 'approved')\n        GROUP BY c.id\n        ORDER BY students DESC\n        LIMIT 5\n    ");
    $students_by_course = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('Error fetching students by course: ' . $e->getMessage());
    $students_by_course = [];
}

// Recent enrollments
$recent_activities = [];
try {
    $stmt = $conn->query("\n        SELECT\n            e.id,\n            " . $enrollment_date_expr . " AS activity_date,\n            CONCAT(s.first_name, ' ', s.last_name) AS student_name,\n            " . $student_number_expr . " AS student_number,\n            c.course_code,\n            c.course_name,\n            e.status\n        FROM enrollments e\n        JOIN users s ON e.student_id = s.id\n        JOIN schedules sch ON e.schedule_id = sch.id\n        JOIN courses c ON sch.course_id = c.id\n        ORDER BY activity_date DESC\n        LIMIT 5\n    ");
    $recent_activities = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('Error fetching recent activities: ' . $e->getMessage());
    $recent_activities = [];
}

function dashboard_status_badge(string $status): array {
    $s = strtolower(trim($status));
    switch ($s) {
        case 'enrolled':
        case 'approved':
            return ['label' => 'Active', 'class' => 'bg-emerald-50 text-emerald-700 ring-emerald-200'];
        case 'pending':
            return ['label' => 'Pending', 'class' => 'bg-amber-50 text-amber-700 ring-amber-200'];
        case 'dropped':
            return ['label' => 'Dropped', 'class' => 'bg-rose-50 text-rose-700 ring-rose-200'];
        case 'rejected':
            return ['label' => 'Rejected', 'class' => 'bg-rose-50 text-rose-700 ring-rose-200'];
        default:
            return ['label' => ucfirst($s !== '' ? $s : 'Unknown'), 'class' => 'bg-slate-100 text-slate-700 ring-slate-200'];
    }
}

function dashboard_initials(string $name): string {
    $name = trim($name);
    if ($name === '') {
        return 'U';
    }
    $parts = preg_split('/\s+/', $name);
    if (!$parts) {
        return strtoupper(substr($name, 0, 1));
    }
    $first = strtoupper(substr($parts[0], 0, 1));
    $last = strtoupper(substr($parts[count($parts) - 1], 0, 1));
    return $first . $last;
}

function dashboard_month_template(int $months = 6): array {
    $template = [];
    for ($i = $months - 1; $i >= 0; $i--) {
        $key = date('Y-m', strtotime('first day of -' . $i . ' month'));
        $template[$key] = 0;
    }
    return $template;
}

function dashboard_fetch_month_counts(PDO $conn, string $sql, array $params, array $template): array {
    $months = $template;
    try {
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $ym = (string)($row['ym'] ?? '');
            if (isset($months[$ym])) {
                $months[$ym] = (int)($row['cnt'] ?? 0);
            }
        }
    } catch (PDOException $e) {
        error_log('Error fetching dashboard month counts: ' . $e->getMessage());
    }
    return array_values($months);
}

function dashboard_series_polyline_points(array $values, int $width = 100, int $height = 24, int $padding = 2): string {
    $count = count($values);
    if ($count <= 1) {
        return '0,' . ($height - $padding) . ' ' . $width . ',' . ($height - $padding);
    }

    $min = min($values);
    $max = max($values);
    if ($max === $min) {
        $max = $min + 1;
    }

    $usableW = $width - (2 * $padding);
    $usableH = $height - (2 * $padding);
    $points = [];
    for ($i = 0; $i < $count; $i++) {
        $x = $padding + (($usableW / ($count - 1)) * $i);
        $y = $padding + ($usableH * (1 - (($values[$i] - $min) / ($max - $min))));
        $points[] = round($x, 2) . ',' . round($y, 2);
    }
    return implode(' ', $points);
}

$today_label = date('D, M j, Y');
$user_initials = 'SA';
$full_name = 'Super Admin';
if (!empty($_SESSION['first_name']) || !empty($_SESSION['last_name'])) {
    $full_name = trim((string)($_SESSION['first_name'] ?? '') . ' ' . (string)($_SESSION['last_name'] ?? ''));
    $first = strtoupper(substr((string)($_SESSION['first_name'] ?? ''), 0, 1));
    $last = strtoupper(substr((string)($_SESSION['last_name'] ?? ''), 0, 1));
    $user_initials = trim($first . $last);
    if ($user_initials === '') {
        $user_initials = 'SA';
    }
}

$trend_points = [];
$trend_n = count($enrollment_trend_counts);
$trend_max = max(1, ...($enrollment_trend_counts ?: [1]));
if ($trend_n <= 1) {
    $trend_points[] = '0,18';
    $trend_points[] = '100,18';
} else {
    for ($i = 0; $i < $trend_n; $i++) {
        $x = (100 / ($trend_n - 1)) * $i;
        $y = 30 - (($enrollment_trend_counts[$i] / $trend_max) * 20);
        $trend_points[] = round($x, 2) . ',' . round($y, 2);
    }
}
$trend_svg_points = implode(' ', $trend_points);

$course_max = 1;
foreach ($students_by_course as $item) {
    $course_max = max($course_max, (int)($item['students'] ?? 0));
}

$course_palette = ['bg-indigo-500', 'bg-emerald-500', 'bg-amber-500', 'bg-rose-500', 'bg-sky-500'];

$trend_template = dashboard_month_template(6);
$trend_since = (string)array_key_first($trend_template) . '-01';

$trend_total_users = dashboard_fetch_month_counts(
    $conn,
    "SELECT DATE_FORMAT(created_at, '%Y-%m') AS ym, COUNT(*) AS cnt FROM users WHERE created_at >= ? GROUP BY ym ORDER BY ym ASC",
    [$trend_since],
    $trend_template
);

$trend_students = dashboard_fetch_month_counts(
    $conn,
    "SELECT DATE_FORMAT(created_at, '%Y-%m') AS ym, COUNT(*) AS cnt FROM users WHERE role = 'student' AND created_at >= ? GROUP BY ym ORDER BY ym ASC",
    [$trend_since],
    $trend_template
);

$trend_active_classes = dashboard_fetch_month_counts(
    $conn,
    "SELECT DATE_FORMAT(created_at, '%Y-%m') AS ym, COUNT(*) AS cnt FROM schedules WHERE status = 'active' AND created_at >= ? GROUP BY ym ORDER BY ym ASC",
    [$trend_since],
    $trend_template
);

$trend_enrollments = dashboard_fetch_month_counts(
    $conn,
    "SELECT DATE_FORMAT(" . $enrollment_date_expr . ", '%Y-%m') AS ym, COUNT(*) AS cnt FROM enrollments e WHERE " . $enrollment_date_expr . " >= ? GROUP BY ym ORDER BY ym ASC",
    [$trend_since],
    $trend_template
);

$mini_trend_points = [
    'total_users' => dashboard_series_polyline_points($trend_total_users),
    'students' => dashboard_series_polyline_points($trend_students),
    'active_classes' => dashboard_series_polyline_points($trend_active_classes),
    'enrollments' => dashboard_series_polyline_points($trend_enrollments),
];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Super Admin Dashboard - PCT Class Scheduling</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        .soft-card { box-shadow: 0 1px 1px rgba(15, 23, 42, 0.03), 0 8px 24px rgba(15, 23, 42, 0.04); }
        .thin-scrollbar::-webkit-scrollbar { width: 6px; height: 6px; }
        .thin-scrollbar::-webkit-scrollbar-thumb { background: #d4dbe7; border-radius: 999px; }
    </style>
</head>
<body class="bg-slate-50 text-slate-900">
<div class="min-h-screen">
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
                <a href="dashboard.php" class="flex items-center gap-3 px-3 py-2.5 rounded-xl bg-emerald-900/40 text-emerald-50"><i class="bi bi-speedometer2"></i><span class="text-sm font-medium">Dashboard</span></a>
                <a href="users.php" class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-emerald-50/80 hover:text-emerald-50 hover:bg-emerald-900/30"><i class="bi bi-people"></i><span class="text-sm font-medium">All Users</span></a>
                <a href="instructors.php" class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-emerald-50/80 hover:text-emerald-50 hover:bg-emerald-900/30"><i class="bi bi-person-video3"></i><span class="text-sm font-medium">Instructors</span></a>
                <a href="students.php" class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-emerald-50/80 hover:text-emerald-50 hover:bg-emerald-900/30"><i class="bi bi-mortarboard"></i><span class="text-sm font-medium">Students</span></a>
                <a href="classes.php" class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-emerald-50/80 hover:text-emerald-50 hover:bg-emerald-900/30"><i class="bi bi-book"></i><span class="text-sm font-medium">Classes</span></a>
                <a href="classrooms.php" class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-emerald-50/80 hover:text-emerald-50 hover:bg-emerald-900/30"><i class="bi bi-door-open"></i><span class="text-sm font-medium">Classrooms</span></a>
                <a href="subjects.php" class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-emerald-50/80 hover:text-emerald-50 hover:bg-emerald-900/30"><i class="bi bi-journal-bookmark"></i><span class="text-sm font-medium">Subjects</span></a>
                <a href="courses.php" class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-emerald-50/80 hover:text-emerald-50 hover:bg-emerald-900/30"><i class="bi bi-journal-text"></i><span class="text-sm font-medium">Courses</span></a>
                <a href="schedules.php" class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-emerald-50/80 hover:text-emerald-50 hover:bg-emerald-900/30"><i class="bi bi-calendar3"></i><span class="text-sm font-medium">Schedules</span></a>
                <a href="enrollments.php" class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-emerald-50/80 hover:text-emerald-50 hover:bg-emerald-900/30"><i class="bi bi-person-plus"></i><span class="text-sm font-medium">Enrollments</span></a>
                <a href="reports.php" class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-emerald-50/80 hover:text-emerald-50 hover:bg-emerald-900/30"><i class="bi bi-file-earmark-text"></i><span class="text-sm font-medium">Reports</span></a>
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
                        <span class="font-semibold text-slate-900">Dashboard</span>
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

        <main class="p-4 sm:p-6">
            <div class="mx-auto max-w-[1220px] space-y-5">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div class="inline-flex items-center gap-3 rounded-2xl border border-slate-200 bg-white px-4 py-3 soft-card">
                        <img src="../pctlogo.png" alt="PCT Logo" class="h-9 w-9 rounded-full bg-slate-100 object-contain">
                        <div class="leading-tight">
                            <div class="text-sm font-semibold text-slate-700">PCT Super Admin</div>
                            <div class="text-xs text-slate-400"><?php echo htmlspecialchars($today_label); ?></div>
                        </div>
                    </div>
                    <div class="inline-flex items-center gap-2 self-start rounded-full border border-emerald-200 bg-emerald-50 px-3 py-1.5 text-xs font-semibold text-emerald-700">
                        <i class="bi bi-stars"></i>
                        <span>AY. 2025-2026 - 2nd Semester</span>
                    </div>
                </div>

                <section class="grid grid-cols-1 gap-4 xl:grid-cols-12">
                    <div class="xl:col-span-5 rounded-2xl bg-gradient-to-br from-[#032b1c] via-[#043926] to-[#0f6444] p-5 text-emerald-50 soft-card">
                        <div class="flex items-start justify-between gap-4">
                            <div>
                                <div class="text-[10px] tracking-[0.18em] uppercase text-emerald-200/85">Total Enrollment</div>
                                <div id="stat_total_enrollments_main" class="mt-2 text-5xl font-bold leading-none"><?php echo (int)$stats['total_enrollments']; ?></div>
                                <div class="mt-2 text-sm text-emerald-100/80">Students across <span id="stat_active_classes_main"><?php echo (int)$stats['active_classes']; ?></span> active classes</div>
                            </div>
                            <span class="inline-flex items-center rounded-full bg-emerald-400/20 px-2.5 py-1 text-[11px] font-semibold text-emerald-100">+350%</span>
                        </div>

                        <div class="mt-7">
                            <div class="text-[10px] uppercase tracking-[0.14em] text-emerald-200/75">6-Month Trend</div>
                            <svg viewBox="0 0 100 30" preserveAspectRatio="none" class="mt-2 h-16 w-full">
                                <polyline fill="none" stroke="rgba(74, 222, 128, 0.95)" stroke-width="1.7" points="<?php echo htmlspecialchars($trend_svg_points); ?>"></polyline>
                            </svg>
                            <div class="mt-1 flex items-center justify-between text-[10px] text-emerald-100/70">
                                <?php foreach ($enrollment_trend_labels as $label): ?>
                                    <span><?php echo htmlspecialchars($label); ?></span>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>

                    <div class="xl:col-span-7 grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <a href="users.php" class="group block rounded-2xl border border-slate-200 bg-white p-4 soft-card transition duration-200 hover:-translate-y-0.5 hover:shadow-xl focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-300/80" aria-label="Open all users page">
                            <div class="flex items-center justify-between">
                                <span class="inline-flex h-7 w-7 items-center justify-center rounded-lg bg-indigo-50 text-indigo-500"><i class="bi bi-people"></i></span>
                                <span class="inline-flex items-center gap-1 text-[11px] font-semibold text-indigo-500"><i class="bi bi-arrow-up-right transition-transform duration-200 group-hover:-translate-y-0.5 group-hover:translate-x-0.5"></i>+3</span>
                            </div>
                            <div id="stat_total_users" class="mt-3 text-3xl font-semibold text-slate-800"><?php echo (int)$stats['total_users']; ?></div>
                            <div class="text-xs text-slate-400">Total Users</div>
                            <div class="mt-2 h-8">
                                <svg viewBox="0 0 100 24" class="h-full w-full"><polyline fill="none" stroke="#6366f1" stroke-width="2" points="<?php echo htmlspecialchars($mini_trend_points['total_users'] ?? '0,22 100,22'); ?>"></polyline></svg>
                            </div>
                        </a>

                        <a href="students.php" class="group block rounded-2xl border border-slate-200 bg-white p-4 soft-card transition duration-200 hover:-translate-y-0.5 hover:shadow-xl focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-300/80" aria-label="Open students page">
                            <div class="flex items-center justify-between">
                                <span class="inline-flex h-7 w-7 items-center justify-center rounded-lg bg-emerald-50 text-emerald-500"><i class="bi bi-mortarboard"></i></span>
                                <span class="inline-flex items-center gap-1 text-[11px] font-semibold text-emerald-500"><i class="bi bi-arrow-up-right transition-transform duration-200 group-hover:-translate-y-0.5 group-hover:translate-x-0.5"></i>+2</span>
                            </div>
                            <div id="stat_students" class="mt-3 text-3xl font-semibold text-slate-800"><?php echo (int)$stats['students']; ?></div>
                            <div class="text-xs text-slate-400">Students</div>
                            <div class="mt-2 h-8">
                                <svg viewBox="0 0 100 24" class="h-full w-full"><polyline fill="none" stroke="#10b981" stroke-width="2" points="<?php echo htmlspecialchars($mini_trend_points['students'] ?? '0,22 100,22'); ?>"></polyline></svg>
                            </div>
                        </a>

                        <a href="schedules.php" class="group block rounded-2xl border border-slate-200 bg-white p-4 soft-card transition duration-200 hover:-translate-y-0.5 hover:shadow-xl focus:outline-none focus-visible:ring-2 focus-visible:ring-amber-300/80" aria-label="Open schedules page">
                            <div class="flex items-center justify-between">
                                <span class="inline-flex h-7 w-7 items-center justify-center rounded-lg bg-amber-50 text-amber-500"><i class="bi bi-book"></i></span>
                                <span class="inline-flex items-center gap-1 text-[11px] font-semibold text-amber-500"><i class="bi bi-arrow-up-right transition-transform duration-200 group-hover:-translate-y-0.5 group-hover:translate-x-0.5"></i>+1</span>
                            </div>
                            <div id="stat_active_classes" class="mt-3 text-3xl font-semibold text-slate-800"><?php echo (int)$stats['active_classes']; ?></div>
                            <div class="text-xs text-slate-400">Active Classes</div>
                            <div class="mt-2 h-8">
                                <svg viewBox="0 0 100 24" class="h-full w-full"><polyline fill="none" stroke="#f59e0b" stroke-width="2" points="<?php echo htmlspecialchars($mini_trend_points['active_classes'] ?? '0,22 100,22'); ?>"></polyline></svg>
                            </div>
                        </a>

                        <a href="enrollments.php" class="group block rounded-2xl border border-slate-200 bg-white p-4 soft-card transition duration-200 hover:-translate-y-0.5 hover:shadow-xl focus:outline-none focus-visible:ring-2 focus-visible:ring-rose-300/80" aria-label="Open enrollments page">
                            <div class="flex items-center justify-between">
                                <span class="inline-flex h-7 w-7 items-center justify-center rounded-lg bg-rose-50 text-rose-500"><i class="bi bi-person-plus"></i></span>
                                <span class="inline-flex items-center gap-1 text-[11px] font-semibold text-rose-500"><i class="bi bi-arrow-up-right transition-transform duration-200 group-hover:-translate-y-0.5 group-hover:translate-x-0.5"></i>+4</span>
                            </div>
                            <div id="stat_total_enrollments" class="mt-3 text-3xl font-semibold text-slate-800"><?php echo (int)$stats['total_enrollments']; ?></div>
                            <div class="text-xs text-slate-400">Enrollments</div>
                            <div class="mt-2 h-8">
                                <svg viewBox="0 0 100 24" class="h-full w-full"><polyline fill="none" stroke="#f43f5e" stroke-width="2" points="<?php echo htmlspecialchars($mini_trend_points['enrollments'] ?? '0,22 100,22'); ?>"></polyline></svg>
                            </div>
                        </a>
                    </div>
                </section>

                <section class="grid grid-cols-1 gap-4 xl:grid-cols-12">
                    <div class="xl:col-span-4 rounded-2xl border border-slate-200 bg-white p-5 soft-card transition duration-200 hover:-translate-y-0.5 hover:shadow-xl">
                        <div class="text-sm font-semibold text-slate-700">Classes / Day</div>
                        <div class="text-xs text-slate-400 mt-0.5">This week</div>

                        <div class="mt-4 flex h-40 items-end justify-between gap-2">
                            <?php foreach ($class_day_counts as $day => $count): ?>
                                <?php $height = max(10, (int)round(($count / $class_day_max) * 100)); ?>
                                <div class="group flex w-full flex-col items-center justify-end gap-2">
                                    <div class="relative flex h-28 w-full items-end justify-center">
                                        <div class="pointer-events-none absolute -top-8 left-1/2 -translate-x-1/2 rounded-md bg-slate-800 px-2 py-1 text-[10px] font-semibold text-white opacity-0 transition-opacity duration-150 group-hover:opacity-100">
                                            <?php echo (int)$count; ?> class<?php echo ((int)$count === 1) ? '' : 'es'; ?>
                                        </div>
                                        <div class="w-8 rounded-t-lg bg-emerald-200" style="height: <?php echo $height; ?>%;" title="<?php echo htmlspecialchars($day); ?>: <?php echo (int)$count; ?> class<?php echo ((int)$count === 1) ? '' : 'es'; ?>"></div>
                                    </div>
                                    <div class="text-[11px] text-slate-400"><?php echo htmlspecialchars($day); ?></div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="xl:col-span-4 rounded-2xl border border-slate-200 bg-white p-5 soft-card">
                        <div class="text-sm font-semibold text-slate-700">Students by Course</div>
                        <div class="text-xs text-slate-400 mt-0.5">Current distribution</div>

                        <div class="mt-4 space-y-3">
                            <?php if (!empty($students_by_course)): ?>
                                <?php foreach ($students_by_course as $idx => $row): ?>
                                    <?php
                                        $count = (int)($row['students'] ?? 0);
                                        $w = (int)round(($count / $course_max) * 100);
                                        $color = $course_palette[$idx % count($course_palette)];
                                    ?>
                                    <div>
                                        <div class="mb-1 flex items-center justify-between text-xs">
                                            <div class="flex items-center gap-2">
                                                <span class="inline-block h-2.5 w-2.5 rounded-full <?php echo htmlspecialchars($color); ?>"></span>
                                                <span class="font-semibold text-slate-600"><?php echo htmlspecialchars((string)($row['course_code'] ?? 'N/A')); ?></span>
                                            </div>
                                            <span class="text-slate-400"><?php echo $count; ?> student<?php echo $count === 1 ? '' : 's'; ?></span>
                                        </div>
                                        <div class="h-1.5 rounded-full bg-slate-100">
                                            <div class="h-1.5 rounded-full <?php echo htmlspecialchars($color); ?>" style="width: <?php echo max(5, $w); ?>%;"></div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="text-sm text-slate-400">No course data available.</div>
                            <?php endif; ?>
                        </div>

                        <a href="students.php" class="mt-4 inline-flex w-full items-center justify-center rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-xs font-semibold text-slate-500 hover:bg-slate-100">View all students <i class="bi bi-chevron-right ml-1"></i></a>
                    </div>

                    <div class="xl:col-span-4 grid grid-cols-1 gap-4">
                        <a href="instructors.php" class="group block rounded-2xl bg-gradient-to-r from-[#7f22fe] to-[#6d28d9] p-5 text-white soft-card transition duration-200 hover:-translate-y-0.5 hover:shadow-xl focus:outline-none focus-visible:ring-2 focus-visible:ring-violet-300/80" aria-label="Open instructors page">
                            <div class="flex items-center justify-between">
                                <span class="inline-flex h-9 w-9 items-center justify-center rounded-xl bg-white/15"><i class="bi bi-person-video3"></i></span>
                                <i class="bi bi-arrow-up-right transition-transform duration-200 group-hover:-translate-y-0.5 group-hover:translate-x-0.5"></i>
                            </div>
                            <div id="stat_instructors" class="mt-3 text-4xl font-semibold"><?php echo (int)$stats['instructors']; ?></div>
                            <div class="text-sm text-white/85">Instructors</div>
                            <div class="text-xs text-white/70">Faculty members</div>
                        </a>

                        <a href="courses.php" class="group block rounded-2xl bg-gradient-to-r from-[#0ea5e9] to-[#0284c7] p-5 text-white soft-card transition duration-200 hover:-translate-y-0.5 hover:shadow-xl focus:outline-none focus-visible:ring-2 focus-visible:ring-sky-300/80" aria-label="Open courses page">
                            <div class="flex items-center justify-between">
                                <span class="inline-flex h-9 w-9 items-center justify-center rounded-xl bg-white/15"><i class="bi bi-bar-chart"></i></span>
                                <i class="bi bi-arrow-up-right transition-transform duration-200 group-hover:-translate-y-0.5 group-hover:translate-x-0.5"></i>
                            </div>
                            <div id="stat_courses" class="mt-3 text-4xl font-semibold"><?php echo (int)$stats['courses']; ?></div>
                            <div class="text-sm text-white/85">Courses Offered</div>
                            <div class="text-xs text-white/70">Degree programs</div>
                        </a>
                    </div>
                </section>

                <section class="rounded-2xl border border-slate-200 bg-white p-5 soft-card">
                    <div class="flex items-center justify-between">
                        <div>
                            <div class="text-base font-semibold text-slate-700">Recent Enrollments</div>
                            <div class="text-xs text-slate-400">Latest student enrollment activity</div>
                        </div>
                        <a href="enrollments.php" class="inline-flex items-center gap-1 rounded-full border border-emerald-200 bg-emerald-50 px-3 py-1.5 text-xs font-semibold text-emerald-700 hover:bg-emerald-100">View all <i class="bi bi-chevron-right"></i></a>
                    </div>

                    <div class="mt-4 overflow-x-auto thin-scrollbar">
                        <table class="min-w-full text-sm">
                            <thead class="text-[11px] uppercase tracking-[0.08em] text-slate-400">
                                <tr>
                                    <th class="px-3 py-2 text-left font-semibold">Student</th>
                                    <th class="px-3 py-2 text-left font-semibold">Class</th>
                                    <th class="px-3 py-2 text-left font-semibold">Course</th>
                                    <th class="px-3 py-2 text-left font-semibold">Date</th>
                                    <th class="px-3 py-2 text-left font-semibold">Status</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                <?php if (!empty($recent_activities)): ?>
                                    <?php foreach ($recent_activities as $activity): ?>
                                        <?php
                                            $badge = dashboard_status_badge((string)($activity['status'] ?? ''));
                                            $when = (string)($activity['activity_date'] ?? '');
                                            $date_label = $when !== '' ? date('M d', strtotime($when)) : 'N/A';
                                            $initials = dashboard_initials((string)($activity['student_name'] ?? ''));
                                        ?>
                                        <tr>
                                            <td class="px-3 py-3">
                                                <div class="flex items-center gap-3">
                                                    <span class="inline-flex h-7 w-7 items-center justify-center rounded-full bg-slate-800 text-[10px] font-semibold text-white"><?php echo htmlspecialchars($initials); ?></span>
                                                    <div>
                                                        <div class="font-semibold text-slate-700"><?php echo htmlspecialchars((string)($activity['student_name'] ?? 'Unknown')); ?></div>
                                                        <div class="text-[11px] text-slate-400"><?php echo htmlspecialchars((string)($activity['student_number'] ?? '')); ?></div>
                                                    </div>
                                                </div>
                                            </td>
                                            <td class="px-3 py-3 text-slate-500"><?php echo htmlspecialchars((string)($activity['course_name'] ?? 'N/A')); ?></td>
                                            <td class="px-3 py-3">
                                                <span class="inline-flex rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-semibold text-slate-500"><?php echo htmlspecialchars((string)($activity['course_code'] ?? 'N/A')); ?></span>
                                            </td>
                                            <td class="px-3 py-3 text-slate-400"><?php echo htmlspecialchars($date_label); ?></td>
                                            <td class="px-3 py-3">
                                                <span class="inline-flex items-center gap-1 rounded-full px-2.5 py-1 text-[11px] font-semibold ring-1 ring-inset <?php echo htmlspecialchars((string)$badge['class']); ?>">
                                                    <span class="h-1.5 w-1.5 rounded-full bg-current opacity-70"></span>
                                                    <?php echo htmlspecialchars((string)$badge['label']); ?>
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="5" class="px-3 py-8 text-center text-sm text-slate-400">No recent enrollments found.</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>
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
        if (sidebar?.classList.contains('-translate-x-full')) {
            openSidebar();
        } else {
            closeSidebar();
        }
    });
    overlay?.addEventListener('click', closeSidebar);

    async function refreshSummaryCards() {
        try {
            const res = await fetch('dashboard_stats.php', { cache: 'no-store' });
            if (!res.ok) return;
            const data = await res.json();

            const setText = function (id, value) {
                const el = document.getElementById(id);
                if (!el) return;
                const next = String(value ?? '');
                if (el.textContent !== next) {
                    el.textContent = next;
                }
            };

            setText('stat_total_users', data.total_users);
            setText('stat_students', data.students);
            setText('stat_active_classes', data.active_classes);
            setText('stat_active_classes_main', data.active_classes);
            setText('stat_total_enrollments', data.total_enrollments);
            setText('stat_total_enrollments_main', data.total_enrollments);
            setText('stat_instructors', data.instructors);
            setText('stat_courses', data.courses);
        } catch (_) {
            // Ignore temporary network failures.
        }
    }

    refreshSummaryCards();
    setInterval(refreshSummaryCards, 8000);
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

    btn.addEventListener('click', function (e) {
        e.preventDefault();
        e.stopPropagation();
        if (isOpen()) closeMenu();
        else openMenu();
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
        const t = e.target;
        if (!(t instanceof Element)) return;
        if (menu.contains(t) || btn.contains(t)) return;
        closeMenu();
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            closeMenu();
        }
    });
})();
</script>
</body>
</html>