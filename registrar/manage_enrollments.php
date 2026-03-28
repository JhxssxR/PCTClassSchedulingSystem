<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is a registrar/admin
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'registrar'], true)) {
    header('Location: ../auth/login.php');
    exit();
}

// Schedules schema compatibility (some DB versions don't store end_time)
$schedule_cols_stmt = $conn->prepare('DESCRIBE schedules');
$schedule_cols_stmt->execute();
$schedule_cols = [];
foreach ($schedule_cols_stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $schedule_cols[$r['Field']] = true;
}

// Enrollments schema compatibility (date columns may vary)
$enroll_cols_stmt = $conn->prepare('DESCRIBE enrollments');
$enroll_cols_stmt->execute();
$enroll_cols = [];
foreach ($enroll_cols_stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $enroll_cols[$r['Field']] = true;
}

function schedule_end_expr(array $schedule_cols, string $alias = ''): string {
    $p = $alias !== '' ? ($alias . '.') : '';
    if (isset($schedule_cols['end_time'])) {
        return $p . 'end_time';
    }
    if (isset($schedule_cols['duration_minutes'])) {
        return "ADDTIME({$p}start_time, SEC_TO_TIME({$p}duration_minutes * 60))";
    }
    return "ADDTIME({$p}start_time, SEC_TO_TIME(120 * 60))";
}

function fmt_time_range(?string $start, ?string $end): string {
    $start = (string)($start ?? '');
    $end = (string)($end ?? '');
    if ($start === '' || $end === '') return '';
    return date('g:i A', strtotime($start)) . ' – ' . date('g:i A', strtotime($end));
}

function enrollment_status_map(PDO $conn): array {
    // Some schemas use 'approved' instead of 'enrolled'
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
    $pending = isset($allowed['pending']) ? 'pending' : 'pending';
    $rejected = isset($allowed['rejected']) ? 'rejected' : 'rejected';
    $dropped = isset($allowed['dropped']) ? 'dropped' : 'dropped';

    return [
        'active' => $active,
        'pending' => $pending,
        'rejected' => $rejected,
        'dropped' => $dropped,
        'allowed' => $allowed,
    ];
}

function display_status(string $db_status, string $active_db): string {
    $s = strtolower(trim($db_status));
    if ($s === strtolower($active_db)) {
        return 'active';
    }
    if ($s === 'rejected') {
        return 'dropped';
    }
    return $s;
}

function table_exists(PDO $conn, string $table): bool {
    $stmt = $conn->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
    $stmt->execute([$table]);
    return ((int)$stmt->fetchColumn()) > 0;
}

function status_badge(string $status): array {
    $s = strtolower($status);
    switch ($s) {
        case 'active':
            return ['bg' => 'bg-emerald-50', 'ring' => 'ring-emerald-200', 'text' => 'text-emerald-700', 'icon' => 'bi-record-circle', 'label' => 'Active'];
        case 'pending':
            return ['bg' => 'bg-amber-50', 'ring' => 'ring-amber-200', 'text' => 'text-amber-700', 'icon' => 'bi-clock', 'label' => 'Pending'];
        case 'dropped':
            return ['bg' => 'bg-rose-50', 'ring' => 'ring-rose-200', 'text' => 'text-rose-700', 'icon' => 'bi-x-circle', 'label' => 'Dropped'];
        default:
            return ['bg' => 'bg-slate-100', 'ring' => 'ring-slate-200', 'text' => 'text-slate-700', 'icon' => 'bi-circle', 'label' => ucfirst($s)];
    }
}

$status_map = enrollment_status_map($conn);
$active_db_status = $status_map['active'];
$allowed_statuses = $status_map['allowed'];

$supports_pending = empty($allowed_statuses) || isset($allowed_statuses['pending']);
$supports_rejected = empty($allowed_statuses) || isset($allowed_statuses['rejected']);
$supports_dropped = empty($allowed_statuses) || isset($allowed_statuses['dropped']);
$drop_target_status = $supports_dropped ? 'dropped' : ($supports_rejected ? 'rejected' : 'pending');

$status_filter = strtolower(trim((string)($_GET['status'] ?? 'all')));
$valid_filters = ['all', 'active'];
if ($supports_pending) $valid_filters[] = 'pending';
if ($supports_dropped || $supports_rejected) $valid_filters[] = 'dropped';
if (!in_array($status_filter, $valid_filters, true)) {
    $status_filter = 'all';
}

$q = trim((string)($_GET['q'] ?? ''));
$per_page = 10;
$page = max(1, (int)($_GET['page'] ?? 1));

$end_expr_sched = schedule_end_expr($schedule_cols, 'sched');
$end_expr_s = schedule_end_expr($schedule_cols, 's');

