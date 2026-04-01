<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is a registrar/admin
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'registrar'], true)) {
    header('Location: ../auth/login.php');
    exit();
}

$initial_search = trim((string)($_GET['search'] ?? ''));

// Subjects table may not exist on older DBs
$subjects_table_exists_stmt = $conn->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'subjects'");
$subjects_table_exists_stmt->execute();
$subjects_table_exists = ((int)$subjects_table_exists_stmt->fetchColumn() > 0);

function __pct_enum_values_from_type($type) {
    $type = (string)$type;
    if (stripos($type, 'enum(') !== 0) return [];
    $inside = trim(substr($type, 5), ") ");
    if ($inside === '') return [];
    $parts = str_getcsv($inside, ',', "'");
    $values = [];
    foreach ($parts as $p) {
        $v = trim((string)$p);
        if ($v !== '') $values[] = $v;
    }
    return $values;
}

// Schedules schema compatibility
$schedule_cols_stmt = $conn->prepare('DESCRIBE schedules');
$schedule_cols_stmt->execute();
$schedule_cols = [];
$schedule_status_type = '';
foreach ($schedule_cols_stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $field = (string)($r['Field'] ?? '');
    if ($field !== '') {
        $schedule_cols[$field] = true;
        if ($field === 'status') {
            $schedule_status_type = (string)($r['Type'] ?? '');
        }
    }
}

if (isset($schedule_cols['end_time'])) {
    $end_time_expr = 's.end_time';
} elseif (isset($schedule_cols['duration_minutes'])) {
    $end_time_expr = 'ADDTIME(s.start_time, SEC_TO_TIME(s.duration_minutes * 60))';
} else {
    $end_time_expr = 'ADDTIME(s.start_time, SEC_TO_TIME(120 * 60))';
}

$status_options = __pct_enum_values_from_type($schedule_status_type);
if (empty($status_options)) {
    $status_options = ['active', 'cancelled', 'completed'];
}

// Fetch schedules as "Classes"
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
    LEFT JOIN users i ON i.id = s.instructor_id
    JOIN classrooms cr ON cr.id = s.classroom_id
    ORDER BY (s.status = 'active') DESC, {$order_by_name}, s.day_of_week, s.start_time
