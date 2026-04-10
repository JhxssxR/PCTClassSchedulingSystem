<?php
session_start();
require_once '../config/database.php';

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'registrar'], true)) {
    header('Location: ../auth/login.php');
    exit();
}

function users_columns(PDO $conn): array {
    $stmt = $conn->prepare('DESCRIBE users');
    $stmt->execute();
    $cols = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (!empty($row['Field'])) {
            $cols[$row['Field']] = true;
        }
    }
    return $cols;
}

function initials_for($first_name, $last_name, $fallback): string {
    $fi = strtoupper(substr((string) $first_name, 0, 1));
    $li = strtoupper(substr((string) $last_name, 0, 1));
    $tmp = trim($fi . $li);
    if ($tmp !== '') {
        return $tmp;
    }
    $fallback = (string) $fallback;
    if ($fallback !== '') {
        return strtoupper(substr($fallback, 0, 2));
    }
    return 'IN';
}

function instructor_code($id): string {
    return 'INS-' . str_pad((string) $id, 3, '0', STR_PAD_LEFT);
}

function instructor_status_label(string $status): string {
    return ($status === 'inactive') ? 'On Leave' : 'Active';
}

function instructor_status_classes(string $status): string {
    if ($status === 'inactive') {
        return 'bg-orange-50 text-orange-700 ring-1 ring-inset ring-orange-200';
    }
    return 'bg-emerald-50 text-emerald-700 ring-1 ring-inset ring-emerald-200';
}

