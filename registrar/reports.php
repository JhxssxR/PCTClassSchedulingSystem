<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is a registrar/admin
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'registrar'], true)) {
    header('Location: ../auth/login.php');
    exit();
}

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
    error_log('Error in registrar reports: ' . $e->getMessage());
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

$page_title = 'Reports';
$breadcrumbs = 'Registrar / Reports';
$active_page = 'reports';
require_once __DIR__ . '/includes/layout_top.php';
?>

<!-- Tailwind color palette for charts (read via JS) -->
<div id="chartPalette" class="fixed left-0 top-0 opacity-0 pointer-events-none -z-10">
    <div id="c-emerald" class="h-1 w-1 bg-emerald-500"></div>
    <div id="c-emerald-dark" class="h-1 w-1 bg-emerald-600"></div>
    <div id="c-amber" class="h-1 w-1 bg-amber-500"></div>
    <div id="c-slate" class="h-1 w-1 bg-slate-500"></div>
    <div id="c-rose" class="h-1 w-1 bg-rose-500"></div>
</div>

<div>
    <h1 class="text-2xl font-semibold text-slate-900">Reports</h1>
    <p class="text-sm text-slate-500">Enrollment insights and utilization overview</p>
</div>

<?php if (!empty($db_error)): ?>
    <div class="mt-4 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-amber-800">
        Some report data could not be loaded. Please verify your database schema.
    </div>
<?php endif; ?>

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

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
(function () {
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
    if (ctx1 && window.Chart) {
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
    if (ctx2 && window.Chart) {
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
</script>

<?php require_once __DIR__ . '/includes/layout_bottom.php'; ?>