");
$stmt->execute();
$classes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Select options for modals
$subjects = [];
if ($subjects_enabled) {
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

$page_title = 'Classes';
$breadcrumbs = 'Registrar / Classes';
$active_page = 'classes';
require_once __DIR__ . '/includes/layout_top.php';
?>

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
                        $search = strtolower(($display_name) . ' ' . ($display_code) . ' ' . ($class['instructor_name'] ?? '') . ' ' . ($class['room_number'] ?? ''));
                    ?>
                    <tr class="class-row border-t border-slate-100 hover:bg-slate-50/60" data-search="<?php echo htmlspecialchars($search); ?>">
                        <td class="px-5 py-4">
                            <div class="font-semibold text-slate-900"><?php echo htmlspecialchars($display_name); ?></div>
                            <div class="text-xs text-slate-500"><?php echo htmlspecialchars($display_code); ?></div>
                        </td>
                        <td class="px-5 py-4 text-slate-700"><?php echo htmlspecialchars($class['instructor_name'] ?? 'Not Assigned'); ?></td>
                        <td class="px-5 py-4 text-slate-700"><?php echo htmlspecialchars($class['room_number'] ?? ''); ?></td>
                        <td class="px-5 py-4">
                            <div class="text-slate-700"><?php echo htmlspecialchars($class['day_of_week'] ?? ''); ?></div>
                            <div class="text-xs text-slate-500"><?php echo htmlspecialchars(time_range_label($class['start_time'] ?? '', $class['end_time'] ?? '')); ?></div>
                        </td>
                        <td class="px-5 py-4">
                            <div class="flex items-center gap-3">
                                <div class="min-w-[76px] text-slate-700"><?php echo htmlspecialchars($enrolled . '/' . ($max > 0 ? $max : '—')); ?></div>
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
                                <a class="h-9 w-9 inline-flex items-center justify-center rounded-xl hover:bg-slate-100" href="../admin/view_class.php?id=<?php echo (int)$class['id']; ?>" title="View">
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
                        <td colspan="7" class="px-5 py-10 text-center text-slate-500">No classes found</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Add Class Modal -->
<div id="addClassModal" class="fixed inset-0 z-50 hidden" aria-hidden="true">
    <div class="absolute inset-0 bg-slate-900/50" data-modal-close="addClassModal"></div>
    <div class="relative mx-auto my-10 w-[92%] max-w-3xl">
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
                            <option value="">Select course</option>
                            <?php foreach ($courses as $c): ?>
                                <option value="<?php echo (int)$c['id']; ?>"><?php echo htmlspecialchars(($c['course_code'] ?? '') . ' — ' . ($c['course_name'] ?? '')); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label class="block text-sm font-semibold text-slate-700 mb-1">Year Level</label>
                        <select name="year_level" id="add_year_level" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm" required>
                            <option value="">Select year level</option>
                            <option value="1">1st Year</option>
                            <option value="2">2nd Year</option>
                            <option value="3">3rd Year</option>
                            <option value="4">4th Year</option>
                        </select>
                    </div>

                    <div>
                        <label class="block text-sm font-semibold text-slate-700 mb-1">Subject</label>
                        <select name="subject_id" id="add_subject_id" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm" <?php echo $subjects_enabled ? 'required' : ''; ?> <?php echo $subjects_enabled ? '' : 'disabled'; ?>>
                            <option value="">Select subject</option>
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
                            <option value="">Select instructor</option>
                            <?php foreach ($instructors as $i): ?>
                                <option value="<?php echo (int)$i['id']; ?>"><?php echo htmlspecialchars(($i['last_name'] ?? '') . ', ' . ($i['first_name'] ?? '') . (!empty($i['email']) ? (' (' . $i['email'] . ')') : '')); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label class="block text-sm font-semibold text-slate-700 mb-1">Classroom</label>
                        <select name="classroom_id" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm" required>
                            <option value="">Select room</option>
                            <?php foreach ($classrooms as $r): ?>
                                <option value="<?php echo (int)$r['id']; ?>"><?php echo htmlspecialchars($r['room_number'] ?? ''); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label class="block text-sm font-semibold text-slate-700 mb-1">Day of week</label>
                        <select name="day_of_week" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm" required>
                            <?php foreach (['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'] as $d): ?>
                                <option value="<?php echo htmlspecialchars($d); ?>"><?php echo htmlspecialchars($d); ?></option>
                            <?php endforeach; ?>
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
                        <input type="number" name="max_students" min="1" value="30" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm" required>
                    </div>

                    <div>
                        <label class="block text-sm font-semibold text-slate-700 mb-1">Status</label>
                        <select name="status" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm">
                            <?php foreach ($status_options as $s): ?>
                                <option value="<?php echo htmlspecialchars($s); ?>" <?php echo ($s === 'active') ? 'selected' : ''; ?>><?php echo htmlspecialchars(ucfirst($s)); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label class="block text-sm font-semibold text-slate-700 mb-1">Semester</label>
                        <input type="text" name="semester" value="1st Semester" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm" required>
                    </div>

                    <div>
                        <label class="block text-sm font-semibold text-slate-700 mb-1">Academic year</label>
                        <input type="text" name="academic_year" value="<?php echo htmlspecialchars(date('Y') . '-' . (date('Y') + 1)); ?>" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm" required>
                    </div>
                </div>

                <div class="mt-5 flex items-center justify-end gap-3">
                    <button type="button" class="inline-flex items-center rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-50" data-modal-close="addClassModal">Cancel</button>
                    <button type="submit" class="inline-flex items-center rounded-xl bg-emerald-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-emerald-700">Save Class</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Class Modal -->
<div id="editClassModal" class="fixed inset-0 z-50 hidden" aria-hidden="true">
    <div class="absolute inset-0 bg-slate-900/50" data-modal-close="editClassModal"></div>
    <div class="relative mx-auto my-10 w-[92%] max-w-3xl">
        <div class="rounded-2xl bg-white shadow-xl border border-slate-200 overflow-hidden">
            <div class="px-5 py-4 border-b border-slate-200 flex items-center justify-between">
                <div class="text-base font-semibold text-slate-900">Edit Class</div>
                <button type="button" class="h-9 w-9 inline-flex items-center justify-center rounded-xl hover:bg-slate-100" data-modal-close="editClassModal" aria-label="Close">
                    <i class="bi bi-x-lg"></i>
                </button>
            </div>

            <form action="process_class.php" method="POST" class="p-5">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="schedule_id" id="edit_schedule_id" value="">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-semibold text-slate-700 mb-1">Course</label>
                        <select name="course_id" id="edit_course_id" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm" required>
                            <option value="">Select course</option>
                            <?php foreach ($courses as $c): ?>
                                <option value="<?php echo (int)$c['id']; ?>"><?php echo htmlspecialchars(($c['course_code'] ?? '') . ' — ' . ($c['course_name'] ?? '')); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label class="block text-sm font-semibold text-slate-700 mb-1">Year Level</label>
                        <select name="year_level" id="edit_year_level" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm" required>
                            <option value="">Select year level</option>
                            <option value="1">1st Year</option>
                            <option value="2">2nd Year</option>
                            <option value="3">3rd Year</option>
                            <option value="4">4th Year</option>
                        </select>
                    </div>

                    <div>
                        <label class="block text-sm font-semibold text-slate-700 mb-1">Subject</label>
                        <select name="subject_id" id="edit_subject_id" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm" <?php echo $subjects_enabled ? 'required' : ''; ?> <?php echo $subjects_enabled ? '' : 'disabled'; ?>>
                            <option value="">Select subject</option>
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
                            <option value="">Select instructor</option>
                            <?php foreach ($instructors as $i): ?>
                                <option value="<?php echo (int)$i['id']; ?>"><?php echo htmlspecialchars(($i['last_name'] ?? '') . ', ' . ($i['first_name'] ?? '') . (!empty($i['email']) ? (' (' . $i['email'] . ')') : '')); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label class="block text-sm font-semibold text-slate-700 mb-1">Classroom</label>
                        <select name="classroom_id" id="edit_classroom_id" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm" required>
                            <option value="">Select room</option>
                            <?php foreach ($classrooms as $r): ?>
                                <option value="<?php echo (int)$r['id']; ?>"><?php echo htmlspecialchars($r['room_number'] ?? ''); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label class="block text-sm font-semibold text-slate-700 mb-1">Day of week</label>
                        <select name="day_of_week" id="edit_day_of_week" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm" required>
                            <?php foreach (['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'] as $d): ?>
                                <option value="<?php echo htmlspecialchars($d); ?>"><?php echo htmlspecialchars($d); ?></option>
                            <?php endforeach; ?>
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
                        <input type="number" name="max_students" id="edit_max_students" min="1" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm" required>
                    </div>

                    <div>
                        <label class="block text-sm font-semibold text-slate-700 mb-1">Status</label>
                        <select name="status" id="edit_status" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm">
                            <?php foreach ($status_options as $s): ?>
                                <option value="<?php echo htmlspecialchars($s); ?>"><?php echo htmlspecialchars(ucfirst($s)); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label class="block text-sm font-semibold text-slate-700 mb-1">Semester</label>
                        <input type="text" name="semester" id="edit_semester" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm" required>
                    </div>

                    <div>
                        <label class="block text-sm font-semibold text-slate-700 mb-1">Academic year</label>
                        <input type="text" name="academic_year" id="edit_academic_year" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm" required>
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
                if (!y) return;
                opt.hidden = (year !== '' && y !== year);
            });
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
            if (confirm('Are you sure you want to delete this class? This action cannot be undone.')) {
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

        window.forceDeleteClass = function (scheduleId) {
            if (!confirm('Force delete will remove ALL enrollments for this class, then delete it. Continue?')) {
                return;
            }

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
                const hay = (row.getAttribute('data-search') || '').toLowerCase();
                row.style.display = (!q || hay.includes(q)) ? '' : 'none';
            });
        }

        searchInput?.addEventListener('input', applySearch);
        applySearch();
    })();
</script>

<?php require_once __DIR__ . '/includes/layout_bottom.php'; ?>
