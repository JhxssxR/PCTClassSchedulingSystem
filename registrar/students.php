<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is a registrar/admin
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'registrar'], true)) {
    header('Location: ../auth/login.php');
    exit();
}

// Handle add-student (registrar/admin only)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_student') {
    try {
        $password_raw = (string)($_POST['password'] ?? '');
        if ($password_raw === '') {
            throw new Exception('Password is required.');
        }

        $password = password_hash($password_raw, PASSWORD_DEFAULT);
        $now = date('Y-m-d H:i:s');

        // Detect optional columns for compatibility
        $user_cols = [];
        try {
            $cols_stmt = $conn->prepare('DESCRIBE users');
            $cols_stmt->execute();
            foreach ($cols_stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                if (!empty($r['Field'])) {
                    $user_cols[$r['Field']] = true;
                }
            }
        } catch (Throwable $e) {
            $user_cols = [];
        }

        $insert_cols = ['username', 'password', 'email', 'role', 'first_name', 'last_name'];
        $insert_vals = [':username', ':password', ':email', ':role', ':first_name', ':last_name'];
        $params = [
            'username' => trim((string)($_POST['username'] ?? '')),
            'password' => $password,
            'email' => trim((string)($_POST['email'] ?? '')),
            'role' => 'student',
            'first_name' => trim((string)($_POST['first_name'] ?? '')),
            'last_name' => trim((string)($_POST['last_name'] ?? '')),
        ];

        if (isset($user_cols['status'])) {
            $insert_cols[] = 'status';
            $insert_vals[] = ':status';
            $params['status'] = 'active';
        }

        if (isset($user_cols['created_at'])) {
            $insert_cols[] = 'created_at';
            $insert_vals[] = ':created_at';
            $params['created_at'] = $now;
        }

        if (isset($user_cols['updated_at'])) {
            $insert_cols[] = 'updated_at';
            $insert_vals[] = ':updated_at';
            $params['updated_at'] = $now;
        }

        $stmt = $conn->prepare(
            'INSERT INTO users (' . implode(', ', $insert_cols) . ') VALUES (' . implode(', ', $insert_vals) . ')'
        );
        $stmt->execute($params);

        $_SESSION['success'] = 'Student added successfully.';
    } catch (PDOException $e) {
        $friendly = null;
        $sqlState = $e->getCode();
        $driverCode = null;
        $driverMsg = null;
        if (is_array($e->errorInfo ?? null)) {
            $driverCode = $e->errorInfo[1] ?? null;
            $driverMsg = $e->errorInfo[2] ?? null;
        }

        if ($sqlState === '23000' || $driverCode === 1062) {
            $friendly = 'Username or email already exists.';
        } elseif ($driverCode === 1364 && is_string($driverMsg) && preg_match("/Field '([^']+)' doesn't have a default value/i", $driverMsg, $m)) {
            $friendly = 'Database schema requires a value for: ' . $m[1] . '.';
        }

        error_log('Registrar add student error: ' . $e->getMessage());
        $_SESSION['error'] = $friendly ?: ('Database error: ' . $e->getMessage());
    } catch (Exception $e) {
        $_SESSION['error'] = $e->getMessage();
    }

    header('Location: students.php');
    exit();
}

