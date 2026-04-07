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

if (isset($schedule_cols['end_time'])) {
    $end_expr = 's.end_time';
} elseif (isset($schedule_cols['duration_minutes'])) {
    $end_expr = 'ADDTIME(s.start_time, SEC_TO_TIME(s.duration_minutes * 60))';
} else {
    $end_expr = 'ADDTIME(s.start_time, SEC_TO_TIME(120 * 60))';
}

$start_date_expr = isset($schedule_cols['start_date'])
    ? "DATE_FORMAT(COALESCE(s.start_date, DATE(s.created_at)), '%Y-%m-%d')"
    : "DATE_FORMAT(DATE(s.created_at), '%Y-%m-%d')";
$end_date_expr = isset($schedule_cols['end_date'])
    ? "DATE_FORMAT(COALESCE(s.end_date, DATE_ADD(COALESCE(s.start_date, DATE(s.created_at)), INTERVAL 17 DAY)), '%Y-%m-%d')"
    : "DATE_FORMAT(DATE_ADD(DATE(s.created_at), INTERVAL 17 DAY), '%Y-%m-%d')";

// Detect if subjects table exists (avoid fatal errors on older DBs)
$subjects_table_exists = false;
try {
    $stmt = $conn->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'subjects'");
    $stmt->execute();
    $subjects_table_exists = ((int)$stmt->fetchColumn() > 0);
} catch (Throwable $e) {
    $subjects_table_exists = false;
}

// Get all schedules with their subject (preferred) / course (legacy) and instructor information
$subject_code_expr = $subjects_table_exists
    ? "COALESCE(NULLIF(TRIM(sub.subject_code), ''), NULLIF(TRIM(c.course_code), ''))"
    : "NULLIF(TRIM(c.course_code), '')";
$subject_name_expr = $subjects_table_exists
    ? "COALESCE(NULLIF(TRIM(sub.subject_name), ''), NULLIF(TRIM(c.course_name), ''))"
    : "NULLIF(TRIM(c.course_name), '')";
$subjects_join = $subjects_table_exists ? 'LEFT JOIN subjects sub ON (s.subject_id IS NOT NULL AND s.subject_id = sub.id)' : '';