$subjects_table_exists = isset($schedule_cols['subject_id']) && table_exists($conn, 'subjects');
$subjects_join_sched = $subjects_table_exists ? 'LEFT JOIN subjects sub ON (sched.subject_id IS NOT NULL AND sched.subject_id = sub.id)' : '';
$class_name_expr = $subjects_table_exists ? 'COALESCE(sub.subject_name, c.course_name)' : 'c.course_name';
$subjects_join_s = $subjects_table_exists ? 'LEFT JOIN subjects sub_s ON (s.subject_id IS NOT NULL AND s.subject_id = sub_s.id)' : '';
$subject_name_expr_s = $subjects_table_exists ? 'COALESCE(sub_s.subject_name, c.course_name)' : 'c.course_name';

$where = [];
$params = [];
if ($status_filter === 'active') {
    $where[] = 'e.status = ?';
    $params[] = $active_db_status;
} elseif ($status_filter === 'dropped') {
    if ($supports_dropped && $supports_rejected) {
        $where[] = '(e.status = ? OR e.status = ?)';
        $params[] = 'dropped';
        $params[] = 'rejected';
    } elseif ($supports_dropped) {
        $where[] = 'e.status = ?';
        $params[] = 'dropped';
    } else {
        $where[] = 'e.status = ?';
        $params[] = 'rejected';
    }
} elseif ($status_filter !== 'all') {
    $where[] = 'e.status = ?';
    $params[] = $status_filter;
}
if ($q !== '') {
    $like = '%' . $q . '%';
    $where[] = "(
        u.first_name LIKE ? OR u.last_name LIKE ? OR u.student_id LIKE ?
        OR c.course_code LIKE ? OR c.course_name LIKE ?
        OR i.first_name LIKE ? OR i.last_name LIKE ?
        OR r.room_number LIKE ?
        OR sched.day_of_week LIKE ?
    )";
    $params = array_merge($params, [$like, $like, $like, $like, $like, $like, $like, $like, $like]);
}

$where_sql = '';
if (!empty($where)) {
    $where_sql = 'WHERE ' . implode(' AND ', $where);
}

$date_parts = [];
if (isset($enroll_cols['enrolled_at'])) $date_parts[] = 'e.enrolled_at';
if (isset($enroll_cols['enrollment_date'])) $date_parts[] = 'e.enrollment_date';
if (isset($enroll_cols['created_at'])) $date_parts[] = 'e.created_at';
if (isset($enroll_cols['updated_at'])) $date_parts[] = 'e.updated_at';
$display_date_expr = empty($date_parts) ? 'NULL' : ('COALESCE(' . implode(', ', $date_parts) . ')');

// Summary counts, mapped to display buckets
$counts_stmt = $conn->prepare('SELECT status, COUNT(*) AS c FROM enrollments GROUP BY status');
$counts_stmt->execute();
$status_counts = ['active' => 0, 'pending' => 0, 'dropped' => 0];
foreach ($counts_stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $db = strtolower((string)($row['status'] ?? ''));
    $disp = display_status($db, $active_db_status);
    if (isset($status_counts[$disp])) {
        $status_counts[$disp] += (int)($row['c'] ?? 0);
    }
}
$total_count = $status_counts['active'] + $status_counts['pending'] + $status_counts['dropped'];

// Build reusable FROM/JOIN fragment for export, count, and paginated list.
$enrollment_from_sql = "
    FROM enrollments e
    JOIN users u ON e.student_id = u.id
    JOIN schedules sched ON e.schedule_id = sched.id
    {$subjects_join_sched}
    JOIN courses c ON sched.course_id = c.id
    JOIN users i ON sched.instructor_id = i.id
    JOIN classrooms r ON sched.classroom_id = r.id
    {$where_sql}
";

$enrollment_select_cols = "
    SELECT e.*,
           CONCAT(u.first_name, ' ', u.last_name) as student_name,
           COALESCE(u.student_id, CONCAT('STU', LPAD(u.id, 6, '0'))) as student_number,
           c.course_code, c.course_name,
           {$class_name_expr} as class_name,
           CONCAT(i.first_name, ' ', i.last_name) as instructor_name,
           r.room_number,
           sched.day_of_week,
           sched.start_time,
           TIME_FORMAT({$end_expr_sched}, '%H:%i:%s') as end_time,
           {$display_date_expr} as display_date
";

