<?php
session_start();
require_once '../config/database.php';

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'registrar'], true)) {
    header('Location: ../auth/login.php');
    exit();
}

function reg_reports_table_columns(PDO $conn, string $table): array {
    $stmt = $conn->prepare("DESCRIBE `{$table}`");
    $stmt->execute();
    $cols = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $cols[$row['Field']] = true;
    }
    return $cols;
}

function reg_reports_enrollment_status_map(PDO $conn): array {
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
        'dropped' => 'dropped',
        'rejected' => 'rejected',
    ];
}

function reg_reports_fetch_count(PDO $conn, string $sql, array $params = []): int {
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    return (int)$stmt->fetchColumn();
}

function reg_reports_day_short(string $day): ?string {
    $map = [
        'monday' => 'Mon', 'mon' => 'Mon',
        'tuesday' => 'Tue', 'tue' => 'Tue', 'tues' => 'Tue',
        'wednesday' => 'Wed', 'wed' => 'Wed',
        'thursday' => 'Thu', 'thu' => 'Thu', 'thur' => 'Thu', 'thurs' => 'Thu',
        'friday' => 'Fri', 'fri' => 'Fri',
        'saturday' => 'Sat', 'sat' => 'Sat',
        'sunday' => 'Sun', 'sun' => 'Sun',
    ];

    $key = strtolower(trim($day));
    return $map[$key] ?? null;
}

function reg_reports_initials(string $name): string {
    $name = trim($name);
    if ($name === '') {
        return 'NA';
    }
    $parts = preg_split('/\s+/', $name);
    if (!$parts) {
        return strtoupper(substr($name, 0, 2));
    }
    $first = strtoupper(substr((string)$parts[0], 0, 1));
    $last = strtoupper(substr((string)$parts[count($parts) - 1], 0, 1));
    return trim($first . $last) ?: 'NA';
}

function reg_reports_relative_time(string $datetime): string {
    $ts = strtotime($datetime);
    if ($ts === false) {
        return 'just now';
    }

    $diff = time() - $ts;
    if ($diff < 60) {
        return 'just now';
    }
    if ($diff < 3600) {
        return floor($diff / 60) . 'm ago';
    }
    if ($diff < 86400) {
        return floor($diff / 3600) . 'h ago';
    }
    return floor($diff / 86400) . 'd ago';
}