$stmt = $conn->prepare("
    SELECT s.*,
           {$subject_code_expr} as subject_code,
           {$subject_name_expr} as subject_name,
           CONCAT(i.first_name, ' ', i.last_name) as instructor_name,
           r.room_number,
           COUNT(DISTINCT e.id) as enrollment_count,
            TIME_FORMAT({$end_expr}, '%H:%i:%s') as end_time,
            {$start_date_expr} as start_date,
            {$end_date_expr} as end_date
    FROM schedules s
    {$subjects_join}
    LEFT JOIN courses c ON (s.course_id IS NOT NULL AND s.course_id = c.id)
    JOIN users i ON s.instructor_id = i.id
    JOIN classrooms r ON s.classroom_id = r.id
    LEFT JOIN enrollments e ON s.id = e.schedule_id AND e.status = 'enrolled'
    GROUP BY s.id
    ORDER BY s.day_of_week, s.start_time
");
$stmt->execute();
$schedules = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Merge duplicate-looking rows caused by legacy blank term metadata.
$core_group_stats = [];
foreach ($schedules as $row) {
    $core_key = implode('|', [
        (string)($row['day_of_week'] ?? ''),
        (string)($row['course_id'] ?? ''),
        (string)($row['subject_id'] ?? ''),
        (string)($row['instructor_id'] ?? ''),
        (string)($row['classroom_id'] ?? ''),
        (string)($row['start_time'] ?? ''),
        (string)($row['end_time'] ?? ''),
        (string)($row['status'] ?? ''),
        (string)($row['max_students'] ?? ''),
    ]);

    if (!isset($core_group_stats[$core_key])) {
        $core_group_stats[$core_key] = [
            'semester' => [],
            'academic_year' => [],
            'year_level' => [],
        ];
    }

    $semester = trim((string)($row['semester'] ?? ''));
    $academic_year = trim((string)($row['academic_year'] ?? ''));
    $year_level = trim((string)($row['year_level'] ?? ''));
    if ($semester !== '') {
        $core_group_stats[$core_key]['semester'][$semester] = true;
    }
    if ($academic_year !== '') {
        $core_group_stats[$core_key]['academic_year'][$academic_year] = true;
    }
    if ($year_level !== '') {
        $core_group_stats[$core_key]['year_level'][$year_level] = true;
    }
}

$grouped_schedules = [];
foreach ($schedules as $row) {
    $core_key = implode('|', [
        (string)($row['day_of_week'] ?? ''),
        (string)($row['course_id'] ?? ''),
        (string)($row['subject_id'] ?? ''),
        (string)($row['instructor_id'] ?? ''),
        (string)($row['classroom_id'] ?? ''),
        (string)($row['start_time'] ?? ''),
        (string)($row['end_time'] ?? ''),
        (string)($row['status'] ?? ''),
        (string)($row['max_students'] ?? ''),
    ]);

    $stats = $core_group_stats[$core_key] ?? ['semester' => [], 'academic_year' => [], 'year_level' => []];
    $semester = trim((string)($row['semester'] ?? ''));
    $academic_year = trim((string)($row['academic_year'] ?? ''));
    $year_level = trim((string)($row['year_level'] ?? ''));

    if ($semester === '' && count($stats['semester']) === 1) {
        $semester = (string)array_key_first($stats['semester']);
        $row['semester'] = $semester;
    }
    if ($academic_year === '' && count($stats['academic_year']) === 1) {
        $academic_year = (string)array_key_first($stats['academic_year']);
        $row['academic_year'] = $academic_year;
    }
    if ($year_level === '' && count($stats['year_level']) === 1) {
        $year_level = (string)array_key_first($stats['year_level']);
        $row['year_level'] = $year_level;
    }

    $group_key = $core_key . '|' . $semester . '|' . $academic_year . '|' . $year_level;
    if (!isset($grouped_schedules[$group_key])) {
        $grouped_schedules[$group_key] = $row;
        continue;
    }

    $grouped_schedules[$group_key]['enrollment_count'] = max(
        (int)($grouped_schedules[$group_key]['enrollment_count'] ?? 0),
        (int)($row['enrollment_count'] ?? 0)
    );
}

$schedules = array_values($grouped_schedules);

// Final guard: collapse exact duplicate cards that may still exist in legacy data.
$card_dedup_map = [];
foreach ($schedules as $row) {
    $card_key = implode('|', [
        (string)($row['day_of_week'] ?? ''),
        (string)($row['course_id'] ?? ''),
        (string)($row['subject_id'] ?? ''),
        (string)($row['instructor_id'] ?? ''),
        (string)($row['classroom_id'] ?? ''),
        (string)($row['start_time'] ?? ''),
        (string)($row['end_time'] ?? ''),
        (string)($row['status'] ?? ''),
        (string)($row['semester'] ?? ''),
        (string)($row['academic_year'] ?? ''),
        (string)($row['year_level'] ?? ''),
    ]);

    if (!isset($card_dedup_map[$card_key])) {
        $card_dedup_map[$card_key] = $row;
        continue;
    }

    $existing = $card_dedup_map[$card_key];
    $existing_enrolled = (int)($existing['enrollment_count'] ?? 0);
    $incoming_enrolled = (int)($row['enrollment_count'] ?? 0);

    $existing_has_dates = (trim((string)($existing['start_date'] ?? '')) !== '' && trim((string)($existing['end_date'] ?? '')) !== '');
    $incoming_has_dates = (trim((string)($row['start_date'] ?? '')) !== '' && trim((string)($row['end_date'] ?? '')) !== '');

    if ($incoming_enrolled > $existing_enrolled || (!$existing_has_dates && $incoming_has_dates)) {
        $card_dedup_map[$card_key] = $row;
        $existing = $row;
    }

    if (trim((string)($card_dedup_map[$card_key]['start_date'] ?? '')) === '' && trim((string)($row['start_date'] ?? '')) !== '') {
        $card_dedup_map[$card_key]['start_date'] = $row['start_date'];
    }
    if (trim((string)($card_dedup_map[$card_key]['end_date'] ?? '')) === '' && trim((string)($row['end_date'] ?? '')) !== '') {
        $card_dedup_map[$card_key]['end_date'] = $row['end_date'];
    }
    $card_dedup_map[$card_key]['enrollment_count'] = max(
        (int)($card_dedup_map[$card_key]['enrollment_count'] ?? 0),
        (int)($row['enrollment_count'] ?? 0)
    );
}
$schedules = array_values($card_dedup_map);

$active_count = 0;
foreach ($schedules as $row) {
    $status = strtolower((string)($row['status'] ?? 'active'));
    if ($status === 'active') {
        $active_count++;
    }
}

$days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
$by_day = [];
foreach ($days as $d) {
    $by_day[$d] = [];
}
foreach ($schedules as $s) {
    $d = (string)($s['day_of_week'] ?? '');
    if (!isset($by_day[$d])) {
        $by_day[$d] = [];
    }
    $by_day[$d][] = $s;
}

$has_schedule_date_columns = isset($schedule_cols['start_date']) && isset($schedule_cols['end_date']);
$default_start_date = date('Y-m-d');
$default_end_date = date('Y-m-d', strtotime('+17 days'));

function fmt_time_range(string $start, string $end): string {
    if ($start === '' || $end === '') {
        return '';
    }
    return date('g:i A', strtotime($start)) . ' – ' . date('g:i A', strtotime($end));
}

function fmt_class_duration(string $start_date, string $end_date, string $start_time, string $end_time): string {
    $sd_ts = $start_date !== '' ? strtotime($start_date) : false;
    $ed_ts = $end_date !== '' ? strtotime($end_date) : false;

    if ($sd_ts !== false && $ed_ts !== false) {
        return date('F d, Y', $sd_ts) . ' - ' . date('F d, Y', $ed_ts);
    }

    return 'N/A';
}

// Get all subjects for the dropdown (preferred)
$subjects = [];
try {
    $stmt = $conn->prepare('SELECT id, subject_code, subject_name FROM subjects ORDER BY subject_code');
    $stmt->execute();
    $subjects = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $subjects = [];
}

// Get all courses for the dropdown (required by schedules.course_id FK)
$courses = [];
try {
    $stmt = $conn->prepare('SELECT id, course_code, course_name FROM courses ORDER BY course_code, course_name');
    $stmt->execute();
    $courses = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $courses = [];
}

// Get all instructors for the dropdown
$stmt = $conn->prepare("
    SELECT id, first_name, last_name
    FROM users
    WHERE role = 'instructor'
    ORDER BY last_name, first_name
");
$stmt->execute();
$instructors = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get all classrooms for the dropdown
$stmt = $conn->prepare('SELECT id, room_number FROM classrooms ORDER BY room_number');
$stmt->execute();
$classrooms = $stmt->fetchAll(PDO::FETCH_ASSOC);

$page_title = 'Schedules';
$breadcrumbs = 'Registrar / Schedules';
$active_page = 'schedules';
require_once __DIR__ . '/includes/layout_top.php';
?>

<div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-4">
    <?php if (!$subjects_table_exists): ?>
        <div class="w-full rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-amber-900">
            <div class="text-sm font-semibold">Subjects are not set up yet.</div>
            <div class="text-sm text-amber-800">Run <span class="font-mono">/PCTClassSchedulingSystem/fix_subjects.php</span> once to create and seed the subjects list.</div>
        </div>
    <?php endif; ?>
    <div>
        <h1 class="text-2xl font-semibold text-slate-900">Schedules</h1>
        <p class="text-sm text-slate-500"><?php echo (int)$active_count; ?> active class schedules</p>
    </div>

    <div class="flex items-center gap-3">
        <div class="inline-flex rounded-xl border border-slate-200 bg-white p-1">
            <button id="weekViewBtn" type="button" class="inline-flex items-center gap-2 rounded-lg px-3 py-2 text-sm font-semibold bg-emerald-600 text-white">
                <i class="bi bi-calendar-week"></i>
                Week View
            </button>
            <button id="tableViewBtn" type="button" class="inline-flex items-center gap-2 rounded-lg px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                <i class="bi bi-table"></i>
                Table View
            </button>
        </div>
        <button id="addScheduleBtn" type="button" class="inline-flex items-center gap-2 rounded-xl bg-emerald-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-emerald-700">
            <i class="bi bi-plus-lg"></i>
            Add Schedule
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

<!-- Week View -->
<section id="weekView" class="mt-6 rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden">
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6">
        <?php foreach ($days as $idx => $day): ?>
            <?php
                $header_classes = ['bg-blue-600','bg-violet-600','bg-emerald-600','bg-orange-500','bg-rose-600','bg-slate-700'];
                $h = $header_classes[$idx % count($header_classes)];
                $count = isset($by_day[$day]) ? count($by_day[$day]) : 0;
            ?>
            <div class="border-t sm:border-t-0 sm:border-l border-slate-200">
                <div class="px-4 py-3 text-white <?php echo $h; ?>">
                    <div class="text-sm font-semibold text-center"><?php echo htmlspecialchars($day); ?></div>
                    <div class="text-xs opacity-90 text-center"><?php echo (int)$count; ?> classes</div>
                </div>
                <div class="p-3 space-y-3 bg-slate-50/60 min-h-[180px]">
                    <?php if (!empty($by_day[$day])): ?>
                        <?php foreach ($by_day[$day] as $s): ?>
                            <?php
                                $card_name = trim((string)($s['subject_name'] ?? ''));
                                if ($card_name === '') {
                                    $card_name = trim((string)($s['course_name'] ?? ''));
                                }
                                if ($card_name === '') {
                                    $card_name = 'Class Schedule';
                                }

                                $card_code = trim((string)($s['subject_code'] ?? ''));
                                if ($card_code === '') {
                                    $card_code = trim((string)($s['course_code'] ?? ''));
                                }
                                if ($card_code === '') {
                                    $card_code = 'N/A';
                                }
                                $card_time = fmt_time_range((string) ($s['start_time'] ?? ''), (string) ($s['end_time'] ?? ''));
                                $card_duration = fmt_class_duration((string) ($s['start_date'] ?? ''), (string) ($s['end_date'] ?? ''), (string) ($s['start_time'] ?? ''), (string) ($s['end_time'] ?? ''));
                                $card_room = (string) ($s['room_number'] ?? '');
                                $card_instructor = (string) ($s['instructor_name'] ?? 'Unassigned');
                                $card_enrollment = (int) ($s['enrollment_count'] ?? 0);
                                $card_status = ucfirst(strtolower((string) ($s['status'] ?? 'active')));
                            ?>
                            <button
                                type="button"
                                class="week-schedule-card group w-full text-left rounded-2xl bg-white border border-slate-200 shadow-sm p-3 transition duration-150 hover:-translate-y-0.5 hover:shadow-md hover:border-emerald-300 focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-300"
                                data-class-name="<?php echo htmlspecialchars($card_name); ?>"
                                data-class-code="<?php echo htmlspecialchars($card_code); ?>"
                                data-class-day="<?php echo htmlspecialchars((string) ($s['day_of_week'] ?? $day)); ?>"
                                data-class-time="<?php echo htmlspecialchars($card_time); ?>"
                                data-class-duration="<?php echo htmlspecialchars($card_duration); ?>"
                                data-class-room="<?php echo htmlspecialchars($card_room); ?>"
                                data-class-instructor="<?php echo htmlspecialchars($card_instructor); ?>"
                                data-class-enrollment="<?php echo (int) $card_enrollment; ?>"
                                data-class-status="<?php echo htmlspecialchars($card_status); ?>"
                            >
                                <div class="text-sm font-semibold text-slate-900"><?php echo htmlspecialchars($card_name); ?></div>
                                <div class="mt-2 flex items-center gap-2 text-xs text-slate-500">
                                    <i class="bi bi-clock"></i>
                                    <span><?php echo htmlspecialchars($card_time); ?></span>
                                </div>
                                <div class="mt-1 flex items-center gap-2 text-xs text-slate-500">
                                    <i class="bi bi-door-open"></i>
                                    <span><?php echo htmlspecialchars($card_room); ?></span>
                                </div>
                                <div class="mt-1 flex items-center gap-2 text-xs font-semibold text-emerald-700">
                                    <i class="bi bi-hourglass-split"></i>
                                    <span><?php echo htmlspecialchars($card_duration); ?></span>
                                </div>
                            </button>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</section>

<!-- Class Detail Modal -->
<div id="classDetailModal" class="fixed inset-0 z-50 hidden" aria-hidden="true">
    <div class="absolute inset-0 bg-slate-900/50" data-modal-close="classDetailModal"></div>
    <div class="relative mx-auto my-10 w-[92%] max-w-xl">
        <div class="rounded-2xl bg-white shadow-xl border border-slate-200 overflow-hidden">
            <div class="px-5 py-4 border-b border-slate-200 flex items-center justify-between">
                <div>
                    <div class="text-xs font-semibold tracking-[0.18em] text-emerald-600">CLASS SCHEDULE</div>
                    <h3 id="classDetailName" class="mt-1 text-xl font-semibold text-slate-900">Class</h3>
                    <p id="classDetailCode" class="text-sm text-slate-500"></p>
                </div>
                <button type="button" class="h-9 w-9 inline-flex items-center justify-center rounded-xl hover:bg-slate-100" data-modal-close="classDetailModal" aria-label="Close"><i class="bi bi-x-lg"></i></button>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 p-5">
                <div class="rounded-xl border border-slate-200 bg-slate-50 px-4 py-3">
                    <div class="text-xs font-semibold text-slate-500">DAY</div>
                    <div id="classDetailDay" class="mt-1 text-sm font-semibold text-slate-800">-</div>
                </div>
                <div class="rounded-xl border border-slate-200 bg-slate-50 px-4 py-3">
                    <div class="text-xs font-semibold text-slate-500">TIME</div>
                    <div id="classDetailTime" class="mt-1 text-sm font-semibold text-slate-800">-</div>
                    <div id="classDetailDuration" class="mt-1 text-xs font-semibold text-emerald-700">-</div>
                </div>
                <div class="rounded-xl border border-slate-200 bg-slate-50 px-4 py-3">
                    <div class="text-xs font-semibold text-slate-500">ROOM</div>
                    <div id="classDetailRoom" class="mt-1 text-sm font-semibold text-slate-800">-</div>
                </div>
                <div class="rounded-xl border border-slate-200 bg-slate-50 px-4 py-3">
                    <div class="text-xs font-semibold text-slate-500">INSTRUCTOR</div>
                    <div id="classDetailInstructor" class="mt-1 text-sm font-semibold text-slate-800">-</div>
                </div>
                <div class="rounded-xl border border-slate-200 bg-slate-50 px-4 py-3">
                    <div class="text-xs font-semibold text-slate-500">ENROLLED</div>
                    <div id="classDetailEnrollment" class="mt-1 text-sm font-semibold text-slate-800">-</div>
                </div>
                <div class="rounded-xl border border-slate-200 bg-slate-50 px-4 py-3">
                    <div class="text-xs font-semibold text-slate-500">STATUS</div>
                    <div id="classDetailStatus" class="mt-1 text-sm font-semibold text-slate-800">-</div>
                </div>
            </div>
            <div class="px-5 pb-5 flex justify-end">
                <button type="button" class="inline-flex items-center rounded-xl bg-emerald-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-emerald-700" data-modal-close="classDetailModal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Table View -->
<section id="tableView" class="mt-6 hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
    <div class="p-4 sm:p-5 border-b border-slate-200">
        <div class="relative max-w-md">
            <i class="bi bi-search absolute left-3 top-1/2 -translate-y-1/2 text-slate-400"></i>
            <input id="scheduleSearch" type="text" placeholder="Search schedules…" class="w-full rounded-xl border border-slate-200 bg-slate-50 pl-10 pr-3 py-2.5 text-sm outline-none focus:bg-white focus:ring-2 focus:ring-emerald-200" />
        </div>
    </div>

    <div class="overflow-x-auto">
        <table class="min-w-full text-sm">
            <thead class="text-xs uppercase tracking-wider text-slate-500 bg-slate-50">
                <tr>
                    <th class="px-5 py-3 text-left font-semibold">Class</th>
                    <th class="px-5 py-3 text-left font-semibold">Instructor</th>
                    <th class="px-5 py-3 text-left font-semibold">Room</th>
                    <th class="px-5 py-3 text-left font-semibold">Day</th>
                    <th class="px-5 py-3 text-left font-semibold">Time</th>
                    <th class="px-5 py-3 text-left font-semibold">Class Duration</th>
                    <th class="px-5 py-3 text-left font-semibold">Subject</th>
                    <th class="px-5 py-3 text-left font-semibold">Status</th>
                    <th class="px-5 py-3 text-right font-semibold">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($schedules as $s): ?>
                    <?php
                        $status = strtolower((string)($s['status'] ?? 'active'));
                        $display_name = trim((string)($s['subject_name'] ?? ''));
                        if ($display_name === '') {
                            $display_name = trim((string)($s['course_name'] ?? ''));
                        }
                        if ($display_name === '') {
                            $display_name = 'Untitled Class';
                        }

                        $display_code = trim((string)($s['subject_code'] ?? ''));
                        if ($display_code === '') {
                            $display_code = trim((string)($s['course_code'] ?? ''));
                        }
                        if ($display_code === '') {
                            $display_code = 'N/A';
                        }

                        $search_blob = strtolower($display_code . ' ' . $display_name . ' ' . ($s['instructor_name'] ?? '') . ' ' . ($s['room_number'] ?? '') . ' ' . ($s['day_of_week'] ?? ''));
                    ?>
                                <tr class="schedule-row border-t border-slate-100 hover:bg-slate-50/60" data-search="<?php echo htmlspecialchars($search_blob); ?>">
                        <td class="px-5 py-4">
                                        <div class="font-semibold text-slate-900"><?php echo htmlspecialchars($display_name); ?></div>
                                        <div class="text-xs text-slate-500"><?php echo htmlspecialchars($display_code); ?></div>
                        </td>
                        <td class="px-5 py-4 text-slate-700"><?php echo htmlspecialchars($s['instructor_name'] ?? ''); ?></td>
                        <td class="px-5 py-4 text-slate-700"><?php echo htmlspecialchars($s['room_number'] ?? ''); ?></td>
                        <td class="px-5 py-4 text-slate-700"><?php echo htmlspecialchars($s['day_of_week'] ?? ''); ?></td>
                        <td class="px-5 py-4 text-slate-700"><?php echo htmlspecialchars(fmt_time_range((string)($s['start_time'] ?? ''), (string)($s['end_time'] ?? ''))); ?></td>
                        <td class="px-5 py-4 text-sm font-semibold text-emerald-700"><?php echo htmlspecialchars(fmt_class_duration((string)($s['start_date'] ?? ''), (string)($s['end_date'] ?? ''), (string)($s['start_time'] ?? ''), (string)($s['end_time'] ?? ''))); ?></td>
                        <td class="px-5 py-4">
                                        <span class="inline-flex items-center rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-700"><?php echo htmlspecialchars($display_code); ?></span>
                        </td>
                        <td class="px-5 py-4">
                            <?php if ($status === 'active'): ?>
                                <span class="inline-flex items-center gap-2 rounded-full bg-emerald-50 text-emerald-700 ring-1 ring-inset ring-emerald-200 px-3 py-1 text-xs font-semibold">
                                    <span class="h-1.5 w-1.5 rounded-full bg-emerald-500"></span>
                                    Active
                                </span>
                            <?php else: ?>
                                <span class="inline-flex items-center gap-2 rounded-full bg-slate-100 text-slate-700 ring-1 ring-inset ring-slate-200 px-3 py-1 text-xs font-semibold">
                                    <span class="h-1.5 w-1.5 rounded-full bg-slate-500"></span>
                                    <?php echo htmlspecialchars(ucfirst($status)); ?>
                                </span>
                            <?php endif; ?>
                        </td>
                        <td class="px-5 py-4">
                            <div class="flex items-center justify-end gap-2 text-slate-500">
                                <button type="button" class="h-9 w-9 inline-flex items-center justify-center rounded-xl hover:bg-slate-100" onclick='editSchedule(<?php echo json_encode($s, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>)' title="Edit">
                                    <i class="bi bi-pencil"></i>
                                </button>
                                <button type="button" class="h-9 w-9 inline-flex items-center justify-center rounded-xl hover:bg-slate-100" onclick="deleteSchedule(<?php echo (int)$s['id']; ?>)" title="Delete">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>

                <?php if (empty($schedules)): ?>
                    <tr>
                        <td colspan="9" class="px-5 py-10 text-center text-slate-500">No schedules found</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<!-- Add Schedule Modal -->
<div id="addScheduleModal" class="fixed inset-0 z-50 hidden" aria-hidden="true">
    <div class="absolute inset-0 bg-slate-900/50" data-modal-close="addScheduleModal"></div>
    <div class="relative mx-auto my-10 w-[92%] max-w-2xl">
        <div class="rounded-2xl bg-white shadow-xl border border-slate-200 overflow-hidden">
            <div class="px-5 py-4 border-b border-slate-200 flex items-center justify-between">
                <div class="text-base font-semibold text-slate-900">Add Schedule</div>
                <button type="button" class="h-9 w-9 inline-flex items-center justify-center rounded-xl hover:bg-slate-100" data-modal-close="addScheduleModal" aria-label="Close"><i class="bi bi-x-lg"></i></button>
            </div>

            <form action="process_schedule.php" method="POST" class="p-5">
                <input type="hidden" name="action" value="add">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-semibold text-slate-700 mb-1">Course</label>
                        <select name="course_id" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm" required>
                            <option value="" selected disabled>Select course</option>
                            <?php foreach ($courses as $course): ?>
                                <option value="<?php echo (int)$course['id']; ?>"><?php echo htmlspecialchars(($course['course_code'] ?? '') . ' — ' . ($course['course_name'] ?? '')); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label class="block text-sm font-semibold text-slate-700 mb-1">Subject</label>
                        <select name="subject_id" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm" required>
                            <option value="" selected disabled>Select subject</option>
                            <?php foreach ($subjects as $sub): ?>
                                <option value="<?php echo (int)$sub['id']; ?>"><?php echo htmlspecialchars(($sub['subject_code'] ?? '') . ' — ' . ($sub['subject_name'] ?? '')); ?></option>
                            <?php endforeach; ?>
                        </select>
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
                                <option value="<?php echo (int)$r['id']; ?>"><?php echo htmlspecialchars($r['room_number'] ?? ''); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label class="block text-sm font-semibold text-slate-700 mb-2">Days</label>
                        <div class="grid grid-cols-2 gap-2 rounded-xl border border-slate-200 bg-slate-50 p-3">
                            <?php foreach ($days as $d): ?>
                                <label class="inline-flex items-center gap-2 rounded-lg bg-white border border-slate-200 px-2.5 py-2 text-sm text-slate-700 cursor-pointer hover:border-emerald-300">
                                    <input type="checkbox" name="day_of_week[]" value="<?php echo htmlspecialchars($d); ?>" class="h-4 w-4 rounded border-slate-300 text-emerald-600 focus:ring-emerald-500">
                                    <span><?php echo htmlspecialchars($d); ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                        <p class="mt-1 text-xs text-slate-500">Select one or more days for this subject schedule.</p>
                    </div>

                    <div class="sm:col-start-2 space-y-4">
                        <div>
                            <label class="block text-sm font-semibold text-slate-700 mb-1">Start time</label>
                            <input type="time" name="start_time" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm" required>
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-slate-700 mb-1">End time</label>
                            <input type="time" name="end_time" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm" <?php echo isset($schedule_cols['end_time']) ? 'required' : ''; ?>>
                            <?php if (!isset($schedule_cols['end_time'])): ?>
                                <p class="mt-1 text-xs text-slate-500">Your database doesn’t store an explicit end time; it will be computed automatically.</p>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div>
                        <label class="block text-sm font-semibold text-slate-700 mb-1">Start date</label>
                        <input type="date" name="start_date" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm" value="<?php echo htmlspecialchars($default_start_date); ?>" <?php echo $has_schedule_date_columns ? 'required' : ''; ?>>
                    </div>

                    <div>
                        <label class="block text-sm font-semibold text-slate-700 mb-1">End date</label>
                        <input type="date" name="end_date" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm" value="<?php echo htmlspecialchars($default_end_date); ?>" <?php echo $has_schedule_date_columns ? 'required' : ''; ?>>
                        <?php if (!$has_schedule_date_columns): ?>
                            <p class="mt-1 text-xs text-slate-500">Your database schema does not include schedule date range columns yet.</p>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="mt-5 flex items-center justify-end gap-3">
                    <button type="button" class="inline-flex items-center rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-50" data-modal-close="addScheduleModal">Cancel</button>
                    <button type="submit" class="inline-flex items-center rounded-xl bg-emerald-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-emerald-700">Add Schedule</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Schedule Modal -->
<div id="editScheduleModal" class="fixed inset-0 z-50 hidden" aria-hidden="true">
    <div class="absolute inset-0 bg-slate-900/50" data-modal-close="editScheduleModal"></div>
    <div class="relative mx-auto my-10 w-[92%] max-w-2xl">
        <div class="rounded-2xl bg-white shadow-xl border border-slate-200 overflow-hidden">
            <div class="px-5 py-4 border-b border-slate-200 flex items-center justify-between">
                <div class="text-base font-semibold text-slate-900">Edit Schedule</div>
                <button type="button" class="h-9 w-9 inline-flex items-center justify-center rounded-xl hover:bg-slate-100" data-modal-close="editScheduleModal" aria-label="Close"><i class="bi bi-x-lg"></i></button>
            </div>

            <form id="editScheduleForm" action="process_schedule.php" method="POST" class="p-5">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="schedule_id" id="edit_schedule_id">

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-semibold text-slate-700 mb-1">Course</label>
                        <select name="course_id" id="edit_course_id" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm" required>
                            <option value="" disabled>Select course</option>
                            <?php foreach ($courses as $course): ?>
                                <option value="<?php echo (int)$course['id']; ?>"><?php echo htmlspecialchars(($course['course_code'] ?? '') . ' — ' . ($course['course_name'] ?? '')); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label class="block text-sm font-semibold text-slate-700 mb-1">Subject</label>
                        <select name="subject_id" id="edit_subject_id" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm" required>
                            <option value="" disabled>Select subject</option>
                            <?php foreach ($subjects as $sub): ?>
                                <option value="<?php echo (int)$sub['id']; ?>"><?php echo htmlspecialchars(($sub['subject_code'] ?? '') . ' — ' . ($sub['subject_name'] ?? '')); ?></option>
                            <?php endforeach; ?>
                        </select>
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
                                <option value="<?php echo (int)$r['id']; ?>"><?php echo htmlspecialchars($r['room_number'] ?? ''); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label class="block text-sm font-semibold text-slate-700 mb-2">Days</label>
                        <div class="grid grid-cols-2 gap-2 rounded-xl border border-slate-200 bg-slate-50 p-3">
                            <?php foreach ($days as $d): ?>
                                <label class="inline-flex items-center gap-2 rounded-lg bg-white border border-slate-200 px-2.5 py-2 text-sm text-slate-700 cursor-pointer hover:border-emerald-300">
                                    <input type="checkbox" name="day_of_week[]" value="<?php echo htmlspecialchars($d); ?>" data-day="<?php echo htmlspecialchars($d); ?>" class="edit-day-checkbox h-4 w-4 rounded border-slate-300 text-emerald-600 focus:ring-emerald-500">
                                    <span><?php echo htmlspecialchars($d); ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                        <p class="mt-1 text-xs text-slate-500">Select one or more days for this subject schedule.</p>
                    </div>

                    <div class="sm:col-start-2 space-y-4">
                        <div>
                            <label class="block text-sm font-semibold text-slate-700 mb-1">Start time</label>
                            <input type="time" name="start_time" id="edit_start_time" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm" required>
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-slate-700 mb-1">End time</label>
                            <input type="time" name="end_time" id="edit_end_time" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm" <?php echo isset($schedule_cols['end_time']) ? 'required' : ''; ?>>
                            <?php if (!isset($schedule_cols['end_time'])): ?>
                                <p class="mt-1 text-xs text-slate-500">Your database doesn’t store an explicit end time; it will be computed automatically.</p>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div>
                        <label class="block text-sm font-semibold text-slate-700 mb-1">Start date</label>
                        <input type="date" name="start_date" id="edit_start_date" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm" <?php echo $has_schedule_date_columns ? 'required' : ''; ?>>
                    </div>

                    <div>
                        <label class="block text-sm font-semibold text-slate-700 mb-1">End date</label>
                        <input type="date" name="end_date" id="edit_end_date" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm" <?php echo $has_schedule_date_columns ? 'required' : ''; ?>>
                        <?php if (!$has_schedule_date_columns): ?>
                            <p class="mt-1 text-xs text-slate-500">Your database schema does not include schedule date range columns yet.</p>
                        <?php endif; ?>
                    </div>

                    <div>
                        <label class="block text-sm font-semibold text-slate-700 mb-1">Status</label>
                        <select name="status" id="edit_status" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm" required>
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                            <option value="cancelled">Cancelled</option>
                            <option value="completed">Completed</option>
                        </select>
                    </div>
                </div>

                <div class="mt-5 flex items-center justify-end gap-3">
                    <button type="button" class="inline-flex items-center rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-50" data-modal-close="editScheduleModal">Cancel</button>
                    <button type="submit" class="inline-flex items-center rounded-xl bg-emerald-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-emerald-700">Update Schedule</button>
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

    document.getElementById('addScheduleBtn')?.addEventListener('click', function () {
        openModal('addScheduleModal');
    });
    document.querySelectorAll('[data-modal-close]')?.forEach(function (b) {
        b.addEventListener('click', function () {
            const target = b.getAttribute('data-modal-close');
            if (target) closeModal(target);
        });
    });

    const editScheduleForm = document.getElementById('editScheduleForm');
    const editDayCheckboxes = Array.from(document.querySelectorAll('.edit-day-checkbox'));

    const weekView = document.getElementById('weekView');
    const tableView = document.getElementById('tableView');
    const weekBtn = document.getElementById('weekViewBtn');
    const tableBtn = document.getElementById('tableViewBtn');

    function setWeek() {
        weekView?.classList.remove('hidden');
        tableView?.classList.add('hidden');
        weekBtn?.classList.add('bg-emerald-600', 'text-white');
        weekBtn?.classList.remove('text-slate-700');
        tableBtn?.classList.remove('bg-emerald-600', 'text-white');
        tableBtn?.classList.add('text-slate-700');
    }
    function setTable() {
        weekView?.classList.add('hidden');
        tableView?.classList.remove('hidden');
        tableBtn?.classList.add('bg-emerald-600', 'text-white');
        tableBtn?.classList.remove('text-slate-700');
        weekBtn?.classList.remove('bg-emerald-600', 'text-white');
        weekBtn?.classList.add('text-slate-700');
    }
    weekBtn?.addEventListener('click', setWeek);
    tableBtn?.addEventListener('click', setTable);

    const weekCards = Array.from(document.querySelectorAll('.week-schedule-card'));
    const detailFields = {
        name: document.getElementById('classDetailName'),
        code: document.getElementById('classDetailCode'),
        day: document.getElementById('classDetailDay'),
        time: document.getElementById('classDetailTime'),
        duration: document.getElementById('classDetailDuration'),
        room: document.getElementById('classDetailRoom'),
        instructor: document.getElementById('classDetailInstructor'),
        enrollment: document.getElementById('classDetailEnrollment'),
        status: document.getElementById('classDetailStatus')
    };

    function getCardValue(card, key, fallback) {
        return (card?.dataset?.[key] || fallback || '').toString();
    }

    function openClassDetail(card) {
        if (!card) {
            return;
        }

        const classCode = getCardValue(card, 'classCode', '');
        if (detailFields.name) detailFields.name.textContent = getCardValue(card, 'className', 'Class Schedule');
        if (detailFields.code) detailFields.code.textContent = classCode;
        if (detailFields.day) detailFields.day.textContent = getCardValue(card, 'classDay', '-');
        if (detailFields.time) detailFields.time.textContent = getCardValue(card, 'classTime', '-');
        if (detailFields.duration) detailFields.duration.textContent = getCardValue(card, 'classDuration', 'N/A');
        if (detailFields.room) detailFields.room.textContent = getCardValue(card, 'classRoom', '-');
        if (detailFields.instructor) detailFields.instructor.textContent = getCardValue(card, 'classInstructor', '-');
        if (detailFields.enrollment) detailFields.enrollment.textContent = getCardValue(card, 'classEnrollment', '0') + ' student(s)';
        if (detailFields.status) detailFields.status.textContent = getCardValue(card, 'classStatus', '-');

        openModal('classDetailModal');
    }

    weekCards.forEach(function (card) {
        card.addEventListener('click', function () {
            openClassDetail(card);
        });

        card.addEventListener('keydown', function (event) {
            if (event.key === 'Enter' || event.key === ' ') {
                event.preventDefault();
                openClassDetail(card);
            }
        });
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            closeModal('classDetailModal');
        }
    });

    const searchInput = document.getElementById('scheduleSearch');
    const rows = Array.from(document.querySelectorAll('.schedule-row'));
    function applySearch() {
        const q = (searchInput?.value || '').trim().toLowerCase();
        rows.forEach(function (row) {
            const hay = (row.getAttribute('data-search') || '').toLowerCase();
            row.style.display = (!q || hay.includes(q)) ? '' : 'none';
        });
    }
    searchInput?.addEventListener('input', applySearch);

    editScheduleForm?.addEventListener('submit', function (event) {
        const selectedCount = editDayCheckboxes.filter(function (cb) { return cb.checked; }).length;
        if (selectedCount === 0) {
            event.preventDefault();
            alert('Please select at least one day.');
        }
    });

    window.editSchedule = function (scheduleData) {
        document.getElementById('edit_schedule_id').value = scheduleData.id;
        const editCourse = document.getElementById('edit_course_id');
        if (editCourse) {
            editCourse.value = scheduleData.course_id || '';
        }
        document.getElementById('edit_subject_id').value = scheduleData.subject_id || '';
        document.getElementById('edit_instructor_id').value = scheduleData.instructor_id || '';
        document.getElementById('edit_classroom_id').value = scheduleData.classroom_id || '';
        editDayCheckboxes.forEach(function (cb) { cb.checked = false; });
        const rawDays = String(scheduleData.combined_days_label || scheduleData.day_of_week || '');
        const selectedDays = rawDays.split(',').map(function (d) { return d.trim(); }).filter(Boolean);
        editDayCheckboxes.forEach(function (cb) {
            if (selectedDays.includes(cb.value)) {
                cb.checked = true;
            }
        });
        document.getElementById('edit_start_time').value = scheduleData.start_time || '';
        document.getElementById('edit_end_time').value = scheduleData.end_time || '';
        const editStartDate = document.getElementById('edit_start_date');
        const editEndDate = document.getElementById('edit_end_date');
        if (editStartDate) {
            editStartDate.value = scheduleData.start_date || '<?php echo htmlspecialchars($default_start_date); ?>';
        }
        if (editEndDate) {
            editEndDate.value = scheduleData.end_date || '<?php echo htmlspecialchars($default_end_date); ?>';
        }
        document.getElementById('edit_status').value = scheduleData.status || 'active';
        openModal('editScheduleModal');
    }

    window.deleteSchedule = function (scheduleId) {
        if (confirm('Are you sure you want to delete this schedule? This action cannot be undone.')) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = 'process_schedule.php';

            const actionInput = document.createElement('input');
            actionInput.type = 'hidden';
            actionInput.name = 'action';
            actionInput.value = 'delete';

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
})();
</script>

<?php require_once __DIR__ . '/includes/layout_bottom.php'; ?>