// Get all students with enrollment counts + enrolled course codes
$stmt = $conn->prepare("
    SELECT u.*,
           COUNT(DISTINCT e.id) as enrollment_count,
           GROUP_CONCAT(DISTINCT c.course_code ORDER BY c.course_code SEPARATOR ', ') as enrolled_courses
    FROM users u
    LEFT JOIN enrollments e ON u.id = e.student_id AND e.status IN ('enrolled', 'approved')
    LEFT JOIN schedules sch ON e.schedule_id = sch.id
    LEFT JOIN courses c ON sch.course_id = c.id
    WHERE u.role = 'student'
    GROUP BY u.id
    ORDER BY u.last_name, u.first_name
");
$stmt->execute();
$students = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Summary cards (top 4 courses by enrolled students)
$course_summary = [];
try {
    $stmt = $conn->query("
        SELECT
            c.course_code,
            COUNT(DISTINCT e.student_id) as students
        FROM enrollments e
        JOIN schedules sch ON e.schedule_id = sch.id
        JOIN courses c ON sch.course_id = c.id
        WHERE e.status IN ('enrolled', 'approved')
        GROUP BY c.id
        ORDER BY students DESC
        LIMIT 4
    ");
    $course_summary = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('Error fetching students course summary (registrar): ' . $e->getMessage());
    $course_summary = [];
}

// CSV export
if (isset($_GET['export']) && $_GET['export'] === '1') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="students_export.csv"');

    $out = fopen('php://output', 'w');
    fputcsv($out, ['Student Name', 'Username', 'Email', 'Status', 'Enrollments', 'Courses']);
    foreach ($students as $s) {
        $name = trim(($s['first_name'] ?? '') . ' ' . ($s['last_name'] ?? ''));
        fputcsv($out, [
            $name,
            $s['username'] ?? '',
            $s['email'] ?? '',
            $s['status'] ?? 'active',
            (int)($s['enrollment_count'] ?? 0),
            $s['enrolled_courses'] ?? ''
        ]);
    }
    fclose($out);
    exit();
}

function student_status_label($status) {
    return ($status === 'inactive') ? 'Inactive' : 'Active';
}

function student_status_classes($status) {
    if ($status === 'inactive') {
        return 'bg-slate-100 text-slate-600 ring-1 ring-inset ring-slate-200';
    }
    return 'bg-emerald-50 text-emerald-700 ring-1 ring-inset ring-emerald-200';
}

function initials_for_student($first_name, $last_name, $fallback) {
    $fi = strtoupper(substr((string)$first_name, 0, 1));
    $li = strtoupper(substr((string)$last_name, 0, 1));
    $tmp = trim($fi . $li);
    if ($tmp !== '') {
        return $tmp;
    }
    $fallback = (string)$fallback;
    if ($fallback !== '') {
        return strtoupper(substr($fallback, 0, 2));
    }
    return 'ST';
}

function student_public_id($id) {
    $year = date('Y');
    return $year . '-' . str_pad((string)$id, 4, '0', STR_PAD_LEFT);
}

$page_title = 'Students';
$breadcrumbs = 'Registrar / Students';
$active_page = 'students';
require_once __DIR__ . '/includes/layout_top.php';

$total_students = count($students);
$filter_courses = array_map(function ($r) { return $r['course_code']; }, $course_summary);
?>

<div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-4">
    <div>
        <h1 class="text-2xl font-semibold text-slate-900">Students</h1>
        <p class="text-sm text-slate-600"><?php echo (int)$total_students; ?> students enrolled</p>
    </div>
    <div class="flex items-center gap-2">
        <a href="students.php?export=1" class="inline-flex items-center gap-2 rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-50">
            <i class="bi bi-download"></i>
            Export
        </a>
        <button id="addStudentBtn" type="button" class="inline-flex items-center gap-2 rounded-xl bg-emerald-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-emerald-700">
            <i class="bi bi-plus"></i>
            Add Student
        </button>
    </div>
</div>

<!-- Add Student Modal (Registrar) -->
<div id="addStudentModal" class="fixed inset-0 z-50 hidden" aria-hidden="true">
    <div class="absolute inset-0 bg-slate-900/50" data-modal-close="addStudentModal"></div>
    <div class="relative mx-auto my-8 w-full max-w-lg px-4">
        <div class="rounded-2xl bg-white border border-slate-200 shadow-xl overflow-hidden">
            <div class="px-5 py-4 border-b border-slate-200 flex items-center justify-between">
                <div>
                    <div class="text-base font-semibold text-slate-900">Add Student</div>
                    <div class="text-sm text-slate-600">Create a new student account.</div>
                </div>
                <button type="button" class="h-9 w-9 inline-flex items-center justify-center rounded-xl hover:bg-slate-100" data-modal-close="addStudentModal" aria-label="Close">
                    <i class="bi bi-x-lg"></i>
                </button>
            </div>

            <form action="students.php" method="POST" class="p-5 space-y-4">
                <input type="hidden" name="action" value="add_student">

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-slate-700">First Name</label>
                        <input type="text" name="first_name" required class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm outline-none focus:ring-2 focus:ring-emerald-600/20 focus:border-emerald-300" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-700">Last Name</label>
                        <input type="text" name="last_name" required class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm outline-none focus:ring-2 focus:ring-emerald-600/20 focus:border-emerald-300" />
                    </div>
                </div>

                <div>
                    <label class="block text-sm font-medium text-slate-700">Username</label>
                    <input type="text" name="username" required class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm outline-none focus:ring-2 focus:ring-emerald-600/20 focus:border-emerald-300" />
                </div>

                <div>
                    <label class="block text-sm font-medium text-slate-700">Email</label>
                    <input type="email" name="email" required class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm outline-none focus:ring-2 focus:ring-emerald-600/20 focus:border-emerald-300" />
                </div>

                <div>
                    <label class="block text-sm font-medium text-slate-700">Password</label>
                    <input type="password" name="password" required class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm outline-none focus:ring-2 focus:ring-emerald-600/20 focus:border-emerald-300" />
                </div>

                <div class="pt-2 flex items-center justify-end gap-2">
                    <button type="button" class="inline-flex items-center rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-50" data-modal-close="addStudentModal">Cancel</button>
                    <button type="submit" class="inline-flex items-center rounded-xl bg-emerald-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-emerald-700">Create</button>
                </div>
            </form>
        </div>
    </div>
</div>

<section class="mt-5 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
    <?php if (!empty($course_summary)): ?>
        <?php foreach ($course_summary as $row): ?>
            <div class="bg-white border border-slate-200 rounded-2xl shadow-sm p-5">
                <div class="text-3xl font-semibold text-slate-900"><?php echo (int)$row['students']; ?></div>
                <div class="mt-1 text-sm text-slate-600"><?php echo htmlspecialchars($row['course_code']); ?> Students</div>
            </div>
        <?php endforeach; ?>
    <?php else: ?>
        <?php for ($i = 0; $i < 4; $i++): ?>
            <div class="bg-white border border-slate-200 rounded-2xl shadow-sm p-5">
                <div class="text-3xl font-semibold text-slate-900">0</div>
                <div class="mt-1 text-sm text-slate-600">Students</div>
            </div>
        <?php endfor; ?>
    <?php endif; ?>
</section>

<?php if (isset($_SESSION['success']) || isset($_SESSION['error'])): ?>
    <div class="mt-4">
        <?php if (isset($_SESSION['success'])): ?>
            <div class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-emerald-800">
                <?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?>
            </div>
        <?php endif; ?>
        <?php if (isset($_SESSION['error'])): ?>
            <div class="<?php echo isset($_SESSION['success']) ? 'mt-3 ' : ''; ?>rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-rose-800">
                <?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?>
            </div>
        <?php endif; ?>
    </div>
<?php endif; ?>

<section class="mt-6 bg-white border border-slate-200 rounded-2xl shadow-sm overflow-hidden">
    <div class="p-4 flex flex-col lg:flex-row lg:items-center gap-3 lg:justify-between">
        <div class="w-full lg:max-w-xl">
            <label class="sr-only" for="studentSearch">Search students</label>
            <div class="relative">
                <i class="bi bi-search absolute left-3 top-1/2 -translate-y-1/2 text-slate-400"></i>
                <input id="studentSearch" type="text" placeholder="Search by name or student ID..." class="w-full rounded-xl border border-slate-200 bg-white pl-10 pr-3 py-2.5 text-sm outline-none focus:ring-2 focus:ring-emerald-600/20 focus:border-emerald-300" />
            </div>
        </div>

        <div class="flex flex-wrap gap-2">
            <button type="button" class="course-pill inline-flex items-center rounded-full px-3 py-1.5 text-sm font-semibold bg-emerald-600 text-white" data-course="all">All</button>
            <?php foreach ($filter_courses as $cc): ?>
                <button type="button" class="course-pill inline-flex items-center rounded-full px-3 py-1.5 text-sm font-semibold border border-slate-200 bg-white text-slate-700 hover:bg-slate-50" data-course="<?php echo htmlspecialchars($cc); ?>">
                    <?php echo htmlspecialchars($cc); ?>
                </button>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-slate-200">
            <thead class="bg-slate-50">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">Student</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">Student ID</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">Course</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">Year</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">Status</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">GPA</th>
                    <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wider text-slate-500">Actions</th>
                </tr>
            </thead>
            <tbody id="studentsBody" class="divide-y divide-slate-100 bg-white">
                <?php foreach ($students as $s): ?>
                    <?php
                        $name = trim(($s['first_name'] ?? '') . ' ' . ($s['last_name'] ?? ''));
                        if ($name === '') {
                            $name = (string)($s['username'] ?? 'Student');
                        }
                        $initials = initials_for_student($s['first_name'] ?? '', $s['last_name'] ?? '', $s['username'] ?? '');
                        $sid = student_public_id($s['id']);
                        $status = (string)($s['status'] ?? 'active');
                        $status_label = student_status_label($status);
                        $status_classes = student_status_classes($status);
                        $email = (string)($s['email'] ?? '');
                        $courses_str = (string)($s['enrolled_courses'] ?? '');
                        $course_codes = array_values(array_filter(array_map('trim', explode(',', $courses_str))));
                        $primary_course = !empty($course_codes) ? $course_codes[0] : '—';
                        $search_blob = strtolower(trim($name . ' ' . $email . ' ' . $sid . ' ' . $primary_course));
                        $q = urlencode($email !== '' ? $email : $name);
                    ?>
                    <tr class="student-row" data-course="<?php echo htmlspecialchars($primary_course); ?>" data-search="<?php echo htmlspecialchars($search_blob); ?>">
                        <td class="px-4 py-3">
                            <div class="flex items-center gap-3">
                                <div class="h-10 w-10 rounded-xl bg-blue-600 text-white flex items-center justify-center font-semibold">
                                    <?php echo htmlspecialchars($initials); ?>
                                </div>
                                <div class="min-w-0">
                                    <div class="text-sm font-semibold text-slate-900 truncate"><?php echo htmlspecialchars($name); ?></div>
                                    <div class="text-xs text-slate-500 truncate"><?php echo htmlspecialchars($email); ?></div>
                                </div>
                            </div>
                        </td>
                        <td class="px-4 py-3 text-sm text-slate-600"><?php echo htmlspecialchars($sid); ?></td>
                        <td class="px-4 py-3">
                            <?php if ($primary_course !== '—'): ?>
                                <span class="inline-flex items-center rounded-full bg-blue-50 px-2.5 py-1 text-xs font-semibold text-blue-700 ring-1 ring-inset ring-blue-200">
                                    <?php echo htmlspecialchars($primary_course); ?>
                                </span>
                            <?php else: ?>
                                <span class="text-sm text-slate-500">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="px-4 py-3 text-sm text-slate-600">—</td>
                        <td class="px-4 py-3">
                            <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold <?php echo htmlspecialchars($status_classes); ?>">
                                <?php echo htmlspecialchars($status_label); ?>
                            </span>
                        </td>
                        <td class="px-4 py-3 text-sm text-slate-600">—</td>
                        <td class="px-4 py-3">
                            <div class="flex items-center justify-end gap-2">
                                <a href="../admin/enrollments.php?student_id=<?php echo (int)$s['id']; ?>" class="inline-flex h-9 w-9 items-center justify-center rounded-xl border border-slate-200 bg-white text-slate-600 hover:bg-slate-50" title="View enrollments" aria-label="View">
                                    <i class="bi bi-eye"></i>
                                </a>
                                <a href="../admin/users.php?q=<?php echo htmlspecialchars($q); ?>" class="inline-flex h-9 w-9 items-center justify-center rounded-xl border border-slate-200 bg-white text-slate-600 hover:bg-slate-50" title="Edit in All Users" aria-label="Edit">
                                    <i class="bi bi-pencil"></i>
                                </a>
                                <a href="../admin/users.php?q=<?php echo htmlspecialchars($q); ?>" class="inline-flex h-9 w-9 items-center justify-center rounded-xl border border-slate-200 bg-white text-slate-600 hover:bg-slate-50" title="Delete in All Users" aria-label="Delete">
                                    <i class="bi bi-trash"></i>
                                </a>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>

                <?php if (empty($students)): ?>
                    <tr>
                        <td colspan="7" class="px-4 py-10 text-center text-sm text-slate-500">No students found</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<script>
    (function () {
        const searchInput = document.getElementById('studentSearch');
        const pills = Array.from(document.querySelectorAll('.course-pill'));
        const rows = Array.from(document.querySelectorAll('.student-row'));
        let activeCourse = 'all';

        function applyFilters() {
            const q = (searchInput?.value || '').trim().toLowerCase();
            rows.forEach(function (row) {
                const course = (row.getAttribute('data-course') || '').toLowerCase();
                const blob = (row.getAttribute('data-search') || '').toLowerCase();
                const matchCourse = activeCourse === 'all' || course === activeCourse;
                const matchSearch = q === '' || blob.includes(q);
                row.hidden = !(matchCourse && matchSearch);
            });
        }

        pills.forEach(function (pill) {
            pill.addEventListener('click', function () {
                activeCourse = (pill.getAttribute('data-course') || 'all').toLowerCase();
                pills.forEach(function (p) {
                    p.classList.remove('bg-emerald-600', 'text-white');
                    p.classList.add('border', 'border-slate-200', 'bg-white', 'text-slate-700');
                });
                pill.classList.add('bg-emerald-600', 'text-white');
                pill.classList.remove('border', 'border-slate-200', 'bg-white', 'text-slate-700');
                applyFilters();
            });
        });

        searchInput?.addEventListener('input', applyFilters);
        applyFilters();

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

        document.getElementById('addStudentBtn')?.addEventListener('click', function () {
            openModal('addStudentModal');
        });

        document.querySelectorAll('[data-modal-close]')?.forEach(function (btn) {
            btn.addEventListener('click', function () {
                const target = btn.getAttribute('data-modal-close');
                if (target) closeModal(target);
            });
        });
    })();
</script>

<?php require_once __DIR__ . '/includes/layout_bottom.php'; ?>