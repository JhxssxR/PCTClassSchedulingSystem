<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is a registrar
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'registrar'], true)) {
    header('Location: ../auth/login.php');
    exit();
}

// Initialize default values
$stats = [
    'total_students' => 0,
    'total_instructors' => 0,
    'total_classes' => 0,
    'active_classes' => 0,
    'total_enrollments' => 0
];
$recent_enrollments = [];
$classes_by_day = [];
$today_schedule = [];
$enrollment_trend = [];
$enrollment_trend_labels = [];
$students_trend = [];
$instructors_trend = [];
$active_classes_trend = [];

function registrar_has_column(PDO $conn, string $table, string $column): bool {
    try {
        $stmt = $conn->query("SHOW COLUMNS FROM `$table` LIKE " . $conn->quote($column));
        return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return false;
    }
}

function registrar_month_template(int $months = 6): array {
    $template = [];
    for ($i = $months - 1; $i >= 0; $i--) {
        $key = date('Y-m', strtotime('first day of -' . $i . ' month'));
        $template[$key] = 0;
    }
    return $template;
}

function registrar_fetch_month_counts(PDO $conn, string $sql, array $params, array $template): array {
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
        error_log('Registrar month trend query failed: ' . $e->getMessage());
    }
    return array_values($months);
}

$enrollments_date_column = 'enrollment_date';
if (registrar_has_column($conn, 'enrollments', 'enrolled_at')) {
    $enrollments_date_column = 'enrolled_at';
} elseif (registrar_has_column($conn, 'enrollments', 'enrollment_date')) {
    $enrollments_date_column = 'enrollment_date';
} elseif (registrar_has_column($conn, 'enrollments', 'created_at')) {
    $enrollments_date_column = 'created_at';
}

function format_time_ampm($time) {
    if (!$time) {
        return '';
    }
    return date('g:i A', strtotime($time));
}

function svg_sparkline_path(array $values, $width = 84, $height = 28, $padding = 3) {
    $count = count($values);
    if ($count < 2) {
        return '';
    }

    $min = min($values);
    $max = max($values);
    if ($max === $min) {
        $max = $min + 1;
    }

    $dx = ($width - 2 * $padding) / ($count - 1);
    $points = [];
    for ($i = 0; $i < $count; $i++) {
        $x = $padding + $dx * $i;
        $v = $values[$i];
        $y = $padding + ($height - 2 * $padding) * (1 - (($v - $min) / ($max - $min)));
        $points[] = [$x, $y];
    }

    $d = 'M ' . number_format($points[0][0], 2, '.', '') . ' ' . number_format($points[0][1], 2, '.', '');
    for ($i = 1; $i < count($points); $i++) {
        $d .= ' L ' . number_format($points[$i][0], 2, '.', '') . ' ' . number_format($points[$i][1], 2, '.', '');
    }
    return $d;
}