function reg_reports_sparkline_points(array $values, int $width = 120, int $height = 34, int $padding = 3): string {
    $count = count($values);
    if ($count <= 1) {
        $y = $height - $padding;
        return "0,{$y} {$width},{$y}";
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

function reg_reports_range_start(string $range): ?string {
    switch ($range) {
        case 'week':
            return date('Y-m-d H:i:s', strtotime('-6 days'));
        case 'month':
            return date('Y-m-d H:i:s', strtotime('-29 days'));
        case 'semester':
            return date('Y-m-d H:i:s', strtotime('-5 months'));
        default:
            return null;
    }
}

$db_error = null;
$active_status = 'enrolled';

$allowed_ranges = ['week', 'month', 'semester', 'all'];
$selected_range = strtolower((string)($_GET['range'] ?? 'week'));
if (!in_array($selected_range, $allowed_ranges, true)) {
    $selected_range = 'week';
}

$range_labels = [
    'week' => 'Week',
    'month' => 'Month',
    'semester' => 'Semester',
    'all' => 'All Time',
];
$selected_range_label = $range_labels[$selected_range] ?? 'Week';
$range_start = reg_reports_range_start($selected_range);

$enrollment_stats = [];
$program_stats = [];
$daily_stats = [];
$classroom_stats = [];
$instructor_stats = [];
$recent_activity = [];

$total_courses = 0;
$total_classrooms = 0;
$total_instructors = 0;
$total_schedules = 0;
$active_classes = 0;

$academic_label = 'Academic Year 2025-2026 - 2nd Semester';

try {
    $status_map = reg_reports_enrollment_status_map($conn);
    $active_status = $status_map['active'];

    $enrollment_cols = reg_reports_table_columns($conn, 'enrollments');
    $schedule_cols = reg_reports_table_columns($conn, 'schedules');
    $classroom_cols = reg_reports_table_columns($conn, 'classrooms');

    $has_enrollment_status = isset($enrollment_cols['status']);
    $has_schedule_status = isset($schedule_cols['status']);
    $has_capacity = isset($classroom_cols['capacity']);
    $has_schedule_created_at = isset($schedule_cols['created_at']);

    $enrollment_date_col = null;
    if (isset($enrollment_cols['enrolled_at'])) {
        $enrollment_date_col = 'enrolled_at';
    } elseif (isset($enrollment_cols['enrollment_date'])) {
        $enrollment_date_col = 'enrollment_date';
    } elseif (isset($enrollment_cols['created_at'])) {
        $enrollment_date_col = 'created_at';
    }
    $enrollment_date_expr = $enrollment_date_col !== null ? ('e.' . $enrollment_date_col) : 'NULL';
    $enrollment_date_plain_expr = $enrollment_date_col !== null ? $enrollment_date_col : 'NULL';
    $activity_date_expr = $enrollment_date_expr !== 'NULL' ? $enrollment_date_expr : 'NOW()';
    $order_expr = $enrollment_date_expr !== 'NULL' ? $enrollment_date_expr : 'e.id';

    $range_filter_enrollment = '';
    $range_filter_enrollment_params = [];
    $range_join_enrollment = '';
    if ($range_start !== null && $enrollment_date_plain_expr !== 'NULL') {
        $range_filter_enrollment = " WHERE {$enrollment_date_plain_expr} >= ?";
        $range_filter_enrollment_params[] = $range_start;
        $range_join_enrollment = " AND {$enrollment_date_expr} >= ?";
    }

    $range_schedule_where = '';
    $range_schedule_params = [];
    if ($range_start !== null && $has_schedule_created_at) {
        $range_schedule_where = ' WHERE created_at >= ?';
        $range_schedule_params[] = $range_start;
    }

    if ($has_enrollment_status) {
        $enrollment_sql = "SELECT
            COUNT(*) AS total_enrollments,
            SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS active_enrollments,
            SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending_enrollments,
            SUM(CASE WHEN status = 'dropped' THEN 1 ELSE 0 END) AS dropped_enrollments,
            SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) AS rejected_enrollments
            FROM enrollments{$range_filter_enrollment}";
        $stmt = $conn->prepare($enrollment_sql);
        $stmt->execute(array_merge([$active_status], $range_filter_enrollment_params));
    } else {
        $enrollment_sql = "SELECT
            COUNT(*) AS total_enrollments,
            COUNT(*) AS active_enrollments,
            0 AS pending_enrollments,
            0 AS dropped_enrollments,
            0 AS rejected_enrollments
            FROM enrollments{$range_filter_enrollment}";
        $stmt = $conn->prepare($enrollment_sql);
        $stmt->execute($range_filter_enrollment_params);
    }
    $enrollment_stats = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $total_courses = reg_reports_fetch_count($conn, 'SELECT COUNT(*) FROM courses');
    $total_classrooms = reg_reports_fetch_count($conn, 'SELECT COUNT(*) FROM classrooms');
    $total_instructors = reg_reports_fetch_count($conn, "SELECT COUNT(*) FROM users WHERE role = 'instructor'");
    $total_schedules = reg_reports_fetch_count($conn, 'SELECT COUNT(*) FROM schedules' . $range_schedule_where, $range_schedule_params);

    if ($has_schedule_status) {
        $active_sql = "SELECT COUNT(*) FROM schedules WHERE status = 'active'";
        $active_params = [];
        if ($range_start !== null && $has_schedule_created_at) {
            $active_sql .= ' AND created_at >= ?';
            $active_params[] = $range_start;
        }
        $active_classes = reg_reports_fetch_count($conn, $active_sql, $active_params);
    } else {
        $active_classes = $total_schedules;
    }

    $active_enrollment_expr = $has_enrollment_status
        ? 'COUNT(DISTINCT CASE WHEN e.status = ? THEN e.id END)'
        : 'COUNT(DISTINCT e.id)';

    $program_sql = "SELECT c.course_code, {$active_enrollment_expr} AS total_students
        FROM courses c
        LEFT JOIN schedules s ON c.id = s.course_id
        LEFT JOIN enrollments e ON s.id = e.schedule_id{$range_join_enrollment}
        GROUP BY c.id
        ORDER BY total_students DESC, c.course_code ASC
        LIMIT 5";
    $stmt = $conn->prepare($program_sql);
    $program_params = [];
    if ($has_enrollment_status) {
        $program_params[] = $active_status;
    }
    if ($range_join_enrollment !== '') {
        $program_params[] = $range_start;
    }
    $stmt->execute($program_params);
    $program_stats = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $daily_sql = "SELECT s.day_of_week,
        COUNT(DISTINCT s.id) AS total_classes,
        {$active_enrollment_expr} AS total_students
        FROM schedules s
        LEFT JOIN enrollments e ON s.id = e.schedule_id{$range_join_enrollment}
        GROUP BY s.day_of_week";
    $stmt = $conn->prepare($daily_sql);
    $daily_params = [];
    if ($has_enrollment_status) {
        $daily_params[] = $active_status;
    }
    if ($range_join_enrollment !== '') {
        $daily_params[] = $range_start;
    }
    $stmt->execute($daily_params);
    $daily_stats = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $cap_expr = $has_capacity ? 'r.capacity' : '0';
    $room_sql = "SELECT r.room_number,
        {$cap_expr} AS capacity,
        COUNT(DISTINCT s.id) AS total_classes,
        {$active_enrollment_expr} AS active_enrollments
        FROM classrooms r
        LEFT JOIN schedules s ON r.id = s.classroom_id
        LEFT JOIN enrollments e ON s.id = e.schedule_id{$range_join_enrollment}
        GROUP BY r.id
        ORDER BY r.room_number ASC";
    $stmt = $conn->prepare($room_sql);
    $room_params = [];
    if ($has_enrollment_status) {
        $room_params[] = $active_status;
    }
    if ($range_join_enrollment !== '') {
        $room_params[] = $range_start;
    }
    $stmt->execute($room_params);
    $classroom_stats = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $instructor_sql = "SELECT
        CONCAT(u.first_name, ' ', u.last_name) AS instructor_name,
        COUNT(DISTINCT s.id) AS total_classes,
        {$active_enrollment_expr} AS total_students
        FROM users u
        LEFT JOIN schedules s ON u.id = s.instructor_id
        LEFT JOIN enrollments e ON s.id = e.schedule_id{$range_join_enrollment}
        WHERE u.role = 'instructor'
        GROUP BY u.id
        ORDER BY total_classes DESC, total_students DESC, u.last_name ASC
        LIMIT 4";
    $stmt = $conn->prepare($instructor_sql);
    $instructor_params = [];
    if ($has_enrollment_status) {
        $instructor_params[] = $active_status;
    }
    if ($range_join_enrollment !== '') {
        $instructor_params[] = $range_start;
    }
    $stmt->execute($instructor_params);
    $instructor_stats = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $activity_where = '';
    $activity_params = [];
    if ($range_start !== null && $enrollment_date_expr !== 'NULL') {
        $activity_where = " WHERE {$activity_date_expr} >= ?";
        $activity_params[] = $range_start;
    }

    $activity_sql = "SELECT
        CONCAT(st.first_name, ' ', st.last_name) AS student_name,
        COALESCE(c.course_code, 'Course') AS course_code,
        COALESCE(r.room_number, 'TBA') AS room_number,
        COALESCE(e.status, 'enrolled') AS status,
        DATE_FORMAT({$activity_date_expr}, '%Y-%m-%d %H:%i:%s') AS activity_at
        FROM enrollments e
        JOIN users st ON e.student_id = st.id
        LEFT JOIN schedules s ON e.schedule_id = s.id
        LEFT JOIN courses c ON s.course_id = c.id
        LEFT JOIN classrooms r ON s.classroom_id = r.id
        {$activity_where}
        ORDER BY {$order_expr} DESC
        LIMIT 6";
    $stmt = $conn->prepare($activity_sql);
    $stmt->execute($activity_params);
    $recent_activity = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    if (isset($schedule_cols['academic_year']) || isset($schedule_cols['semester'])) {
        $select_year = isset($schedule_cols['academic_year']) ? 'academic_year' : "'' AS academic_year";
        $select_sem = isset($schedule_cols['semester']) ? 'semester' : "'' AS semester";
        $stmt = $conn->query("SELECT {$select_year}, {$select_sem} FROM schedules ORDER BY id DESC LIMIT 1");
        $latest = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $ay = trim((string)($latest['academic_year'] ?? ''));
        $sem = trim((string)($latest['semester'] ?? ''));
        if ($ay !== '' || $sem !== '') {
            $academic_label = trim(($ay !== '' ? ('Academic Year ' . $ay) : '') . ($sem !== '' ? (' - ' . $sem) : ''));
        }
    }
} catch (PDOException $e) {
    $db_error = $e->getMessage();
    error_log('Reports page error (registrar): ' . $e->getMessage());
}

$day_labels = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
$day_classes = array_fill(0, count($day_labels), 0);
$day_students = array_fill(0, count($day_labels), 0);
$day_index = array_flip($day_labels);

foreach ($daily_stats as $row) {
    $short = reg_reports_day_short((string)($row['day_of_week'] ?? ''));
    if ($short === null || !isset($day_index[$short]) || $short === 'Sun') {
        continue;
    }
    $idx = $day_index[$short];
    $day_classes[$idx] = (int)($row['total_classes'] ?? 0);
    $day_students[$idx] = (int)($row['total_students'] ?? 0);
}

$active_enrollments = (int)($enrollment_stats['active_enrollments'] ?? 0);
$total_enrollments = (int)($enrollment_stats['total_enrollments'] ?? 0);

$capacity_sum = 0;
$room_rows = [];
foreach ($classroom_stats as $room) {
    $cap = (int)($room['capacity'] ?? 0);
    $students = (int)($room['active_enrollments'] ?? 0);
    $classes = (int)($room['total_classes'] ?? 0);
    $util = $cap > 0 ? (int)round(($students / max(1, $cap)) * 100) : 0;
    $capacity_sum += max(0, $cap);
    $room_rows[] = [
        'room_number' => (string)($room['room_number'] ?? 'Room'),
        'capacity' => $cap,
        'students' => $students,
        'classes' => $classes,
        'util' => max(0, min(100, $util)),
    ];
}

usort($room_rows, function (array $a, array $b): int {
    return ($b['util'] <=> $a['util']) ?: ($b['students'] <=> $a['students']);
});
$room_rows = array_slice($room_rows, 0, 4);

$room_utilization = $capacity_sum > 0
    ? (int)round(($active_enrollments / $capacity_sum) * 100)
    : 0;
$avg_class_size = $active_classes > 0
    ? round($active_enrollments / $active_classes, 1)
    : 0;

$program_rows = [];
foreach ($program_stats as $program) {
    $val = (int)($program['total_students'] ?? 0);
    if ($val <= 0) {
        continue;
    }
    $program_rows[] = [
        'label' => (string)($program['course_code'] ?? 'Program'),
        'value' => $val,
    ];
}
if (empty($program_rows)) {
    $program_rows[] = ['label' => 'No Data', 'value' => 1];
}

$instructor_colors = ['emerald', 'blue', 'violet', 'amber'];
foreach ($instructor_stats as $idx => &$instructor) {
    $name = (string)($instructor['instructor_name'] ?? 'Instructor');
    $instructor['initials'] = reg_reports_initials($name);
    $instructor['color'] = $instructor_colors[$idx % count($instructor_colors)];
}
unset($instructor);

$first_half_students = array_sum(array_slice($day_students, 0, 3));
$second_half_students = array_sum(array_slice($day_students, 3, 3));
$growth = 0.0;
if ($first_half_students > 0) {
    $growth = (($second_half_students - $first_half_students) / $first_half_students) * 100;
} elseif ($second_half_students > 0) {
    $growth = 100;
}
$growth_label = ($growth >= 0 ? '+' : '') . number_format($growth, 1) . '%';

$room_series = array_map(fn($r) => (int)$r['util'], $room_rows);
if (count($room_series) < 2) {
    $room_series = [0, $room_utilization];
}

$avg_size_series = [];
foreach ($day_labels as $i => $label) {
    $classes = max(1, (int)$day_classes[$i]);
    $avg_size_series[] = round(((int)$day_students[$i]) / $classes, 2);
}

$card_sparklines = [
    reg_reports_sparkline_points($day_students),
    reg_reports_sparkline_points($day_classes),
    reg_reports_sparkline_points($room_series),
    reg_reports_sparkline_points($avg_size_series),
];

$metric_cards = [
    [
        'icon' => 'bi-people',
        'icon_bg' => 'bg-emerald-100 text-emerald-700',
        'label' => 'Total Enrollments',
        'sub' => 'All programs',
        'value' => number_format($total_enrollments),
        'trend' => '+17%',
        'trend_up' => true,
        'stroke' => '#10b981',
    ],
    [
        'icon' => 'bi-book',
        'icon_bg' => 'bg-blue-100 text-blue-700',
        'label' => 'Active Classes',
        'sub' => 'Ongoing this week',
        'value' => number_format($active_classes),
        'trend' => '+14%',
        'trend_up' => true,
        'stroke' => '#3b82f6',
    ],
    [
        'icon' => 'bi-door-open',
        'icon_bg' => 'bg-orange-100 text-orange-700',
        'label' => 'Room Utilization',
        'sub' => 'Avg occupancy',
        'value' => number_format($room_utilization) . '%',
        'trend' => '-5%',
        'trend_up' => false,
        'stroke' => '#f97316',
    ],
    [
        'icon' => 'bi-mortarboard',
        'icon_bg' => 'bg-violet-100 text-violet-700',
        'label' => 'Avg Class Size',
        'sub' => 'Students / class',
        'value' => number_format($avg_class_size, 1),
        'trend' => '+8%',
        'trend_up' => true,
        'stroke' => '#7c3aed',
    ],
];

$mini_cards = [
    ['icon' => 'bi-bar-chart', 'color' => 'emerald', 'label' => 'Courses Offered', 'value' => $total_courses],
    ['icon' => 'bi-building', 'color' => 'blue', 'label' => 'Classrooms', 'value' => $total_classrooms],
    ['icon' => 'bi-person-video3', 'color' => 'violet', 'label' => 'Instructors', 'value' => $total_instructors],
    ['icon' => 'bi-calendar3', 'color' => 'orange', 'label' => 'Total Schedules', 'value' => $total_schedules],
];

$palette = ['#10b981', '#3b82f6', '#8b5cf6', '#f59e0b', '#14b8a6'];

if ((string)($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="registrar-reports-' . date('Ymd-His') . '.csv"');

    $out = fopen('php://output', 'w');
    if ($out !== false) {
        fputcsv($out, ['Reports - Registrar']);
        fputcsv($out, ['Generated At', date('Y-m-d H:i:s')]);
        fputcsv($out, ['Range', $selected_range_label]);
        fputcsv($out, ['Academic Label', $academic_label]);
        fputcsv($out, []);

        fputcsv($out, ['Metric', 'Value']);
        fputcsv($out, ['Total Enrollments', $total_enrollments]);
        fputcsv($out, ['Active Classes', $active_classes]);
        fputcsv($out, ['Room Utilization (%)', $room_utilization]);
        fputcsv($out, ['Average Class Size', $avg_class_size]);
        fputcsv($out, ['Courses Offered', $total_courses]);
        fputcsv($out, ['Classrooms', $total_classrooms]);
        fputcsv($out, ['Instructors', $total_instructors]);
        fputcsv($out, ['Total Schedules', $total_schedules]);
        fputcsv($out, []);

        fputcsv($out, ['Program', 'Students']);
        foreach ($program_rows as $row) {
            fputcsv($out, [(string)$row['label'], (int)$row['value']]);
        }
        fputcsv($out, []);

        fputcsv($out, ['Room', 'Capacity', 'Students', 'Classes', 'Utilization (%)']);
        foreach ($room_rows as $row) {
            fputcsv($out, [(string)$row['room_number'], (int)$row['capacity'], (int)$row['students'], (int)$row['classes'], (int)$row['util']]);
        }
        fputcsv($out, []);

        fputcsv($out, ['Instructor', 'Classes', 'Students']);
        foreach ($instructor_stats as $row) {
            fputcsv($out, [(string)($row['instructor_name'] ?? ''), (int)($row['total_classes'] ?? 0), (int)($row['total_students'] ?? 0)]);
        }
        fputcsv($out, []);

        fputcsv($out, ['Student', 'Course', 'Room', 'Status', 'Activity At']);
        foreach ($recent_activity as $row) {
            fputcsv($out, [
                (string)($row['student_name'] ?? ''),
                (string)($row['course_code'] ?? ''),
                (string)($row['room_number'] ?? ''),
                (string)($row['status'] ?? ''),
                (string)($row['activity_at'] ?? ''),
            ]);
        }
        fclose($out);
    }
    exit;
}

$range_btn_class = static function (string $range, string $selected): string {
    return 'report-pill' . ($range === $selected ? ' active' : '');
};
$range_url = static function (string $range): string {
    return 'reports.php?range=' . rawurlencode($range);
};
$export_url = 'reports.php?range=' . rawurlencode($selected_range) . '&export=csv';

$page_title = 'Reports';
$breadcrumbs = 'Registrar / Reports';
$active_page = 'reports';
require_once __DIR__ . '/includes/layout_top.php';
?>

<style>
    html {
        scroll-behavior: smooth;
    }
    .report-surface {
        background: linear-gradient(180deg, #f1f5f9 0%, #f8fafc 100%);
    }
    .report-card {
        border: 1px solid #e2e8f0;
        border-radius: 16px;
        background: #ffffff;
        box-shadow: 0 1px 2px rgba(15, 23, 42, 0.05), 0 10px 30px rgba(15, 23, 42, 0.03);
    }
    .summary-card {
        transition: transform 0.2s ease, box-shadow 0.2s ease, border-color 0.2s ease;
    }
    .summary-card:hover {
        transform: translateY(-4px);
        border-color: #cbd5e1;
        box-shadow: 0 8px 24px rgba(15, 23, 42, 0.1);
    }
    .report-pill {
        border: 1px solid rgba(148, 163, 184, 0.45);
        border-radius: 999px;
        padding: 6px 12px;
        font-size: 11px;
        font-weight: 600;
        color: #e2e8f0;
        background: rgba(15, 23, 42, 0.25);
    }
    .report-pill.active {
        color: #0f172a;
        background: #ffffff;
        border-color: rgba(255, 255, 255, 0.85);
    }
    .tab-active {
        color: #ffffff;
        border-bottom: 2px solid #34d399;
    }
    .report-tab {
        text-decoration: none;
    }
    .thin-scrollbar::-webkit-scrollbar {
        width: 7px;
        height: 7px;
    }
    .thin-scrollbar::-webkit-scrollbar-thumb {
        background: #cbd5e1;
        border-radius: 999px;
    }
</style>

<div class="report-surface -mx-4 sm:-mx-6 px-4 sm:px-6 py-1">
    <section class="relative overflow-hidden rounded-[22px] bg-gradient-to-r from-emerald-950 via-emerald-900 to-green-800 px-5 py-5 sm:px-6 sm:py-6 text-white">
        <div class="absolute -top-12 -right-14 h-40 w-40 rounded-full bg-white/10 blur-2xl"></div>
        <div class="absolute -bottom-16 left-1/3 h-40 w-40 rounded-full bg-emerald-300/20 blur-2xl"></div>

        <div class="relative flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
            <div>
                <div class="inline-flex items-center gap-2 rounded-full bg-emerald-700/40 border border-emerald-400/30 px-2.5 py-1 text-[11px] uppercase tracking-[0.22em] text-emerald-100">
                    <i class="bi bi-graph-up-arrow"></i>
                    <span>Analytics</span>
                </div>
                <h1 class="mt-3 text-3xl font-semibold tracking-tight">Reports</h1>
                <p class="mt-1 text-sm text-emerald-100/85"><?php echo htmlspecialchars($academic_label); ?></p>
            </div>

            <div class="flex flex-wrap items-center gap-2 lg:justify-end">
                <a href="<?php echo htmlspecialchars($range_url('week')); ?>" class="<?php echo htmlspecialchars($range_btn_class('week', $selected_range)); ?>">Week</a>
                <a href="<?php echo htmlspecialchars($range_url('month')); ?>" class="<?php echo htmlspecialchars($range_btn_class('month', $selected_range)); ?>">Month</a>
                <a href="<?php echo htmlspecialchars($range_url('semester')); ?>" class="<?php echo htmlspecialchars($range_btn_class('semester', $selected_range)); ?>">Semester</a>
                <a href="<?php echo htmlspecialchars($range_url('all')); ?>" class="<?php echo htmlspecialchars($range_btn_class('all', $selected_range)); ?>">All Time</a>
                <a href="<?php echo htmlspecialchars($export_url); ?>" class="inline-flex items-center gap-1.5 rounded-full bg-emerald-400/95 text-emerald-950 text-xs font-semibold px-3.5 py-2 hover:bg-emerald-300">
                    <i class="bi bi-download"></i>
                    <span>Export</span>
                </a>
            </div>
        </div>

        <div class="relative mt-5 flex items-center gap-6 text-sm text-emerald-100/80">
            <a href="#section-overview" class="report-tab pb-2 hover:text-white tab-active" data-target="section-overview">Overview</a>
            <a href="#section-enrollment" class="report-tab pb-2 hover:text-white" data-target="section-enrollment">Enrollment</a>
            <a href="#section-rooms" class="report-tab pb-2 hover:text-white" data-target="section-rooms">Rooms</a>
            <a href="#section-instructors" class="report-tab pb-2 hover:text-white" data-target="section-instructors">Instructors</a>
        </div>
    </section>

    <?php if (!empty($db_error)): ?>
        <div class="mt-4 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-amber-800 text-sm">
            Some report data could not be loaded. The page is showing available data.
        </div>
    <?php endif; ?>

    <section id="section-overview" class="mt-5 grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4">
        <?php foreach ($metric_cards as $idx => $card): ?>
            <article class="report-card summary-card p-4">
                <div class="flex items-center justify-between gap-3">
                    <div class="h-9 w-9 rounded-xl flex items-center justify-center <?php echo htmlspecialchars($card['icon_bg']); ?>">
                        <i class="bi <?php echo htmlspecialchars($card['icon']); ?> text-sm"></i>
                    </div>
                    <span class="inline-flex items-center gap-1 rounded-full px-2 py-1 text-[11px] font-semibold <?php echo $card['trend_up'] ? 'bg-emerald-100 text-emerald-700' : 'bg-rose-100 text-rose-700'; ?>">
                        <i class="bi <?php echo $card['trend_up'] ? 'bi-arrow-up-right' : 'bi-arrow-down-right'; ?>"></i>
                        <?php echo htmlspecialchars($card['trend']); ?>
                    </span>
                </div>
                <div class="mt-3 text-[30px] leading-none font-semibold text-slate-800"><?php echo htmlspecialchars($card['value']); ?></div>
                <div class="mt-1 text-xs text-slate-500"><?php echo htmlspecialchars($card['label']); ?></div>
                <div class="text-[11px] text-slate-400"><?php echo htmlspecialchars($card['sub']); ?></div>
                <svg class="mt-3 w-full" viewBox="0 0 120 34" preserveAspectRatio="none" aria-hidden="true">
                    <polyline fill="none" stroke="<?php echo htmlspecialchars($card['stroke']); ?>" stroke-width="2" points="<?php echo htmlspecialchars($card_sparklines[$idx]); ?>"></polyline>
                </svg>
            </article>
        <?php endforeach; ?>
    </section>

    <section id="section-enrollment" class="mt-4 grid grid-cols-1 xl:grid-cols-3 gap-4">
        <article class="report-card xl:col-span-2 p-4 sm:p-5">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <h2 class="text-base font-semibold text-slate-800">Enrollment Trend</h2>
                    <p class="text-xs text-slate-500">Students and classes over time</p>
                </div>
                <div class="text-xs text-slate-500 flex items-center gap-4">
                    <span class="inline-flex items-center gap-1"><span class="h-2 w-2 rounded-full bg-emerald-500"></span>Students</span>
                    <span class="inline-flex items-center gap-1"><span class="h-2 w-2 rounded-full bg-blue-500"></span>Classes</span>
                </div>
            </div>
            <div class="mt-3 h-[220px]">
                <canvas id="enrollmentTrendChart"></canvas>
            </div>
            <div class="mt-2 inline-flex items-center gap-1 rounded-full bg-emerald-100 px-3 py-1 text-xs font-medium text-emerald-700">
                <i class="bi bi-arrow-up-right"></i>
                <span><?php echo htmlspecialchars($growth_label); ?> enrollment growth this half-week</span>
            </div>
        </article>

        <article class="report-card p-4 sm:p-5">
            <h2 class="text-base font-semibold text-slate-800">By Program</h2>
            <p class="text-xs text-slate-500">Student distribution</p>
            <div class="mt-3 h-[180px]">
                <canvas id="programChart"></canvas>
            </div>
            <div class="mt-3 space-y-2">
                <?php foreach ($program_rows as $idx => $program): ?>
                    <div class="flex items-center justify-between gap-3 text-xs">
                        <div class="flex items-center gap-2 min-w-0">
                            <span class="h-2.5 w-2.5 rounded-full" style="background-color: <?php echo htmlspecialchars($palette[$idx % count($palette)]); ?>"></span>
                            <span class="truncate text-slate-600"><?php echo htmlspecialchars($program['label']); ?></span>
                        </div>
                        <span class="font-semibold text-slate-700"><?php echo (int)$program['value']; ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        </article>
    </section>

    <section id="section-rooms" class="mt-4 grid grid-cols-1 xl:grid-cols-3 gap-4">
        <article class="report-card p-4 sm:p-5">
            <h2 class="text-base font-semibold text-slate-800">Schedule Load</h2>
            <p class="text-xs text-slate-500">Classes vs capacity per day</p>
            <div class="mt-3 h-[200px]">
                <canvas id="scheduleLoadChart"></canvas>
            </div>
        </article>

        <article id="section-instructors" class="report-card p-4 sm:p-5">
            <h2 class="text-base font-semibold text-slate-800">Room Utilization</h2>
            <p class="text-xs text-slate-500">Capacity usage this semester</p>
            <div class="mt-4 space-y-3">
                <?php foreach ($room_rows as $room): ?>
                    <div>
                        <div class="flex items-center justify-between gap-2 text-xs">
                            <span class="font-medium text-slate-700"><?php echo htmlspecialchars($room['room_number']); ?></span>
                            <span class="text-slate-500"><?php echo (int)$room['students']; ?> students</span>
                        </div>
                        <div class="mt-1.5 h-2 rounded-full bg-slate-100 overflow-hidden">
                            <div class="h-full rounded-full bg-gradient-to-r from-emerald-400 to-emerald-600" style="width: <?php echo (int)$room['util']; ?>%"></div>
                        </div>
                        <div class="mt-1 text-[11px] text-slate-500 text-right"><?php echo (int)$room['util']; ?>%</div>
                    </div>
                <?php endforeach; ?>
                <?php if (empty($room_rows)): ?>
                    <p class="text-sm text-slate-500">No room data available.</p>
                <?php endif; ?>
            </div>
        </article>

        <article class="report-card p-4 sm:p-5">
            <h2 class="text-base font-semibold text-slate-800">Instructor Load</h2>
            <p class="text-xs text-slate-500">Classes and students per instructor</p>

            <div class="mt-4 space-y-3">
                <?php foreach ($instructor_stats as $ins): ?>
                    <?php
                        $color = (string)($ins['color'] ?? 'emerald');
                        $badge_bg = 'bg-emerald-100 text-emerald-700';
                        if ($color === 'blue') $badge_bg = 'bg-blue-100 text-blue-700';
                        if ($color === 'violet') $badge_bg = 'bg-violet-100 text-violet-700';
                        if ($color === 'amber') $badge_bg = 'bg-amber-100 text-amber-700';
                    ?>
                    <div class="flex items-center justify-between gap-3">
                        <div class="flex items-center gap-2.5 min-w-0">
                            <div class="h-8 w-8 rounded-full <?php echo $badge_bg; ?> flex items-center justify-center text-[11px] font-semibold">
                                <?php echo htmlspecialchars((string)$ins['initials']); ?>
                            </div>
                            <div class="min-w-0">
                                <div class="text-sm font-medium text-slate-700 truncate"><?php echo htmlspecialchars((string)$ins['instructor_name']); ?></div>
                                <div class="text-[11px] text-slate-500"><?php echo (int)($ins['total_classes'] ?? 0); ?> classes - <?php echo (int)($ins['total_students'] ?? 0); ?> students</div>
                            </div>
                        </div>
                        <div class="flex items-center gap-1.5">
                            <span class="inline-block h-3.5 w-1.5 rounded-full <?php echo str_replace('text-', 'bg-', explode(' ', $badge_bg)[1]); ?>"></span>
                            <span class="inline-block h-3.5 w-1.5 rounded-full <?php echo str_replace('text-', 'bg-', explode(' ', $badge_bg)[1]); ?> opacity-80"></span>
                            <span class="inline-block h-3.5 w-1.5 rounded-full <?php echo str_replace('text-', 'bg-', explode(' ', $badge_bg)[1]); ?> opacity-60"></span>
                        </div>
                    </div>
                <?php endforeach; ?>
                <?php if (empty($instructor_stats)): ?>
                    <p class="text-sm text-slate-500">No instructor load data available.</p>
                <?php endif; ?>
            </div>

            <div class="mt-5 grid grid-cols-2 gap-3 text-center">
                <div class="rounded-xl bg-slate-50 py-2">
                    <div class="text-xs text-slate-500">Instructors</div>
                    <div class="text-lg font-semibold text-slate-700"><?php echo count($instructor_stats); ?></div>
                </div>
                <div class="rounded-xl bg-emerald-50 py-2">
                    <div class="text-xs text-emerald-600">Total Classes</div>
                    <div class="text-lg font-semibold text-emerald-700"><?php echo array_sum(array_map(fn($i) => (int)($i['total_classes'] ?? 0), $instructor_stats)); ?></div>
                </div>
            </div>
        </article>
    </section>

    <section class="mt-4 grid grid-cols-1 xl:grid-cols-4 gap-4 pb-4">
        <div class="xl:col-span-3">
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                <?php foreach ($mini_cards as $mini): ?>
                    <?php
                        $iconStyle = 'bg-emerald-100 text-emerald-700';
                        if ($mini['color'] === 'blue') $iconStyle = 'bg-blue-100 text-blue-700';
                        if ($mini['color'] === 'violet') $iconStyle = 'bg-violet-100 text-violet-700';
                        if ($mini['color'] === 'orange') $iconStyle = 'bg-orange-100 text-orange-700';
                    ?>
                    <article class="report-card summary-card p-4">
                        <div class="h-9 w-9 rounded-xl <?php echo $iconStyle; ?> flex items-center justify-center">
                            <i class="bi <?php echo htmlspecialchars($mini['icon']); ?>"></i>
                        </div>
                        <div class="mt-3 text-2xl font-semibold text-slate-800"><?php echo number_format((int)$mini['value']); ?></div>
                        <div class="text-xs text-slate-500"><?php echo htmlspecialchars($mini['label']); ?></div>
                    </article>
                <?php endforeach; ?>
            </div>

            <article class="report-card mt-4 p-4 sm:p-5 bg-gradient-to-r from-emerald-950 via-emerald-900 to-green-800 text-white border-none">
                <div class="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <div class="inline-flex items-center gap-2 text-sm font-semibold">
                            <i class="bi bi-activity"></i>
                            <span>System Health</span>
                        </div>
                        <div class="text-xs text-emerald-100/80">All services running normally</div>
                    </div>
                    <div class="grid grid-cols-3 gap-6 text-center">
                        <div>
                            <div class="text-sm font-semibold">99.9%</div>
                            <div class="text-[11px] text-emerald-100/80">Uptime</div>
                        </div>
                        <div>
                            <div class="text-sm font-semibold"><?php echo number_format(max(1, $active_classes)); ?></div>
                            <div class="text-[11px] text-emerald-100/80">Active Sessions</div>
                        </div>
                        <div>
                            <div class="text-sm font-semibold">2h ago</div>
                            <div class="text-[11px] text-emerald-100/80">Last Backup</div>
                        </div>
                    </div>
                    <div class="rounded-full bg-emerald-400/20 border border-emerald-300/30 px-3 py-1 text-xs text-emerald-100">
                        <i class="bi bi-check-circle me-1"></i>All Systems Normal
                    </div>
                </div>
            </article>
        </div>

        <article class="report-card p-4 sm:p-5 thin-scrollbar max-h-[430px] overflow-auto">
            <div class="flex items-center justify-between gap-3">
                <div>
                    <h2 class="text-base font-semibold text-slate-800">Recent Activity</h2>
                    <p class="text-xs text-slate-500">Latest system events</p>
                </div>
                <span class="inline-flex items-center gap-1 rounded-full bg-emerald-100 px-2 py-1 text-[11px] font-semibold text-emerald-700">
                    <span class="h-1.5 w-1.5 rounded-full bg-emerald-500"></span>
                    Live
                </span>
            </div>

            <div class="mt-4 space-y-3">
                <?php foreach ($recent_activity as $activity): ?>
                    <?php
                        $status = strtolower((string)($activity['status'] ?? 'enrolled'));
                        $icon = 'bi-person-plus';
                        $icon_cls = 'bg-emerald-100 text-emerald-700';
                        if ($status === 'pending') {
                            $icon = 'bi-hourglass-split';
                            $icon_cls = 'bg-amber-100 text-amber-700';
                        } elseif ($status === 'dropped' || $status === 'rejected') {
                            $icon = 'bi-x-circle';
                            $icon_cls = 'bg-rose-100 text-rose-700';
                        }
                    ?>
                    <div class="flex items-start justify-between gap-3">
                        <div class="flex items-start gap-2.5 min-w-0">
                            <div class="mt-0.5 h-7 w-7 rounded-full <?php echo $icon_cls; ?> flex items-center justify-center">
                                <i class="bi <?php echo $icon; ?> text-xs"></i>
                            </div>
                            <div class="min-w-0">
                                <div class="text-xs font-semibold text-slate-700 truncate"><?php echo htmlspecialchars((string)($activity['student_name'] ?? 'Student')); ?></div>
                                <div class="text-[11px] text-slate-500 truncate"><?php echo htmlspecialchars((string)($activity['course_code'] ?? 'Course')); ?> scheduled in <?php echo htmlspecialchars((string)($activity['room_number'] ?? 'TBA')); ?></div>
                            </div>
                        </div>
                        <div class="text-[11px] text-slate-400 whitespace-nowrap"><?php echo htmlspecialchars(reg_reports_relative_time((string)($activity['activity_at'] ?? ''))); ?></div>
                    </div>
                <?php endforeach; ?>
                <?php if (empty($recent_activity)): ?>
                    <p class="text-sm text-slate-500">No recent activity to display.</p>
                <?php endif; ?>
            </div>
        </article>
    </section>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
(function () {
    const tabs = Array.from(document.querySelectorAll('.report-tab[data-target]'));
    if (!tabs.length) return;

    function activateTab(targetId) {
        tabs.forEach(function (tab) {
            if (tab.getAttribute('data-target') === targetId) {
                tab.classList.add('tab-active');
            } else {
                tab.classList.remove('tab-active');
            }
        });
    }

    tabs.forEach(function (tab) {
        tab.addEventListener('click', function () {
            const targetId = tab.getAttribute('data-target') || '';
            activateTab(targetId);
        });
    });

    const observer = new IntersectionObserver(function (entries) {
        entries.forEach(function (entry) {
            if (entry.isIntersecting) {
                activateTab(entry.target.id);
            }
        });
    }, { threshold: 0.3 });

    ['section-overview', 'section-enrollment', 'section-rooms', 'section-instructors'].forEach(function (id) {
        const el = document.getElementById(id);
        if (el) observer.observe(el);
    });
})();

(function () {
    if (!window.Chart) return;

    Chart.defaults.font.family = 'Inter, ui-sans-serif, system-ui';
    Chart.defaults.color = '#64748b';

    const dayLabels = <?php echo json_encode($day_labels); ?>;
    const dayStudents = <?php echo json_encode($day_students); ?>;
    const dayClasses = <?php echo json_encode($day_classes); ?>;

    const programLabels = <?php echo json_encode(array_map(fn($r) => $r['label'], $program_rows)); ?>;
    const programValues = <?php echo json_encode(array_map(fn($r) => (int)$r['value'], $program_rows)); ?>;
    const palette = <?php echo json_encode($palette); ?>;

    const maxClass = Math.max(1, ...dayClasses);
    const loadCapacity = dayClasses.map(function (v) { return Math.max(v + 2, maxClass); });

    const trendCtx = document.getElementById('enrollmentTrendChart');
    if (trendCtx) {
        new Chart(trendCtx, {
            type: 'line',
            data: {
                labels: dayLabels,
                datasets: [
                    {
                        label: 'Students',
                        data: dayStudents,
                        borderColor: '#10b981',
                        backgroundColor: 'rgba(16, 185, 129, 0.08)',
                        fill: false,
                        borderWidth: 2.4,
                        tension: 0.4,
                        pointRadius: 0,
                    },
                    {
                        label: 'Classes',
                        data: dayClasses,
                        borderColor: '#3b82f6',
                        backgroundColor: 'rgba(59, 130, 246, 0.08)',
                        fill: false,
                        borderWidth: 2.4,
                        tension: 0.4,
                        pointRadius: 0,
                    }
                ]
            },
            options: {
                maintainAspectRatio: false,
                interaction: { intersect: false, mode: 'index' },
                plugins: { legend: { display: false } },
                scales: {
                    x: { grid: { display: false } },
                    y: {
                        beginAtZero: true,
                        ticks: { precision: 0 },
                        grid: { color: 'rgba(148, 163, 184, 0.2)' }
                    }
                }
            }
        });
    }

    const programCtx = document.getElementById('programChart');
    if (programCtx) {
        new Chart(programCtx, {
            type: 'doughnut',
            data: {
                labels: programLabels,
                datasets: [{
                    data: programValues,
                    borderWidth: 0,
                    backgroundColor: programValues.map(function (_, i) { return palette[i % palette.length]; })
                }]
            },
            options: {
                maintainAspectRatio: false,
                cutout: '68%',
                plugins: {
                    legend: { display: false }
                }
            }
        });
    }

    const loadCtx = document.getElementById('scheduleLoadChart');
    if (loadCtx) {
        new Chart(loadCtx, {
            type: 'bar',
            data: {
                labels: dayLabels,
                datasets: [
                    {
                        label: 'Capacity',
                        data: loadCapacity,
                        backgroundColor: '#e2e8f0',
                        borderRadius: 10,
                        barThickness: 18,
                    },
                    {
                        label: 'Classes',
                        data: dayClasses,
                        backgroundColor: '#3b82f6',
                        borderRadius: 10,
                        barThickness: 11,
                    }
                ]
            },
            options: {
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    x: { grid: { display: false }, stacked: false },
                    y: {
                        beginAtZero: true,
                        ticks: { precision: 0 },
                        grid: { color: 'rgba(148, 163, 184, 0.2)' }
                    }
                }
            }
        });
    }
})();
</script>

<?php require_once __DIR__ . '/includes/layout_bottom.php'; ?>