$users_cols = users_columns($conn);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');

    try {
        if ($action === 'add' || $action === 'edit') {
            $first_name = trim((string) ($_POST['first_name'] ?? ''));
            $last_name = trim((string) ($_POST['last_name'] ?? ''));
            $email = trim((string) ($_POST['email'] ?? ''));
            $username = trim((string) ($_POST['username'] ?? ''));
            $password = (string) ($_POST['password'] ?? '');
            $phone_number = trim((string) ($_POST['phone_number'] ?? ''));
            $department = trim((string) ($_POST['department'] ?? ''));
            $status = strtolower(trim((string) ($_POST['status'] ?? 'active')));
            if (!in_array($status, ['active', 'inactive'], true)) {
                $status = 'active';
            }

            if ($department === '') {
                $department = 'Information Technology Education';
            }
            if (strlen($phone_number) > 30) {
                throw new Exception('Contact number is too long.');
            }
            if (strlen($department) > 100) {
                throw new Exception('Department name is too long.');
            }

            if ($first_name === '' || $last_name === '' || $email === '' || $username === '') {
                throw new Exception('Please fill in all required fields.');
            }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new Exception('Please provide a valid email address.');
            }

            if ($action === 'add') {
                if ($password === '') {
                    throw new Exception('Password is required for new instructor accounts.');
                }

                $stmt = $conn->prepare('SELECT 1 FROM users WHERE username = ? LIMIT 1');
                $stmt->execute([$username]);
                if ($stmt->fetchColumn()) {
                    throw new Exception('Username already exists.');
                }

                $stmt = $conn->prepare('SELECT 1 FROM users WHERE email = ? LIMIT 1');
                $stmt->execute([$email]);
                if ($stmt->fetchColumn()) {
                    throw new Exception('Email already exists.');
                }

                $db_now = date('Y-m-d H:i:s');
                try {
                    $db_now = (string) $conn->query('SELECT NOW()')->fetchColumn();
                } catch (Throwable $e) {
                    // Keep local timestamp fallback.
                }

                $cols = ['username', 'password', 'email', 'role', 'first_name', 'last_name'];
                $vals = [':username', ':password', ':email', ':role', ':first_name', ':last_name'];
                $params = [
                    'username' => $username,
                    'password' => password_hash($password, PASSWORD_DEFAULT),
                    'email' => $email,
                    'role' => 'instructor',
                    'first_name' => $first_name,
                    'last_name' => $last_name,
                ];

                if (isset($users_cols['status'])) {
                    $cols[] = 'status';
                    $vals[] = ':status';
                    $params['status'] = $status;
                }

                if (isset($users_cols['department'])) {
                    $cols[] = 'department';
                    $vals[] = ':department';
                    $params['department'] = $department;
                }

                if (isset($users_cols['phone_number'])) {
                    $cols[] = 'phone_number';
                    $vals[] = ':phone_number';
                    $params['phone_number'] = $phone_number !== '' ? $phone_number : null;
                }

                if (isset($users_cols['created_at'])) {
                    $cols[] = 'created_at';
                    $vals[] = ':created_at';
                    $params['created_at'] = $db_now;
                }

                if (isset($users_cols['updated_at'])) {
                    $cols[] = 'updated_at';
                    $vals[] = ':updated_at';
                    $params['updated_at'] = $db_now;
                }

                $sql = 'INSERT INTO users (' . implode(', ', $cols) . ') VALUES (' . implode(', ', $vals) . ')';
                $stmt = $conn->prepare($sql);
                $stmt->execute($params);

                $_SESSION['success'] = 'Instructor added successfully.';
            } else {
                $instructor_id = (int) ($_POST['instructor_id'] ?? 0);
                if ($instructor_id <= 0) {
                    throw new Exception('Invalid instructor ID.');
                }

                $stmt = $conn->prepare("SELECT 1 FROM users WHERE id = ? AND role = 'instructor' LIMIT 1");
                $stmt->execute([$instructor_id]);
                if (!$stmt->fetchColumn()) {
                    throw new Exception('Instructor not found.');
                }

                $stmt = $conn->prepare('SELECT 1 FROM users WHERE username = ? AND id <> ? LIMIT 1');
                $stmt->execute([$username, $instructor_id]);
                if ($stmt->fetchColumn()) {
                    throw new Exception('Username already exists.');
                }

                $stmt = $conn->prepare('SELECT 1 FROM users WHERE email = ? AND id <> ? LIMIT 1');
                $stmt->execute([$email, $instructor_id]);
                if ($stmt->fetchColumn()) {
                    throw new Exception('Email already exists.');
                }

                $set_parts = [
                    'first_name = :first_name',
                    'last_name = :last_name',
                    'email = :email',
                    'username = :username',
                    "role = 'instructor'",
                ];
                $params = [
                    'id' => $instructor_id,
                    'first_name' => $first_name,
                    'last_name' => $last_name,
                    'email' => $email,
                    'username' => $username,
                ];

                if ($password !== '') {
                    $set_parts[] = 'password = :password';
                    $params['password'] = password_hash($password, PASSWORD_DEFAULT);
                }

                if (isset($users_cols['status'])) {
                    $set_parts[] = 'status = :status';
                    $params['status'] = $status;
                }

                if (isset($users_cols['department'])) {
                    $set_parts[] = 'department = :department';
                    $params['department'] = $department;
                }

                if (isset($users_cols['phone_number'])) {
                    $set_parts[] = 'phone_number = :phone_number';
                    $params['phone_number'] = $phone_number !== '' ? $phone_number : null;
                }

                if (isset($users_cols['updated_at'])) {
                    $set_parts[] = 'updated_at = NOW()';
                }

                $sql = 'UPDATE users SET ' . implode(', ', $set_parts) . ' WHERE id = :id AND role = \'instructor\'';
                $stmt = $conn->prepare($sql);
                $stmt->execute($params);

                $_SESSION['success'] = 'Instructor updated successfully.';
            }
        } elseif ($action === 'delete') {
            $instructor_id = (int) ($_POST['instructor_id'] ?? 0);
            if ($instructor_id <= 0) {
                throw new Exception('Invalid instructor ID.');
            }

            $stmt = $conn->prepare("SELECT 1 FROM users WHERE id = ? AND role = 'instructor' LIMIT 1");
            $stmt->execute([$instructor_id]);
            if (!$stmt->fetchColumn()) {
                throw new Exception('Instructor not found.');
            }

            $stmt = $conn->prepare('SELECT COUNT(*) FROM schedules WHERE instructor_id = ? AND status = \'active\'');
            $stmt->execute([$instructor_id]);
            $active_schedule_count = (int) $stmt->fetchColumn();

            if ($active_schedule_count > 0 && isset($users_cols['status'])) {
                $stmt = $conn->prepare('UPDATE users SET status = \'inactive\'' . (isset($users_cols['updated_at']) ? ', updated_at = NOW()' : '') . ' WHERE id = ? AND role = \'instructor\'');
                $stmt->execute([$instructor_id]);
                $_SESSION['success'] = 'Instructor has active schedules and was set to inactive instead of being deleted.';
            } else {
                $stmt = $conn->prepare('DELETE FROM users WHERE id = ? AND role = \'instructor\'');
                $stmt->execute([$instructor_id]);
                $_SESSION['success'] = 'Instructor deleted successfully.';
            }
        }
    } catch (Exception $e) {
        $_SESSION['error'] = $e->getMessage();
    }

    header('Location: instructors.php');
    exit();
}