try {
    // Get statistics with error handling for each query
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM users WHERE role = 'student'");
    $stmt->execute();
    $stats['total_students'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;

    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM users WHERE role = 'instructor'");
    $stmt->execute();
    $stats['total_instructors'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;

    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM schedules");
    $stmt->execute();
    $stats['total_classes'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;

    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM schedules WHERE status = 'active'");
    $stmt->execute();
    $stats['active_classes'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'] ?? $stats['total_classes'];

    // Total enrollments
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM enrollments");
    $stmt->execute();
    $stats['total_enrollments'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;

    // Get recent enrollments with proper JOIN and error handling
    $stmt = $conn->prepare("
        SELECT e.*, 
               u.first_name as student_first_name, 
               u.last_name as student_last_name,
               c.course_code, c.course_name,
               DATE_FORMAT(e.$enrollments_date_column, '%Y-%m-%d') as formatted_date
        FROM enrollments e
        JOIN users u ON e.student_id = u.id
        JOIN schedules s ON e.schedule_id = s.id
        JOIN courses c ON s.course_id = c.id
        ORDER BY e.$enrollments_date_column DESC
        LIMIT 5
    ");
    $stmt->execute();
    $recent_enrollments = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get classes by day with proper ordering
    $stmt = $conn->prepare("
        SELECT day_of_week, COUNT(*) as count
        FROM schedules
        GROUP BY day_of_week
        ORDER BY FIELD(day_of_week, 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday')
    ");
    $stmt->execute();
    $classes_by_day = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // Today's schedule
    $today = date('l');
    $stmt = $conn->prepare("
         SELECT s.start_time,
             TIME_FORMAT(ADDTIME(s.start_time, SEC_TO_TIME(120 * 60)), '%H:%i:%s') as end_time,
               c.course_code, c.course_name,
               u.first_name as instructor_first_name, u.last_name as instructor_last_name,
               cr.room_number
        FROM schedules s
        JOIN courses c ON s.course_id = c.id
        LEFT JOIN users u ON s.instructor_id = u.id
        LEFT JOIN classrooms cr ON s.classroom_id = cr.id
        WHERE s.status = 'active'
        AND s.day_of_week = :today
        ORDER BY s.start_time ASC
        LIMIT 4
    ");
    $stmt->execute(['today' => $today]);
    $today_schedule = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // 6-month trends for dashboard cards
    $months = registrar_month_template(6);
    $since = (string)array_key_first($months) . '-01';

    $enrollment_trend = registrar_fetch_month_counts(
        $conn,
        "SELECT DATE_FORMAT($enrollments_date_column, '%Y-%m') as ym, COUNT(*) as cnt FROM enrollments WHERE $enrollments_date_column >= :since GROUP BY ym ORDER BY ym ASC",
        ['since' => $since],
        $months
    );

    $students_trend = registrar_fetch_month_counts(
        $conn,
        "SELECT DATE_FORMAT(created_at, '%Y-%m') as ym, COUNT(*) as cnt FROM users WHERE role = 'student' AND created_at >= :since GROUP BY ym ORDER BY ym ASC",
        ['since' => $since],
        $months
    );

    $instructors_trend = registrar_fetch_month_counts(
        $conn,
        "SELECT DATE_FORMAT(created_at, '%Y-%m') as ym, COUNT(*) as cnt FROM users WHERE role = 'instructor' AND created_at >= :since GROUP BY ym ORDER BY ym ASC",
        ['since' => $since],
        $months
    );

    $active_classes_trend = registrar_fetch_month_counts(
        $conn,
        "SELECT DATE_FORMAT(created_at, '%Y-%m') as ym, COUNT(*) as cnt FROM schedules WHERE status = 'active' AND created_at >= :since GROUP BY ym ORDER BY ym ASC",
        ['since' => $since],
        $months
    );

    $enrollment_trend_labels = array_map(function ($ym) {
        return date('M', strtotime($ym . '-01'));
    }, array_keys($months));
} catch(PDOException $e) {
    error_log("Dashboard Error: " . $e->getMessage());
    // Don't set error message, just use default values
}
?>

<?php
$page_title = 'Dashboard';
$breadcrumbs = 'Registrar / Dashboard';
$active_page = 'dashboard';
require_once __DIR__ . '/includes/layout_top.php';

$max_day_count = 0;
foreach ($classes_by_day as $row) {
    $max_day_count = max($max_day_count, (int) ($row['count'] ?? 0));
}
if ($max_day_count <= 0) {
    $max_day_count = 1;
}

$day_map = [
    'Monday' => 0,
    'Tuesday' => 0,
    'Wednesday' => 0,
    'Thursday' => 0,
    'Friday' => 0,
    'Saturday' => 0,
];
foreach ($classes_by_day as $row) {
    $day = $row['day_of_week'] ?? '';
    if (isset($day_map[$day])) {
        $day_map[$day] = (int) $row['count'];
    }
}

$month_day_map = [];
foreach ($day_map as $dayName => $weeklyCount) {
    $month_day_map[$dayName] = (int) $weeklyCount;
}

$trend_path = svg_sparkline_path($enrollment_trend, 420, 120, 8);
$trend_small_paths = [
    'total_students' => svg_sparkline_path($students_trend, 84, 28, 3),
    'instructors' => svg_sparkline_path($instructors_trend, 84, 28, 3),
    'active_classes' => svg_sparkline_path($active_classes_trend, 84, 28, 3),
    'enrollments' => svg_sparkline_path($enrollment_trend, 84, 28, 3),
];
?>

<?php if (isset($error_message)): ?>
    <div class="mb-6 rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
        <?php echo htmlspecialchars($error_message); ?>
    </div>
<?php endif; ?>

<div class="grid grid-cols-1 xl:grid-cols-3 gap-6">
    <!-- Total enrollments big card -->
    <section class="xl:col-span-2 rounded-3xl bg-emerald-950 text-white overflow-hidden border border-emerald-900">
        <div class="p-6">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <div class="text-xs tracking-wide text-emerald-200 uppercase">Total Enrollments</div>
                    <div class="mt-2 text-5xl font-semibold leading-none"><?php echo number_format($stats['total_enrollments']); ?></div>
                    <div class="mt-2 text-sm text-emerald-200">Across <?php echo number_format($stats['active_classes']); ?> active classes</div>
                </div>
                <div class="hidden sm:flex items-center gap-2 rounded-full bg-emerald-900/40 px-3 py-1 text-xs text-emerald-100">
                    <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 12H4"/><path d="M14 6l6 6-6 6"/></svg>
                    +<?php echo $enrollment_trend ? number_format(max(0, end($enrollment_trend) - $enrollment_trend[0])) : '0'; ?>
                </div>
            </div>

            <div class="mt-6">
                <div class="text-xs text-emerald-200">6-Month Trend</div>
                <div class="mt-3 rounded-2xl bg-emerald-900/25 p-4">
                    <svg width="100%" height="140" viewBox="0 0 420 120" preserveAspectRatio="none" class="block">
                        <path d="<?php echo htmlspecialchars($trend_path); ?>" fill="none" stroke="rgba(167, 243, 208, 0.95)" stroke-width="3" />
                    </svg>
                    <div class="mt-2 flex justify-between text-[11px] text-emerald-200">
                        <?php foreach ($enrollment_trend_labels as $lbl): ?>
                            <span><?php echo htmlspecialchars($lbl); ?></span>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Right stats cards (2x2) -->
    <div class="grid grid-cols-2 gap-4">
        <?php
        $mini_cards = [
            [
                'label' => 'Total Students',
                'value' => (int) $stats['total_students'],
                'accent' => 'text-indigo-600',
                'bg' => 'bg-indigo-50',
                'trend_key' => 'total_students',
            ],
            [
                'label' => 'Instructors',
                'value' => (int) $stats['total_instructors'],
                'accent' => 'text-amber-600',
                'bg' => 'bg-amber-50',
                'trend_key' => 'instructors',
            ],
            [
                'label' => 'Active Classes',
                'value' => (int) $stats['active_classes'],
                'accent' => 'text-emerald-600',
                'bg' => 'bg-emerald-50',
                'trend_key' => 'active_classes',
            ],
            [
                'label' => 'Enrollments',
                'value' => (int) $stats['total_enrollments'],
                'accent' => 'text-rose-600',
                'bg' => 'bg-rose-50',
                'trend_key' => 'enrollments',
            ],
        ];
        foreach ($mini_cards as $c):
        ?>
            <div class="group rounded-3xl border border-slate-200 bg-white p-4 transition duration-200 hover:-translate-y-1 hover:shadow-xl hover:border-slate-300 cursor-pointer">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <div class="text-xs text-slate-500"><?php echo htmlspecialchars($c['label']); ?></div>
                        <div class="mt-1 text-2xl font-semibold"><?php echo number_format($c['value']); ?></div>
                    </div>
                    <div class="h-9 w-9 rounded-2xl <?php echo $c['bg']; ?> flex items-center justify-center transition-transform duration-200 group-hover:scale-110">
                        <span class="<?php echo $c['accent']; ?>">
                            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12h4l3 8 4-16 3 8h4"/></svg>
                        </span>
                    </div>
                </div>
                <div class="mt-3 flex justify-end">
                    <svg width="84" height="28" viewBox="0 0 84 28" preserveAspectRatio="none">
                        <path d="<?php echo htmlspecialchars((string)($trend_small_paths[$c['trend_key']] ?? '')); ?>" fill="none" stroke="rgba(99, 102, 241, 0.8)" stroke-width="2" class="<?php echo $c['accent']; ?>" />
                    </svg>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- Classes by day -->
    <section class="xl:col-span-2 rounded-3xl border border-slate-200 bg-white p-6 transition duration-200 hover:-translate-y-0.5 hover:shadow-xl">
        <div class="flex items-center justify-between">
            <div>
                <div class="text-sm font-semibold">Classes by Day</div>
                <div id="classesByDayRange" class="text-xs text-slate-500">This week</div>
            </div>
            <div class="flex items-center gap-2">
                <button id="classesByDayWeekBtn" type="button" class="rounded-full bg-emerald-600 px-3 py-1 text-xs font-medium text-white" aria-pressed="true">Week</button>
                <button id="classesByDayMonthBtn" type="button" class="rounded-full border border-slate-200 px-3 py-1 text-xs font-medium text-slate-600 hover:bg-slate-50" aria-pressed="false">Month</button>
            </div>
        </div>

        <div id="classesByDayChart" class="mt-6 grid grid-cols-6 gap-5 items-end h-44">
            <?php
            $short = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
            $days = array_keys($day_map);
            for ($i = 0; $i < count($days); $i++):
                $count = (int) $day_map[$days[$i]];
                $h = max(6, (int) round(($count / $max_day_count) * 120));
            ?>
                <div class="group flex flex-col items-center gap-2">
                    <div class="w-10 rounded-xl bg-emerald-100 relative overflow-hidden class-day-track" data-day="<?php echo htmlspecialchars($days[$i]); ?>" style="height: 128px;" title="<?php echo htmlspecialchars($days[$i]); ?>: <?php echo (int)$count; ?> class<?php echo ((int)$count === 1) ? '' : 'es'; ?>">
                        <div class="pointer-events-none absolute left-1/2 top-2 -translate-x-1/2 rounded-md bg-slate-800 px-2 py-1 text-[10px] font-semibold text-white opacity-0 transition-opacity duration-150 group-hover:opacity-100 class-day-tooltip">
                            <span class="class-day-count"><?php echo (int)$count; ?></span> class<span class="class-day-plural"><?php echo ((int)$count === 1) ? '' : 'es'; ?></span>
                        </div>
                        <div class="absolute bottom-0 left-0 right-0 rounded-xl bg-emerald-500 class-day-fill" style="height: <?php echo (int) $h; ?>px;"></div>
                    </div>
                    <div class="text-[11px] text-slate-500"><?php echo htmlspecialchars($short[$i]); ?></div>
                </div>
            <?php endfor; ?>
        </div>
    </section>

    <!-- Today's schedule -->
    <section class="rounded-3xl border border-slate-200 bg-white p-6">
        <div class="flex items-center justify-between">
            <div>
                <div class="text-sm font-semibold">Today's Schedule</div>
                <div class="text-xs text-slate-500"><?php echo htmlspecialchars(date('l, F j')); ?></div>
            </div>
            <a href="manage_schedule.php" class="text-xs font-medium text-emerald-700 hover:text-emerald-800">View all →</a>
        </div>

        <div class="mt-5 space-y-4">
            <?php if (!empty($today_schedule)): ?>
                <?php foreach ($today_schedule as $item): ?>
                    <div class="flex items-start gap-4">
                        <div class="w-16 text-xs text-slate-500 pt-0.5"><?php echo htmlspecialchars(format_time_ampm($item['start_time'] ?? '')); ?></div>
                        <div class="flex-1">
                            <div class="flex items-center gap-2">
                                <span class="h-2 w-2 rounded-full bg-emerald-500"></span>
                                <div class="text-sm font-medium text-slate-800"><?php echo htmlspecialchars(($item['course_name'] ?? '') !== '' ? $item['course_name'] : ($item['course_code'] ?? 'Class')); ?></div>
                            </div>
                            <div class="text-xs text-slate-500 ml-4">
                                <?php
                                $instructor = trim(($item['instructor_first_name'] ?? '') . ' ' . ($item['instructor_last_name'] ?? ''));
                                echo htmlspecialchars($instructor !== '' ? $instructor : 'TBA');
                                ?>
                            </div>
                        </div>
                        <div class="text-xs text-slate-500 whitespace-nowrap">
                            <span class="inline-flex items-center rounded-full bg-slate-100 px-2 py-1">
                                <?php echo htmlspecialchars($item['room_number'] ?? ''); ?>
                            </span>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="rounded-2xl bg-slate-50 px-4 py-3 text-sm text-slate-600">No classes scheduled for today.</div>
            <?php endif; ?>
        </div>
    </section>

    <!-- Quick actions -->
    <section class="rounded-3xl border border-slate-200 bg-white p-6">
        <div class="text-sm font-semibold">Quick Actions</div>
        <div class="text-xs text-slate-500">Common tasks</div>

        <div class="mt-5 space-y-3">
            <a href="manage_schedule.php" class="w-full inline-flex items-center justify-center gap-2 rounded-2xl bg-emerald-600 px-4 py-3 text-sm font-semibold text-white hover:bg-emerald-700">
                <span class="inline-flex h-6 w-6 items-center justify-center rounded-lg bg-white/20">
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14"/><path d="M5 12h14"/></svg>
                </span>
                Create New Class
            </a>
            <a href="students.php" class="w-full inline-flex items-center justify-center gap-2 rounded-2xl bg-blue-600 px-4 py-3 text-sm font-semibold text-white hover:bg-blue-700">
                <span class="inline-flex h-6 w-6 items-center justify-center rounded-lg bg-white/20">
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="8.5" cy="7" r="4"/><path d="M20 8v6"/><path d="M23 11h-6"/></svg>
                </span>
                Add New Student
            </a>
            <a href="manage_enrollments.php" class="w-full inline-flex items-center justify-center gap-2 rounded-2xl bg-purple-600 px-4 py-3 text-sm font-semibold text-white hover:bg-purple-700">
                <span class="inline-flex h-6 w-6 items-center justify-center rounded-lg bg-white/20">
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3h18v6H3z"/><path d="M7 21h10"/><path d="M12 9v12"/></svg>
                </span>
                Manage Enrollments
            </a>
            <a href="print_schedule.php" target="_blank" class="w-full inline-flex items-center justify-center gap-2 rounded-2xl bg-slate-700 px-4 py-3 text-sm font-semibold text-white hover:bg-slate-800">
                <span class="inline-flex h-6 w-6 items-center justify-center rounded-lg bg-white/20">
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9V2h12v7"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><path d="M6 14h12v8H6z"/></svg>
                </span>
                Print Schedule
            </a>
        </div>
    </section>

    <!-- Recent enrollments -->
    <section class="xl:col-span-2 rounded-3xl border border-slate-200 bg-white p-6">
        <div class="flex items-center justify-between">
            <div>
                <div class="text-sm font-semibold">Recent Enrollments</div>
                <div class="text-xs text-slate-500">Latest enrollment activity</div>
            </div>
            <a href="manage_enrollments.php" class="text-xs font-medium text-emerald-700 hover:text-emerald-800">View all →</a>
        </div>

        <div class="mt-5 divide-y divide-slate-100">
            <?php if (!empty($recent_enrollments)): ?>
                <?php foreach ($recent_enrollments as $enrollment):
                    $student_name = trim(($enrollment['student_first_name'] ?? '') . ' ' . ($enrollment['student_last_name'] ?? ''));
                    $course_label = trim(($enrollment['course_name'] ?? '') !== '' ? $enrollment['course_name'] : ($enrollment['course_code'] ?? ''));
                    $date_str = isset($enrollment['formatted_date']) ? date('M d', strtotime($enrollment['formatted_date'])) : '';
                    $status = strtolower((string) ($enrollment['status'] ?? ''));
                    $status_class = 'bg-slate-100 text-slate-700';
                    if ($status === 'approved') {
                        $status_class = 'bg-emerald-50 text-emerald-700';
                    } elseif ($status === 'pending') {
                        $status_class = 'bg-amber-50 text-amber-700';
                    } elseif ($status === 'rejected' || $status === 'dropped') {
                        $status_class = 'bg-rose-50 text-rose-700';
                    }
                    $initials = '';
                    foreach (preg_split('/\s+/', $student_name) as $p) {
                        $initials .= strtoupper(substr($p, 0, 1));
                        if (strlen($initials) >= 2) break;
                    }
                    if ($initials === '') $initials = 'ST';
                ?>
                    <div class="py-4 flex items-center gap-4">
                        <div class="h-10 w-10 rounded-full bg-slate-900 text-white flex items-center justify-center text-xs font-semibold">
                            <?php echo htmlspecialchars($initials); ?>
                        </div>
                        <div class="flex-1 min-w-0">
                            <div class="text-sm font-medium text-slate-800 truncate"><?php echo htmlspecialchars($student_name !== '' ? $student_name : 'Student'); ?></div>
                            <div class="text-xs text-slate-500 truncate"><?php echo htmlspecialchars($course_label); ?></div>
                        </div>
                        <div class="text-xs text-slate-500 w-16 text-right"><?php echo htmlspecialchars($date_str); ?></div>
                        <div class="w-24 text-right">
                            <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-medium <?php echo $status_class; ?>">
                                <?php echo htmlspecialchars($status !== '' ? ucfirst($status) : ''); ?>
                            </span>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="py-6 text-sm text-slate-600">No recent enrollments.</div>
            <?php endif; ?>
        </div>
    </section>
</div>

<script>
(function () {
    const weekBtn = document.getElementById('classesByDayWeekBtn');
    const monthBtn = document.getElementById('classesByDayMonthBtn');
    const rangeLabel = document.getElementById('classesByDayRange');
    const chart = document.getElementById('classesByDayChart');
    if (!weekBtn || !monthBtn || !rangeLabel || !chart) {
        return;
    }

    const dayOrder = <?php echo json_encode(array_keys($day_map), JSON_UNESCAPED_SLASHES); ?>;
    const weekData = <?php echo json_encode(array_values($day_map), JSON_UNESCAPED_SLASHES); ?>;
    const monthData = <?php echo json_encode(array_values($month_day_map), JSON_UNESCAPED_SLASHES); ?>;
    const tracks = Array.from(chart.querySelectorAll('.class-day-track'));

    function setButtonState(mode) {
        const weekActive = mode === 'week';
        const active = ['bg-emerald-600', 'text-white'];
        const inactive = ['border', 'border-slate-200', 'text-slate-600', 'hover:bg-slate-50'];

        weekBtn.classList.remove(...active, ...inactive);
        monthBtn.classList.remove(...active, ...inactive);

        if (weekActive) {
            weekBtn.classList.add(...active);
            monthBtn.classList.add(...inactive);
        } else {
            monthBtn.classList.add(...active);
            weekBtn.classList.add(...inactive);
        }

        weekBtn.setAttribute('aria-pressed', weekActive ? 'true' : 'false');
        monthBtn.setAttribute('aria-pressed', weekActive ? 'false' : 'true');
    }

    function render(mode) {
        const values = mode === 'month' ? monthData : weekData;
        const maxValue = Math.max(1, ...values);
        rangeLabel.textContent = mode === 'month' ? 'This month' : 'This week';

        tracks.forEach(function (track, idx) {
            const value = Number(values[idx] || 0);
            const height = Math.max(6, Math.round((value / maxValue) * 120));
            const fill = track.querySelector('.class-day-fill');
            const countText = track.querySelector('.class-day-count');
            const plural = track.querySelector('.class-day-plural');
            const dayName = String(dayOrder[idx] || 'Day');

            if (fill) {
                fill.style.height = height + 'px';
            }
            if (countText) {
                countText.textContent = String(value);
            }
            if (plural) {
                plural.textContent = value === 1 ? '' : 'es';
            }

            track.title = dayName + ': ' + value + ' class' + (value === 1 ? '' : 'es');
        });

        setButtonState(mode);
    }

    weekBtn.addEventListener('click', function () {
        render('week');
    });

    monthBtn.addEventListener('click', function () {
        render('month');
    });
})();
</script>

<?php require_once __DIR__ . '/includes/layout_bottom.php'; ?>