if (isset($_GET['export']) && strtolower((string)$_GET['export']) === 'csv') {
    $stmt = $conn->prepare($enrollment_select_cols . $enrollment_from_sql . ' ORDER BY e.id DESC');
    $stmt->execute($params);
    $enrollments = $stmt->fetchAll(PDO::FETCH_ASSOC);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="enrollments_' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['#', 'Student', 'Student ID', 'Subject', 'Course', 'Date Enrolled', 'Status']);
    foreach ($enrollments as $idx => $enrollment) {
        $disp = display_status((string)($enrollment['status'] ?? ''), $active_db_status);
        $d = (string)($enrollment['display_date'] ?? '');
        fputcsv($out, [
            $idx + 1,
            (string)($enrollment['student_name'] ?? ''),
            (string)($enrollment['student_number'] ?? ''),
            (string)($enrollment['class_name'] ?? ''),
            (string)($enrollment['course_code'] ?? ''),
            $d !== '' ? date('M d, Y', strtotime($d)) : '',
            ucfirst($disp),
        ]);
    }
    fclose($out);
    exit();
}

$count_stmt = $conn->prepare('SELECT COUNT(*) ' . $enrollment_from_sql);
$count_stmt->execute($params);
$filtered_total = (int)$count_stmt->fetchColumn();

$total_pages = max(1, (int)ceil($filtered_total / $per_page));
if ($page > $total_pages) {
    $page = $total_pages;
}
$offset = ($page - 1) * $per_page;

$stmt = $conn->prepare($enrollment_select_cols . $enrollment_from_sql . ' ORDER BY e.id DESC LIMIT ' . (int)$per_page . ' OFFSET ' . (int)$offset);
$stmt->execute($params);
$enrollments = $stmt->fetchAll(PDO::FETCH_ASSOC);

$from_record = $filtered_total === 0 ? 0 : ($offset + 1);
$to_record = $filtered_total === 0 ? 0 : ($offset + count($enrollments));
$pagination_start = max(1, $page - 2);
$pagination_end = min($total_pages, $page + 2);