$search = isset($_GET['search']) ? (string) $_GET['search'] : '';
$filter = isset($_GET['filter']) ? (string) $_GET['filter'] : 'all';

$status_expr = isset($users_cols['status']) ? "COALESCE(u.status, 'active')" : "'active'";
$phone_expr = isset($users_cols['phone_number']) ? "COALESCE(u.phone_number, '')" : "''";
$department_expr = isset($users_cols['department']) ? "COALESCE(u.department, 'Information Technology Education')" : "'Information Technology Education'";

$stmt = $conn->prepare(
    "
    SELECT
        u.id,
        u.first_name,
        u.last_name,
        u.email,
        u.username,
        {$phone_expr} AS phone_number,
        {$department_expr} AS department,
        {$status_expr} AS status,
        COUNT(DISTINCT s.id) AS class_count,
        COUNT(DISTINCT e.student_id) AS student_count,
        GROUP_CONCAT(DISTINCT c.course_code ORDER BY c.course_code SEPARATOR ', ') AS courses
    FROM users u
    LEFT JOIN schedules s ON u.id = s.instructor_id AND s.status = 'active'
    LEFT JOIN courses c ON s.course_id = c.id
    LEFT JOIN enrollments e ON e.schedule_id = s.id AND e.status IN ('enrolled', 'approved')
    WHERE u.role = 'instructor'
      " . (!empty($search) ? "AND (u.first_name LIKE :search OR u.last_name LIKE :search OR u.email LIKE :search OR u.username LIKE :search)" : '') . "
      " . ($filter !== 'all'
            ? ($filter === 'active'
                ? "AND {$status_expr} = 'active'"
                : "AND {$status_expr} = 'inactive'")
            : '') . "
    GROUP BY u.id
    ORDER BY u.last_name, u.first_name
    "
);

if (!empty($search)) {
    $stmt->bindValue(':search', '%' . $search . '%', PDO::PARAM_STR);
}
$stmt->execute();
$instructors = $stmt->fetchAll(PDO::FETCH_ASSOC);

$total_instructors = (int) $conn->query("SELECT COUNT(*) FROM users WHERE role = 'instructor'")->fetchColumn();
$active_instructors = (int) $conn->query("SELECT COUNT(*) FROM users WHERE role = 'instructor'" . (isset($users_cols['status']) ? " AND COALESCE(status,'active') = 'active'" : ''))->fetchColumn();
$inactive_instructors = max(0, $total_instructors - $active_instructors);

$featured_instructors = array_slice($instructors, 0, 4);
$showing = count($instructors);

$page_title = 'Instructors';
$breadcrumbs = 'Registrar / Instructors';
$active_page = 'instructors';
require_once __DIR__ . '/includes/layout_top.php';
?>

<div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-4">
    <div>
        <h1 class="text-2xl font-semibold text-slate-900">Instructors</h1>
        <p class="text-sm text-slate-600"><?php echo (int) $total_instructors; ?> faculty members registered</p>
    </div>
    <button id="openInstructorModalBtn" type="button" class="inline-flex items-center gap-2 rounded-xl bg-emerald-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-emerald-700">
        <i class="bi bi-plus"></i>
        Add Instructor
    </button>
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

<section class="mt-5 grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-4">
    <?php foreach ($featured_instructors as $ins): ?>
        <?php
            $name = trim((string) ($ins['first_name'] ?? '') . ' ' . (string) ($ins['last_name'] ?? ''));
            if ($name === '') {
                $name = (string) ($ins['username'] ?? 'Instructor');
            }
            $ins_initials = initials_for($ins['first_name'] ?? '', $ins['last_name'] ?? '', $ins['username'] ?? '');
            $code = instructor_code($ins['id']);
            $status = (string) ($ins['status'] ?? 'active');
            $status_label = instructor_status_label($status);
            $status_classes = instructor_status_classes($status);
            $class_count = (int) ($ins['class_count'] ?? 0);
            $student_count = (int) ($ins['student_count'] ?? 0);
            $email = (string) ($ins['email'] ?? '');
            $phone_number = (string) ($ins['phone_number'] ?? '');
            $department = (string) ($ins['department'] ?? 'Information Technology Education');
        ?>
        <article class="bg-white border border-slate-200 rounded-2xl shadow-sm overflow-hidden transition duration-200 hover:-translate-y-1 hover:shadow-lg hover:border-emerald-200">
            <div class="h-1 bg-emerald-500"></div>
            <div class="p-4">
                <div class="flex items-start justify-between gap-3">
                    <div class="flex items-center gap-3 min-w-0">
                        <div class="h-11 w-11 rounded-xl bg-blue-600 text-white flex items-center justify-center font-semibold">
                            <?php echo htmlspecialchars($ins_initials); ?>
                        </div>
                        <div class="min-w-0">
                            <div class="text-sm font-semibold text-slate-900 truncate"><?php echo htmlspecialchars($name); ?></div>
                            <div class="text-xs text-slate-500 truncate"><?php echo htmlspecialchars($code); ?></div>
                        </div>
                    </div>
                    <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold <?php echo htmlspecialchars($status_classes); ?>">
                        <?php echo htmlspecialchars($status_label); ?>
                    </span>
                </div>

                <div class="mt-4 space-y-2 text-sm text-slate-600">
                    <div class="text-xs font-medium text-slate-500">Department</div>
                    <div class="text-sm text-slate-700"><?php echo htmlspecialchars($department); ?></div>
                    <div class="pt-2 space-y-1">
                        <div class="flex items-center gap-2 text-sm">
                            <i class="bi bi-envelope text-slate-400"></i>
                            <span class="truncate"><?php echo htmlspecialchars($email !== '' ? $email : '—'); ?></span>
                        </div>
                        <div class="flex items-center gap-2 text-sm">
                            <i class="bi bi-telephone text-slate-400"></i>
                            <span class="truncate"><?php echo htmlspecialchars($phone_number !== '' ? $phone_number : '—'); ?></span>
                        </div>
                    </div>
                </div>

                <div class="mt-4 pt-3 border-t border-slate-100 flex items-center justify-between">
                    <div class="text-xs text-slate-500"><?php echo $class_count; ?> courses • <?php echo $student_count; ?> students</div>
                    <div class="flex items-center gap-2">
                        <a href="instructor_schedule.php?id=<?php echo (int) $ins['id']; ?>" class="h-9 w-9 inline-flex items-center justify-center rounded-xl border border-slate-200 bg-white text-slate-600 hover:bg-slate-50" title="View Schedule" aria-label="View Schedule">
                            <i class="bi bi-calendar3"></i>
                        </a>
                        <button type="button" class="js-edit-instructor h-9 w-9 inline-flex items-center justify-center rounded-xl border border-slate-200 bg-white text-slate-600 hover:bg-slate-50" title="Edit Instructor" aria-label="Edit"
                            data-id="<?php echo (int) $ins['id']; ?>"
                            data-first-name="<?php echo htmlspecialchars((string) ($ins['first_name'] ?? '')); ?>"
                            data-last-name="<?php echo htmlspecialchars((string) ($ins['last_name'] ?? '')); ?>"
                            data-email="<?php echo htmlspecialchars($email); ?>"
                            data-username="<?php echo htmlspecialchars((string) ($ins['username'] ?? '')); ?>"
                            data-phone-number="<?php echo htmlspecialchars($phone_number); ?>"
                            data-department="<?php echo htmlspecialchars($department); ?>"
                            data-status="<?php echo htmlspecialchars($status); ?>">
                            <i class="bi bi-pencil"></i>
                        </button>
                        <button type="button" class="js-delete-instructor h-9 w-9 inline-flex items-center justify-center rounded-xl border border-slate-200 bg-white text-rose-600 hover:bg-rose-50" title="Delete Instructor" aria-label="Delete"
                            data-id="<?php echo (int) $ins['id']; ?>"
                            data-name="<?php echo htmlspecialchars($name); ?>">
                            <i class="bi bi-trash"></i>
                        </button>
                    </div>
                </div>
            </div>
        </article>
    <?php endforeach; ?>