// Get all active schedules for the dropdown
$stmt = $conn->prepare("
    SELECT s.id,
              c.id as course_id,
              c.course_code, c.course_name,
        {$subject_name_expr_s} as subject_name,
           CONCAT(i.first_name, ' ', i.last_name) as instructor_name,
           r.room_number,
           s.day_of_week,
           s.start_time,
           TIME_FORMAT({$end_expr_s}, '%H:%i:%s') as end_time
    FROM schedules s
    JOIN courses c ON s.course_id = c.id
    {$subjects_join_s}
    JOIN users i ON s.instructor_id = i.id
    JOIN classrooms r ON s.classroom_id = r.id
    WHERE s.status = 'active'
    ORDER BY c.course_code, s.day_of_week, s.start_time
");
$stmt->execute();
$schedules = $stmt->fetchAll(PDO::FETCH_ASSOC);
$has_schedule_options = !empty($schedules);

$all_courses = [];
$all_subjects = [];
if (!$has_schedule_options) {
    $courses_stmt = $conn->prepare("SELECT id, course_code, course_name FROM courses ORDER BY course_code, course_name");
    $courses_stmt->execute();
    $all_courses = $courses_stmt->fetchAll(PDO::FETCH_ASSOC);

    if (table_exists($conn, 'subjects')) {
        $subjects_stmt = $conn->prepare("SELECT id, subject_code, subject_name FROM subjects ORDER BY subject_name, subject_code");
        $subjects_stmt->execute();
        $all_subjects = $subjects_stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

// Get all students for the dropdown
$stmt = $conn->prepare("
    SELECT id, student_id, first_name, last_name
    FROM users
    WHERE role = 'student'
    ORDER BY last_name, first_name
");
$stmt->execute();
$students = $stmt->fetchAll(PDO::FETCH_ASSOC);

$page_title = 'Enrollments';
$breadcrumbs = 'Registrar / Enrollments';
$active_page = 'enrollments';
require_once __DIR__ . '/includes/layout_top.php';
?>

<?php
    $export_params = ['export' => 'csv'];
    if ($status_filter !== 'all') $export_params['status'] = $status_filter;
    if ($q !== '') $export_params['q'] = $q;
    $export_url = 'manage_enrollments.php?' . http_build_query($export_params);

    $pagination_url = function (int $target_page) use ($status_filter, $q): string {
        $params = ['page' => $target_page];
        if ($status_filter !== 'all') $params['status'] = $status_filter;
        if ($q !== '') $params['q'] = $q;
        return 'manage_enrollments.php?' . http_build_query($params);
    };

    $status_urls = [
        'all' => 'manage_enrollments.php?' . http_build_query(array_filter(['status' => 'all', 'q' => $q], fn($v) => $v !== '')),
        'active' => 'manage_enrollments.php?' . http_build_query(array_filter(['status' => 'active', 'q' => $q], fn($v) => $v !== '')),
        'pending' => 'manage_enrollments.php?' . http_build_query(array_filter(['status' => $supports_pending ? 'pending' : 'all', 'q' => $q], fn($v) => $v !== '')),
        'dropped' => 'manage_enrollments.php?' . http_build_query(array_filter(['status' => ($supports_dropped || $supports_rejected) ? 'dropped' : 'all', 'q' => $q], fn($v) => $v !== '')),
    ];
?>

<div class="flex flex-col md:flex-row md:items-start md:justify-between gap-4">
    <div>
        <h1 class="text-4xl font-bold tracking-tight text-slate-800">Enrollments</h1>
        <p class="mt-1 text-sm text-slate-500"><?php echo (int)$total_count; ?> total enrollment records</p>
    </div>

    <div class="flex items-center gap-3">
        <a href="<?php echo htmlspecialchars($export_url); ?>" class="inline-flex items-center gap-2 rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-600 shadow-sm hover:bg-slate-50">
            <i class="bi bi-download"></i>
            Export CSV
        </a>
        <button id="addEnrollmentBtn" type="button" class="inline-flex items-center gap-2 rounded-xl bg-emerald-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-emerald-700">
            <i class="bi bi-plus-lg"></i>
            Enroll Student
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

<section class="mt-5 grid grid-cols-1 lg:grid-cols-3 gap-4">
    <a href="<?php echo htmlspecialchars($status_urls['active']); ?>" class="block rounded-2xl border border-emerald-200 bg-emerald-50 px-5 py-4 transition-all duration-200 hover:-translate-y-0.5 hover:shadow-lg <?php echo $status_filter === 'active' ? 'ring-2 ring-emerald-300 shadow-[0_0_0_5px_rgba(16,185,129,0.22)]' : ''; ?>">
        <div class="text-4xl font-bold leading-none text-emerald-700"><?php echo (int)$status_counts['active']; ?></div>
        <div class="mt-2 text-sm font-semibold text-emerald-700">Active Enrollments</div>
    </a>
    <a href="<?php echo htmlspecialchars($status_urls['pending']); ?>" class="block rounded-2xl border border-amber-200 bg-amber-50 px-5 py-4 transition-all duration-200 hover:-translate-y-0.5 hover:shadow-lg <?php echo $status_filter === 'pending' ? 'ring-2 ring-amber-300 shadow-[0_0_0_5px_rgba(245,158,11,0.20)]' : ''; ?>">
        <div class="text-4xl font-bold leading-none text-amber-700"><?php echo (int)$status_counts['pending']; ?></div>
        <div class="mt-2 text-sm font-semibold text-amber-700">Pending Approval</div>
    </a>
    <a href="<?php echo htmlspecialchars($status_urls['dropped']); ?>" class="block rounded-2xl border border-rose-200 bg-rose-50 px-5 py-4 transition-all duration-200 hover:-translate-y-0.5 hover:shadow-lg <?php echo $status_filter === 'dropped' ? 'ring-2 ring-rose-300 shadow-[0_0_0_5px_rgba(244,63,94,0.18)]' : ''; ?>">
        <div class="text-4xl font-bold leading-none text-rose-700"><?php echo (int)$status_counts['dropped']; ?></div>
        <div class="mt-2 text-sm font-semibold text-rose-700">Dropped</div>
    </a>
</section>

<section class="mt-4 rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden">
    <div class="p-4 border-b border-slate-200 flex flex-col gap-3">
        <div class="flex flex-col xl:flex-row xl:items-center gap-3">
            <form method="GET" class="relative w-full">
                <i class="bi bi-search absolute left-3 top-1/2 -translate-y-1/2 text-slate-400"></i>
                <input type="hidden" name="status" value="<?php echo htmlspecialchars($status_filter); ?>">
                <input name="q" value="<?php echo htmlspecialchars($q); ?>" type="text" placeholder="Search by student, ID, or subject..." class="w-full rounded-xl border border-slate-200 bg-slate-50 pl-10 pr-4 py-2.5 text-sm text-slate-700 outline-none focus:bg-white focus:ring-2 focus:ring-emerald-200">
            </form>

            <div class="flex items-center gap-2">
                <?php
                    $chips = ['all' => 'All', 'active' => 'Active'];
                    if ($supports_pending) $chips['pending'] = 'Pending';
                    if ($supports_dropped || $supports_rejected) $chips['dropped'] = 'Dropped';
                ?>
                <?php foreach ($chips as $k => $label): ?>
                    <?php
                        $is_active = ($status_filter === $k);
                        $href = 'manage_enrollments.php?status=' . urlencode($k);
                        if ($q !== '') {
                            $href .= '&q=' . urlencode($q);
                        }
                    ?>
                    <a href="<?php echo htmlspecialchars($href); ?>" class="inline-flex items-center rounded-xl px-4 py-2 text-sm font-semibold transition-all duration-200 hover:-translate-y-0.5 <?php echo $is_active ? 'bg-emerald-600 text-white shadow-[0_0_0_4px_rgba(16,185,129,0.18)] hover:bg-emerald-700' : 'bg-slate-50 text-slate-500 ring-1 ring-slate-200 hover:bg-slate-100 hover:text-slate-700'; ?>">
                        <?php echo htmlspecialchars($label); ?>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <div class="overflow-x-auto">
        <table class="min-w-full text-sm">
            <thead class="bg-slate-50 text-[11px] uppercase tracking-wider text-slate-400">
                <tr>
                    <th class="px-4 py-3 text-left font-semibold">#</th>
                    <th class="px-4 py-3 text-left font-semibold">Student</th>
                    <th class="px-4 py-3 text-left font-semibold">Subject</th>
                    <th class="px-4 py-3 text-left font-semibold">Course</th>
                    <th class="px-4 py-3 text-left font-semibold">Date Enrolled</th>
                    <th class="px-4 py-3 text-left font-semibold">Status</th>
                    <th class="px-4 py-3 text-center font-semibold">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($enrollments as $idx => $enrollment): ?>
                    <?php
                        $disp_status = display_status((string)($enrollment['status'] ?? ''), $active_db_status);
                        $badge = status_badge($disp_status);
                        $display_idx = (int)($offset + $idx + 1);
                    ?>
                    <tr class="border-t border-slate-100 text-slate-600 hover:bg-slate-50/80">
                        <td class="px-4 py-3 text-xs text-slate-400"><?php echo $display_idx; ?></td>
                        <td class="px-4 py-3">
                            <div class="font-semibold text-slate-700"><?php echo htmlspecialchars($enrollment['student_name'] ?? ''); ?></div>
                            <div class="text-xs text-slate-400"><?php echo htmlspecialchars($enrollment['student_number'] ?? ''); ?></div>
                        </td>
                        <td class="px-4 py-3 text-slate-600"><?php echo htmlspecialchars($enrollment['class_name'] ?? ($enrollment['course_name'] ?? '')); ?></td>
                        <td class="px-4 py-3">
                            <span class="inline-flex items-center rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-600"><?php echo htmlspecialchars($enrollment['course_code'] ?? ''); ?></span>
                        </td>
                        <td class="px-4 py-3 text-slate-500">
                            <?php
                                $d = (string)($enrollment['display_date'] ?? '');
                                echo $d !== '' ? htmlspecialchars(date('M d, Y', strtotime($d))) : 'N/A';
                            ?>
                        </td>
                        <td class="px-4 py-3">
                            <span class="inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-semibold ring-1 ring-inset <?php echo htmlspecialchars($badge['bg'] . ' ' . $badge['text'] . ' ' . $badge['ring']); ?>">
                                <i class="bi <?php echo htmlspecialchars($badge['icon']); ?>"></i>
                                <?php echo htmlspecialchars($badge['label']); ?>
                            </span>
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex items-center justify-center gap-3 text-slate-400">
                                <button type="button" class="hover:text-slate-600" onclick="openEditStatusModal(<?php echo (int)$enrollment['id']; ?>, '<?php echo htmlspecialchars($disp_status); ?>')" aria-label="Edit enrollment">
                                    <i class="bi bi-pencil"></i>
                                </button>
                                <button type="button" class="<?php echo $disp_status === 'dropped' ? 'opacity-40 cursor-not-allowed' : 'hover:text-rose-500'; ?>" <?php echo $disp_status === 'dropped' ? 'disabled' : ''; ?> onclick="quickDropEnrollment(<?php echo (int)$enrollment['id']; ?>, '<?php echo htmlspecialchars($disp_status); ?>')" aria-label="Drop enrollment">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>

                <?php if (empty($enrollments)): ?>
                    <tr>
                        <td colspan="7" class="px-4 py-10 text-center text-slate-500">No enrollments found</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <div class="px-4 py-3 text-xs text-slate-400 border-t border-slate-100">
        Showing <?php echo (int)$from_record; ?> to <?php echo (int)$to_record; ?> of <?php echo (int)$filtered_total; ?> records
    </div>

    <?php if ($total_pages > 1): ?>
        <div class="px-4 py-3 border-t border-slate-100 flex items-center justify-between gap-3">
            <div class="text-xs text-slate-400">Page <?php echo (int)$page; ?> of <?php echo (int)$total_pages; ?></div>
            <div class="flex items-center gap-1.5">
                <?php if ($page > 1): ?>
                    <a href="<?php echo htmlspecialchars($pagination_url($page - 1)); ?>" class="inline-flex items-center rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold text-slate-600 hover:bg-slate-50">Prev</a>
                <?php else: ?>
                    <span class="inline-flex items-center rounded-lg border border-slate-200 bg-slate-50 px-3 py-1.5 text-xs font-semibold text-slate-400">Prev</span>
                <?php endif; ?>

                <?php for ($p = $pagination_start; $p <= $pagination_end; $p++): ?>
                    <a href="<?php echo htmlspecialchars($pagination_url($p)); ?>" class="inline-flex h-8 min-w-8 items-center justify-center rounded-lg px-2 text-xs font-semibold <?php echo $p === $page ? 'bg-emerald-600 text-white' : 'border border-slate-200 bg-white text-slate-600 hover:bg-slate-50'; ?>">
                        <?php echo (int)$p; ?>
                    </a>
                <?php endfor; ?>

                <?php if ($page < $total_pages): ?>
                    <a href="<?php echo htmlspecialchars($pagination_url($page + 1)); ?>" class="inline-flex items-center rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold text-slate-600 hover:bg-slate-50">Next</a>
                <?php else: ?>
                    <span class="inline-flex items-center rounded-lg border border-slate-200 bg-slate-50 px-3 py-1.5 text-xs font-semibold text-slate-400">Next</span>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
</section>

<!-- Edit Status Modal -->
<div id="editStatusModal" class="fixed inset-0 z-50 hidden" aria-hidden="true">
    <div class="absolute inset-0 bg-slate-900/50" data-modal-close="editStatusModal"></div>
    <div class="relative mx-auto my-10 w-[92%] max-w-md">
        <div class="rounded-2xl bg-white shadow-xl border border-slate-200 overflow-hidden">
            <div class="px-5 py-4 border-b border-slate-200 flex items-center justify-between">
                <div class="text-base font-semibold text-slate-900">Edit Enrollment Status</div>
                <button type="button" class="h-9 w-9 inline-flex items-center justify-center rounded-xl hover:bg-slate-100" data-modal-close="editStatusModal" aria-label="Close"><i class="bi bi-x-lg"></i></button>
            </div>

            <form id="editStatusForm" action="process_enrollment.php" method="POST" class="p-5">
                <input type="hidden" name="action" value="update_status">
                <input type="hidden" id="edit_status_enrollment_id" name="enrollment_id" value="">

                <div>
                    <label class="block text-sm font-semibold text-slate-700 mb-1">Status</label>
                    <select id="edit_status_value" name="status" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm" required>
                        <option value="enrolled">Active</option>
                        <?php if ($supports_pending): ?><option value="pending">Pending</option><?php endif; ?>
                        <?php if ($supports_dropped): ?><option value="dropped">Dropped</option><?php endif; ?>
                        <?php if ($supports_rejected): ?><option value="rejected">Rejected</option><?php endif; ?>
                    </select>
                </div>

                <div class="mt-5 flex items-center justify-end gap-3">
                    <button type="button" class="inline-flex items-center rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-50" data-modal-close="editStatusModal">Cancel</button>
                    <button type="submit" class="inline-flex items-center rounded-xl bg-emerald-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-emerald-700">Save</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Add Enrollment Modal -->
<div id="addEnrollmentModal" class="fixed inset-0 z-50 hidden" aria-hidden="true">
    <div class="absolute inset-0 bg-slate-900/50" data-modal-close="addEnrollmentModal"></div>
    <div class="relative mx-auto my-6 w-[92%] max-w-lg sm:my-10">
        <div class="rounded-2xl bg-white shadow-xl border border-slate-200 overflow-hidden">
            <div class="px-5 py-4 border-b border-slate-200 flex items-center justify-between">
                <div class="text-base font-semibold text-slate-900">Enroll New Student</div>
                <button type="button" class="h-9 w-9 inline-flex items-center justify-center rounded-xl hover:bg-slate-100" data-modal-close="addEnrollmentModal" aria-label="Close"><i class="bi bi-x-lg"></i></button>
            </div>

            <?php
                $courses_map = [];
                if ($has_schedule_options) {
                    foreach ($schedules as $schedule) {
                        $course_id = (int)($schedule['course_id'] ?? 0);
                        if ($course_id <= 0) continue;

                        $course_label = trim((string)($schedule['course_code'] ?? ''));
                        if ($course_label === '') {
                            $course_label = trim((string)($schedule['course_name'] ?? ''));
                        }
                        if ($course_label === '') {
                            $course_label = 'Course ' . $course_id;
                        }
                        $courses_map[$course_id] = $course_label;
                    }
                } else {
                    foreach ($all_courses as $course) {
                        $course_id = (int)($course['id'] ?? 0);
                        if ($course_id <= 0) continue;

                        $course_label = trim((string)($course['course_code'] ?? ''));
                        if ($course_label === '') {
                            $course_label = trim((string)($course['course_name'] ?? ''));
                        }
                        if ($course_label === '') {
                            $course_label = 'Course ' . $course_id;
                        }
                        $courses_map[$course_id] = $course_label;
                    }
                }
                asort($courses_map, SORT_NATURAL | SORT_FLAG_CASE);
            ?>

            <form id="addEnrollmentForm" action="process_enrollment.php" method="POST" class="p-5">
                <input type="hidden" name="action" value="add">

                <div class="space-y-4">
                    <div>
                        <label class="block text-sm font-semibold text-slate-700 mb-1">Student</label>
                        <select name="student_id" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm" required>
                            <option value="" selected disabled>Select student</option>
                            <?php foreach ($students as $student): ?>
                                <option value="<?php echo (int)$student['id']; ?>"><?php echo htmlspecialchars(($student['last_name'] ?? '') . ', ' . ($student['first_name'] ?? '') . ' (' . ($student['student_id'] ?? '') . ')'); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div>
                            <label class="block text-sm font-semibold text-slate-700 mb-1">Course</label>
                            <select id="addEnrollmentCourse" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm" required>
                                <option value="" selected disabled>Select course</option>
                                <?php foreach ($courses_map as $course_id => $course_label): ?>
                                    <option value="<?php echo (int)$course_id; ?>"><?php echo htmlspecialchars((string)$course_label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div>
                            <label class="block text-sm font-semibold text-slate-700 mb-1">Status</label>
                            <select id="addEnrollmentStatus" name="status" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm" required>
                                <option value="active">Active</option>
                                <option value="pending" selected>Pending</option>
                                <option value="dropped">Dropped</option>
                            </select>
                        </div>
                    </div>

                    <div>
                        <label class="block text-sm font-semibold text-slate-700 mb-1">Subject</label>
                        <?php if ($has_schedule_options): ?>
                            <select id="addEnrollmentSubject" name="schedule_id" data-has-schedules="1" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm" required>
                                <option value="" selected disabled>Select subject</option>
                                <?php foreach ($schedules as $schedule): ?>
                                    <option value="<?php echo (int)$schedule['id']; ?>" data-course="<?php echo (int)($schedule['course_id'] ?? 0); ?>"><?php
                                        $label = ($schedule['subject_name'] ?? $schedule['course_name'] ?? '');
                                        $label .= ' | ' . ($schedule['day_of_week'] ?? '') . ' ';
                                        $label .= fmt_time_range($schedule['start_time'] ?? null, $schedule['end_time'] ?? null);
                                        $label .= ' | Room ' . ($schedule['room_number'] ?? '');
                                        echo htmlspecialchars($label);
                                    ?></option>
                                <?php endforeach; ?>
                            </select>
                        <?php else: ?>
                            <select id="addEnrollmentSubject" data-has-schedules="0" class="w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm text-slate-600" disabled>
                                <option selected><?php echo !empty($all_subjects) ? 'Available subjects (schedule not set)' : 'No subjects available'; ?></option>
                                <?php foreach ($all_subjects as $subject): ?>
                                    <?php
                                        $subject_label = trim((string)($subject['subject_code'] ?? ''));
                                        if ($subject_label !== '') {
                                            $subject_label .= ' - ';
                                        }
                                        $subject_label .= (string)($subject['subject_name'] ?? '');
                                    ?>
                                    <option><?php echo htmlspecialchars($subject_label); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <input type="hidden" name="schedule_id" value="">
                            <p class="mt-1 text-xs text-amber-600">No schedules found. Please create schedules first.</p>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="mt-5 grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <button type="button" class="inline-flex items-center rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-50" data-modal-close="addEnrollmentModal">Cancel</button>
                    <button type="submit" class="inline-flex items-center justify-center rounded-xl px-4 py-2.5 text-sm font-semibold text-white <?php echo $has_schedule_options ? 'bg-emerald-600 hover:bg-emerald-700' : 'bg-slate-400 cursor-not-allowed'; ?>" <?php echo $has_schedule_options ? '' : 'disabled'; ?>>Enroll Student</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
(function () {
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

    const addEnrollmentBtn = document.getElementById('addEnrollmentBtn');
    const addEnrollmentForm = document.getElementById('addEnrollmentForm');
    const addEnrollmentCourse = document.getElementById('addEnrollmentCourse');
    const addEnrollmentSubject = document.getElementById('addEnrollmentSubject');
    const hasScheduleOptions = addEnrollmentSubject?.getAttribute('data-has-schedules') === '1';

    function filterSubjectsByCourse(selectFirstMatch) {
        if (!addEnrollmentCourse || !addEnrollmentSubject || !hasScheduleOptions) return;
        const selectedCourse = String(addEnrollmentCourse.value || '');
        let firstVisibleValue = '';
        let selectedVisible = false;

        Array.from(addEnrollmentSubject.options).forEach(function (option, index) {
            if (index === 0) {
                option.hidden = false;
                return;
            }
            const optionCourse = option.getAttribute('data-course') || '';
            const visible = selectedCourse !== '' && optionCourse === selectedCourse;
            option.hidden = !visible;
            if (visible && firstVisibleValue === '') {
                firstVisibleValue = option.value;
            }
            if (visible && option.selected) {
                selectedVisible = true;
            }
        });

        if (selectFirstMatch && firstVisibleValue !== '') {
            addEnrollmentSubject.value = firstVisibleValue;
            addEnrollmentSubject.setCustomValidity('');
            return;
        }

        if (!selectedVisible) {
            addEnrollmentSubject.value = '';
        }
    }

    function resetEnrollmentModal() {
        addEnrollmentForm?.reset();
        addEnrollmentSubject?.setCustomValidity('');
        if (addEnrollmentCourse && addEnrollmentCourse.options.length > 1) {
            addEnrollmentCourse.selectedIndex = 1;
        }
        if (hasScheduleOptions) {
            filterSubjectsByCourse(true);
        }
    }

    addEnrollmentBtn?.addEventListener('click', function () {
        resetEnrollmentModal();
        openModal('addEnrollmentModal');
    });

    addEnrollmentCourse?.addEventListener('change', function () {
        if (hasScheduleOptions) {
            filterSubjectsByCourse(true);
        }
    });

    addEnrollmentForm?.addEventListener('submit', function (event) {
        if (!hasScheduleOptions) {
            event.preventDefault();
            alert('No schedules available yet. Please create schedules first.');
            return;
        }
        if (!addEnrollmentSubject?.value) {
            event.preventDefault();
            addEnrollmentSubject?.setCustomValidity('Please select a subject.');
            addEnrollmentSubject?.reportValidity();
            return;
        }
        addEnrollmentSubject?.setCustomValidity('');
    });

    document.querySelectorAll('[data-modal-close]')?.forEach(function (b) {
        b.addEventListener('click', function () {
            const target = b.getAttribute('data-modal-close');
            if (target) closeModal(target);
        });
    });

    function submitStatusUpdate(enrollmentId, status) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = 'process_enrollment.php';

        const actionInput = document.createElement('input');
        actionInput.type = 'hidden';
        actionInput.name = 'action';
        actionInput.value = 'update_status';

        const enrollmentIdInput = document.createElement('input');
        enrollmentIdInput.type = 'hidden';
        enrollmentIdInput.name = 'enrollment_id';
        enrollmentIdInput.value = String(enrollmentId);

        const statusInput = document.createElement('input');
        statusInput.type = 'hidden';
        statusInput.name = 'status';
        statusInput.value = status;

        form.appendChild(actionInput);
        form.appendChild(enrollmentIdInput);
        form.appendChild(statusInput);
        document.body.appendChild(form);
        form.submit();
    }

    window.updateEnrollmentStatus = function (enrollmentId, status) {
        submitStatusUpdate(enrollmentId, status);
    }

    window.openEditStatusModal = function (enrollmentId, currentStatus) {
        const idInput = document.getElementById('edit_status_enrollment_id');
        const statusSelect = document.getElementById('edit_status_value');
        if (!idInput || !statusSelect) return;

        idInput.value = String(enrollmentId);
        const normalized = currentStatus === 'active' ? 'enrolled' : currentStatus;
        const exists = Array.from(statusSelect.options).some(function (opt) {
            return opt.value === normalized;
        });
        statusSelect.value = exists ? normalized : statusSelect.options[0].value;
        openModal('editStatusModal');
    }

    window.quickDropEnrollment = function (enrollmentId, currentStatus) {
        if (currentStatus === 'dropped') return;
        if (!confirm('Are you sure you want to drop this enrollment?')) return;
        submitStatusUpdate(enrollmentId, '<?php echo htmlspecialchars($drop_target_status); ?>');
    }

})();
</script>

<?php require_once __DIR__ . '/includes/layout_bottom.php'; ?>