</section>

<section class="mt-6 bg-white border border-slate-200 rounded-2xl shadow-sm overflow-hidden">
    <div class="p-4 flex items-center justify-between gap-4">
        <div class="flex items-center gap-2 text-sm text-slate-500">
            <i class="bi bi-funnel"></i>
            Showing <?php echo (int) $showing; ?> of <?php echo (int) $total_instructors; ?> instructors
        </div>

        <form method="GET" class="w-full max-w-md">
            <input type="hidden" name="filter" value="<?php echo htmlspecialchars($filter); ?>" />
            <label class="sr-only" for="insSearch">Search instructors</label>
            <div class="relative">
                <i class="bi bi-search absolute left-3 top-1/2 -translate-y-1/2 text-slate-400"></i>
                <input id="insSearch" name="search" value="<?php echo htmlspecialchars($search); ?>" type="text" placeholder="Search instructors..." class="w-full rounded-xl border border-slate-200 bg-white pl-10 pr-3 py-2.5 text-sm outline-none focus:ring-2 focus:ring-emerald-600/20 focus:border-emerald-300" />
            </div>
        </form>
    </div>

    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-slate-200">
            <thead class="bg-slate-50">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">Instructor</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">Department</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">Courses</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">Students</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">Status</th>
                    <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wider text-slate-500">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100 bg-white">
                <?php foreach ($instructors as $ins): ?>
                    <?php
                        $name = trim((string) ($ins['first_name'] ?? '') . ' ' . (string) ($ins['last_name'] ?? ''));
                        if ($name === '') {
                            $name = (string) ($ins['username'] ?? 'Instructor');
                        }
                        $ins_initials = initials_for($ins['first_name'] ?? '', $ins['last_name'] ?? '', $ins['username'] ?? '');
                        $code = instructor_code($ins['id']);
                        $status = (string) ($ins['status'] ?? 'active');
                        $status_label = instructor_status_label($status);
                        $status_classes = instructor_status_classes($status);
                        $student_count = (int) ($ins['student_count'] ?? 0);
                        $courses_str = (string) ($ins['courses'] ?? '');
                        $course_codes = array_values(array_filter(array_map('trim', explode(',', $courses_str))));
                        $course_preview = array_slice($course_codes, 0, 2);
                        $course_more = max(0, count($course_codes) - count($course_preview));
                        $email = (string) ($ins['email'] ?? '');
                        $phone_number = (string) ($ins['phone_number'] ?? '');
                        $department = (string) ($ins['department'] ?? 'Information Technology Education');
                    ?>
                    <tr>
                        <td class="px-4 py-3">
                            <div class="flex items-center gap-3">
                                <div class="h-10 w-10 rounded-xl bg-blue-600 text-white flex items-center justify-center font-semibold">
                                    <?php echo htmlspecialchars($ins_initials); ?>
                                </div>
                                <div class="min-w-0">
                                    <div class="text-sm font-semibold text-slate-900 truncate"><?php echo htmlspecialchars($name); ?></div>
                                    <div class="text-xs text-slate-500 truncate"><?php echo htmlspecialchars($code); ?></div>
                                </div>
                            </div>
                        </td>
                        <td class="px-4 py-3 text-sm text-slate-600"><?php echo htmlspecialchars($department); ?></td>
                        <td class="px-4 py-3">
                            <div class="flex flex-wrap gap-2">
                                <?php if (!empty($course_preview)): ?>
                                    <?php foreach ($course_preview as $cc): ?>
                                        <span class="inline-flex items-center rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-700"><?php echo htmlspecialchars($cc); ?></span>
                                    <?php endforeach; ?>
                                    <?php if ($course_more > 0): ?>
                                        <span class="inline-flex items-center rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-700">+<?php echo $course_more; ?></span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="text-sm text-slate-500">—</span>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td class="px-4 py-3 text-sm text-slate-700"><?php echo $student_count; ?></td>
                        <td class="px-4 py-3">
                            <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold <?php echo htmlspecialchars($status_classes); ?>">
                                <?php echo htmlspecialchars($status_label); ?>
                            </span>
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex items-center justify-end gap-2">
                                <a href="instructor_schedule.php?id=<?php echo (int) $ins['id']; ?>" class="inline-flex h-9 w-9 items-center justify-center rounded-xl border border-slate-200 bg-white text-slate-600 hover:bg-slate-50" title="View Schedule" aria-label="View Schedule">
                                    <i class="bi bi-calendar3"></i>
                                </a>
                                <button type="button" class="js-edit-instructor inline-flex h-9 w-9 items-center justify-center rounded-xl border border-slate-200 bg-white text-slate-600 hover:bg-slate-50" title="Edit Instructor" aria-label="Edit"
                                    data-id="<?php echo (int) $ins['id']; ?>"
                                    data-first-name="<?php echo htmlspecialchars((string) ($ins['first_name'] ?? '')); ?>"
                                    data-last-name="<?php echo htmlspecialchars((string) ($ins['last_name'] ?? '')); ?>"
                                    data-email="<?php echo htmlspecialchars($email); ?>"
                                    data-username="<?php echo htmlspecialchars((string) ($ins['username'] ?? '')); ?>"
                                    data-phone-number="<?php echo htmlspecialchars($phone_number); ?>"
                                    data-department="<?php echo htmlspecialchars($department); ?>"
                                    data-status="<?php echo htmlspecialchars($status); ?>">
                                    <i class="bi bi-pencil"></i>
                                </button>
                                <button type="button" class="js-delete-instructor inline-flex h-9 w-9 items-center justify-center rounded-xl border border-slate-200 bg-white text-rose-600 hover:bg-rose-50" title="Delete Instructor" aria-label="Delete"
                                    data-id="<?php echo (int) $ins['id']; ?>"
                                    data-name="<?php echo htmlspecialchars($name); ?>">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>

                <?php if (empty($instructors)): ?>
                    <tr>
                        <td colspan="6" class="px-4 py-10 text-center text-sm text-slate-500">No instructors found</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<div id="instructorModal" class="fixed inset-0 z-50 hidden" aria-hidden="true">
    <div class="absolute inset-0 bg-slate-900/50" data-modal-close></div>
    <div class="absolute inset-0 p-4 flex items-center justify-center">
        <div class="w-full max-w-xl rounded-2xl border border-slate-200 bg-white shadow-2xl overflow-hidden">
            <div class="px-5 py-4 border-b border-slate-200 flex items-center justify-between">
                <h3 id="instructorModalTitle" class="text-lg font-semibold text-slate-900">Add Instructor</h3>
                <button type="button" class="inline-flex h-9 w-9 items-center justify-center rounded-xl border border-slate-200 text-slate-600 hover:bg-slate-50" data-modal-close aria-label="Close">
                    <i class="bi bi-x-lg"></i>
                </button>
            </div>

            <form id="instructorForm" action="instructors.php" method="POST" class="px-5 py-5 space-y-4">
                <input type="hidden" name="action" id="formAction" value="add">
                <input type="hidden" name="instructor_id" id="instructorId" value="">

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label for="firstName" class="block text-sm font-semibold text-slate-700 mb-1">First Name</label>
                        <input id="firstName" name="first_name" type="text" required class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-emerald-200 focus:border-emerald-400">
                    </div>
                    <div>
                        <label for="lastName" class="block text-sm font-semibold text-slate-700 mb-1">Last Name</label>
                        <input id="lastName" name="last_name" type="text" required class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-emerald-200 focus:border-emerald-400">
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label for="email" class="block text-sm font-semibold text-slate-700 mb-1">Email</label>
                        <input id="email" name="email" type="email" required class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-emerald-200 focus:border-emerald-400">
                    </div>
                    <div>
                        <label for="username" class="block text-sm font-semibold text-slate-700 mb-1">Username</label>
                        <input id="username" name="username" type="text" required class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-emerald-200 focus:border-emerald-400">
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label for="contactNumber" class="block text-sm font-semibold text-slate-700 mb-1">Contact Number</label>
                        <input id="contactNumber" name="phone_number" type="text" placeholder="e.g. 09123456789" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-emerald-200 focus:border-emerald-400">
                    </div>
                    <div>
                        <label for="department" class="block text-sm font-semibold text-slate-700 mb-1">Department</label>
                        <input id="department" name="department" type="text" value="Information Technology Education" required class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-emerald-200 focus:border-emerald-400">
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label for="password" class="block text-sm font-semibold text-slate-700 mb-1">Password</label>
                        <input id="password" name="password" type="password" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-emerald-200 focus:border-emerald-400">
                        <p id="passwordHint" class="mt-1 text-xs text-slate-500">Required for new instructor accounts.</p>
                    </div>
                    <div>
                        <label for="status" class="block text-sm font-semibold text-slate-700 mb-1">Status</label>
                        <select id="status" name="status" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-emerald-200 focus:border-emerald-400">
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>
                </div>

                <div class="flex items-center justify-end gap-3 pt-2">
                    <button type="button" class="inline-flex items-center rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-50" data-modal-close>Cancel</button>
                    <button type="submit" class="inline-flex items-center rounded-xl bg-emerald-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-emerald-700">Save Instructor</button>
                </div>
            </form>
        </div>
    </div>
</div>

<form id="deleteInstructorForm" action="instructors.php" method="POST" class="hidden">
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="instructor_id" id="deleteInstructorId" value="">
</form>

<script>
    (function () {
        const modal = document.getElementById('instructorModal');
        const openBtn = document.getElementById('openInstructorModalBtn');
        const closeEls = document.querySelectorAll('[data-modal-close]');
        const form = document.getElementById('instructorForm');
        const formAction = document.getElementById('formAction');
        const formTitle = document.getElementById('instructorModalTitle');
        const instructorId = document.getElementById('instructorId');
        const firstName = document.getElementById('firstName');
        const lastName = document.getElementById('lastName');
        const email = document.getElementById('email');
        const username = document.getElementById('username');
        const contactNumber = document.getElementById('contactNumber');
        const department = document.getElementById('department');
        const password = document.getElementById('password');
        const passwordHint = document.getElementById('passwordHint');
        const status = document.getElementById('status');
        const editButtons = document.querySelectorAll('.js-edit-instructor');
        const deleteButtons = document.querySelectorAll('.js-delete-instructor');
        const deleteForm = document.getElementById('deleteInstructorForm');
        const deleteInstructorId = document.getElementById('deleteInstructorId');

        function openModal() {
            modal.classList.remove('hidden');
            modal.setAttribute('aria-hidden', 'false');
        }

        function closeModal() {
            modal.classList.add('hidden');
            modal.setAttribute('aria-hidden', 'true');
        }

        function resetForAdd() {
            formTitle.textContent = 'Add Instructor';
            formAction.value = 'add';
            instructorId.value = '';
            form.reset();
            password.required = true;
            passwordHint.textContent = 'Required for new instructor accounts.';
            status.value = 'active';
            department.value = 'Information Technology Education';
        }

        openBtn?.addEventListener('click', function () {
            resetForAdd();
            openModal();
        });

        editButtons.forEach(function (button) {
            button.addEventListener('click', function () {
                formTitle.textContent = 'Edit Instructor';
                formAction.value = 'edit';
                instructorId.value = button.getAttribute('data-id') || '';
                firstName.value = button.getAttribute('data-first-name') || '';
                lastName.value = button.getAttribute('data-last-name') || '';
                email.value = button.getAttribute('data-email') || '';
                username.value = button.getAttribute('data-username') || '';
                contactNumber.value = button.getAttribute('data-phone-number') || '';
                department.value = button.getAttribute('data-department') || 'Information Technology Education';
                status.value = button.getAttribute('data-status') || 'active';
                password.value = '';
                password.required = false;
                passwordHint.textContent = 'Leave blank to keep current password.';
                openModal();
            });
        });

        deleteButtons.forEach(function (button) {
            button.addEventListener('click', function () {
                const id = button.getAttribute('data-id') || '';
                const name = button.getAttribute('data-name') || 'this instructor';
                if (!id) {
                    return;
                }

                if (!window.confirm('Delete ' + name + '?')) {
                    return;
                }

                deleteInstructorId.value = id;
                deleteForm.submit();
            });
        });

        closeEls.forEach(function (el) {
            el.addEventListener('click', function () {
                closeModal();
            });
        });

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && !modal.classList.contains('hidden')) {
                closeModal();
            }
        });

        form?.addEventListener('submit', function () {
            // Let the server handle validation and redirect with status messages.
        });
    })();
</script>

<?php require_once __DIR__ . '/includes/layout_bottom.php'; ?>
