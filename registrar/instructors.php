<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is a registrar
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'registrar'], true)) {
    header('Location: ../auth/login.php');
    exit();
}
 
// Match the Super Admin instructors page structure
$search = isset($_GET['search']) ? (string) $_GET['search'] : '';
$filter = isset($_GET['filter']) ? (string) $_GET['filter'] : 'all';

$stmt = $conn->prepare("
    SELECT u.*, 
           COUNT(DISTINCT s.id) as class_count,
           COUNT(DISTINCT e.student_id) as student_count,
           GROUP_CONCAT(DISTINCT c.course_code ORDER BY c.course_code SEPARATOR ', ') as courses
    FROM users u
    LEFT JOIN schedules s ON u.id = s.instructor_id AND s.status = 'active'
    LEFT JOIN courses c ON s.course_id = c.id
    LEFT JOIN enrollments e ON e.schedule_id = s.id AND e.status IN ('enrolled', 'approved')
    WHERE u.role = 'instructor'
    " . (!empty($search) ? "AND (u.first_name LIKE :search OR u.last_name LIKE :search OR u.email LIKE :search)" : "") . "
    " . ($filter !== 'all' ? 
        ($filter === 'active' ? 
            "AND EXISTS (SELECT 1 FROM schedules s2 WHERE s2.instructor_id = u.id AND s2.status = 'active')" : 
            "AND NOT EXISTS (SELECT 1 FROM schedules s2 WHERE s2.instructor_id = u.id AND s2.status = 'active')"
        ) : "") . "
    GROUP BY u.id
    ORDER BY u.last_name, u.first_name
");

if (!empty($search)) {
    $stmt->bindValue(':search', "%$search%", PDO::PARAM_STR);
}
$stmt->execute();
$instructors = $stmt->fetchAll(PDO::FETCH_ASSOC);

$total_instructors = (int) $conn->query("SELECT COUNT(*) FROM users WHERE role = 'instructor'")->fetchColumn();
$active_instructors = (int) $conn->query(" 
    SELECT COUNT(DISTINCT u.id)
    FROM users u
    JOIN schedules s ON u.id = s.instructor_id
    WHERE u.role = 'instructor' AND s.status = 'active'
")->fetchColumn();
$inactive_instructors = $total_instructors - $active_instructors;

function initials_for($first_name, $last_name, $fallback) {
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
    return 'IN';
}

function instructor_code($id) {
    return 'INS-' . str_pad((string)$id, 3, '0', STR_PAD_LEFT);
}

function instructor_status_label($status) {
    return ($status === 'inactive') ? 'On Leave' : 'Active';
}

function instructor_status_classes($status) {
    if ($status === 'inactive') {
        return 'bg-orange-50 text-orange-700 ring-1 ring-inset ring-orange-200';
    }
    return 'bg-emerald-50 text-emerald-700 ring-1 ring-inset ring-emerald-200';
}

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
        <p class="text-sm text-slate-600"><?php echo (int)$total_instructors; ?> faculty members registered</p>
    </div>
    <a href="../admin/users.php?add=1&role=instructor" class="inline-flex items-center gap-2 rounded-xl bg-emerald-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-emerald-700">
        <i class="bi bi-plus"></i>
        Add Instructor
    </a>
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
            $name = trim(($ins['first_name'] ?? '') . ' ' . ($ins['last_name'] ?? ''));
            if ($name === '') {
                $name = (string)($ins['username'] ?? 'Instructor');
            }
            $ins_initials = initials_for($ins['first_name'] ?? '', $ins['last_name'] ?? '', $ins['username'] ?? '');
            $code = instructor_code($ins['id']);
            $status = (string)($ins['status'] ?? 'active');
            $status_label = instructor_status_label($status);
            $status_classes = instructor_status_classes($status);
            $class_count = (int)($ins['class_count'] ?? 0);
            $student_count = (int)($ins['student_count'] ?? 0);
            $email = (string)($ins['email'] ?? '');
            $q = urlencode($email !== '' ? $email : $name);
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
                    <div class="text-sm text-slate-700">—</div>
                    <div class="pt-2 space-y-1">
                        <div class="flex items-center gap-2 text-sm">
                            <i class="bi bi-envelope text-slate-400"></i>
                            <span class="truncate"><?php echo htmlspecialchars($email !== '' ? $email : '—'); ?></span>
                        </div>
                        <div class="flex items-center gap-2 text-sm">
                            <i class="bi bi-telephone text-slate-400"></i>
                            <span>—</span>
                        </div>
                    </div>
                </div>

                <div class="mt-4 pt-3 border-t border-slate-100 flex items-center justify-between">
                    <div class="text-xs text-slate-500"><?php echo $class_count; ?> courses • <?php echo $student_count; ?> students</div>
                    <div class="flex items-center gap-2">
                        <a href="../admin/users.php?q=<?php echo htmlspecialchars($q); ?>" class="h-9 w-9 inline-flex items-center justify-center rounded-xl border border-slate-200 bg-white text-slate-600 hover:bg-slate-50" title="Manage in All Users" aria-label="Manage">
                            <i class="bi bi-eye"></i>
                        </a>
                        <a href="../admin/users.php?q=<?php echo htmlspecialchars($q); ?>" class="h-9 w-9 inline-flex items-center justify-center rounded-xl border border-slate-200 bg-white text-slate-600 hover:bg-slate-50" title="Edit in All Users" aria-label="Edit">
                            <i class="bi bi-pencil"></i>
                        </a>
                        <a href="../admin/users.php?q=<?php echo htmlspecialchars($q); ?>" class="h-9 w-9 inline-flex items-center justify-center rounded-xl border border-slate-200 bg-white text-slate-600 hover:bg-slate-50" title="Delete in All Users" aria-label="Delete">
                            <i class="bi bi-trash"></i>
                        </a>
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
            Showing <?php echo (int)$showing; ?> of <?php echo (int)$total_instructors; ?> instructors
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
                        $name = trim(($ins['first_name'] ?? '') . ' ' . ($ins['last_name'] ?? ''));
                        if ($name === '') {
                            $name = (string)($ins['username'] ?? 'Instructor');
                        }
                        $ins_initials = initials_for($ins['first_name'] ?? '', $ins['last_name'] ?? '', $ins['username'] ?? '');
                        $code = instructor_code($ins['id']);
                        $status = (string)($ins['status'] ?? 'active');
                        $status_label = instructor_status_label($status);
                        $status_classes = instructor_status_classes($status);
                        $student_count = (int)($ins['student_count'] ?? 0);
                        $courses_str = (string)($ins['courses'] ?? '');
                        $course_codes = array_values(array_filter(array_map('trim', explode(',', $courses_str))));
                        $course_preview = array_slice($course_codes, 0, 2);
                        $course_more = max(0, count($course_codes) - count($course_preview));
                        $email = (string)($ins['email'] ?? '');
                        $q = urlencode($email !== '' ? $email : $name);
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
                        <td class="px-4 py-3 text-sm text-slate-600">—</td>
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
                                <a href="../admin/users.php?q=<?php echo htmlspecialchars($q); ?>" class="inline-flex h-9 w-9 items-center justify-center rounded-xl border border-slate-200 bg-white text-slate-600 hover:bg-slate-50" title="Manage in All Users" aria-label="Manage">
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

                <?php if (empty($instructors)): ?>
                    <tr>
                        <td colspan="6" class="px-4 py-10 text-center text-sm text-slate-500">No instructors found</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<?php require_once __DIR__ . '/includes/layout_bottom.php'; ?>
                                    echo array_reduce($instructors, function($carry, $instructor) {
                                        return $carry + ($instructor['assigned_classes'] == 0 ? 1 : 0);
                                    }, 0);
                                    ?>
                                </h2>
                            </div>
                        </div>
                    </div>
                    <div class="col-sm-6 col-lg-3">
                        <div class="card">
                            <div class="card-body">
                                <div class="d-grid">
                                    <button type="button" class="btn btn-primary mb-2" data-bs-toggle="modal" data-bs-target="#createInstructorModal">
                                        <i class="bi bi-plus-circle"></i> Add New Instructor
                                    </button>
                                    <button type="button" class="btn btn-secondary" onclick="printInstructorList()">
                                        <i class="bi bi-printer"></i> Print List
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Instructors Table -->
            <div class="col-md-12">
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">Instructor List</h5>
                        <div class="btn-group">
                            <button type="button" class="btn btn-light" onclick="exportInstructorList()">
                                <i class="bi bi-file-earmark-excel"></i> Export
                            </button>
                            <button type="button" class="btn btn-light" onclick="printInstructorList()">
                                <i class="bi bi-printer"></i> Print
                            </button>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Name</th>
                                        <th>Email</th>
                                        <th>Username</th>
                                        <th>Assigned Classes</th>
                                        <th>Courses</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach($instructors as $instructor): ?>
                                    <tr>
                                        <td><?php echo $instructor['id']; ?></td>
                                        <td><?php echo htmlspecialchars($instructor['first_name'] . ' ' . $instructor['last_name']); ?></td>
                                        <td><?php echo htmlspecialchars($instructor['email']); ?></td>
                                        <td><?php echo htmlspecialchars($instructor['username']); ?></td>
                                        <td><?php echo $instructor['assigned_classes']; ?></td>
                                        <td><?php echo htmlspecialchars($instructor['course_details'] ?? 'None'); ?></td>
                                        <td>
                                            <div class="btn-group">
                                                <button type="button" class="btn btn-sm btn-info" onclick="editInstructor(<?php echo htmlspecialchars(json_encode($instructor)); ?>)">
                                                    <i class="bi bi-pencil"></i>
                                                </button>
                                                <button type="button" class="btn btn-sm btn-success" onclick="viewSchedule(<?php echo $instructor['id']; ?>)">
                                                    <i class="bi bi-calendar3"></i>
                                                </button>
                                                <button type="button" class="btn btn-sm btn-danger" onclick="deleteInstructor(<?php echo $instructor['id']; ?>)">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                    <?php if (empty($instructors)): ?>
                                    <tr>
                                        <td colspan="7" class="text-center">No instructors found</td>
                                    </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    <!-- Create/Edit Instructor Modal -->
    <div class="modal fade" id="createInstructorModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="instructorModalTitle">Add New Instructor</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form id="instructorForm" action="instructors.php" method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="add">
                        <input type="hidden" name="instructor_id" id="edit_instructor_id">
                        <div class="mb-3">
                            <label class="form-label">First Name</label>
                            <input type="text" class="form-control" name="first_name" id="first_name" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Last Name</label>
                            <input type="text" class="form-control" name="last_name" id="last_name" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Email</label>
                            <input type="email" class="form-control" name="email" id="email" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Username</label>
                            <input type="text" class="form-control" name="username" id="username" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Password</label>
                            <input type="password" class="form-control" name="password" id="password" required>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Save Instructor</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        function editInstructor(instructor) {
            document.getElementById('instructorModalTitle').textContent = 'Edit Instructor';
            document.getElementById('edit_instructor_id').value = instructor.id;
            document.getElementById('first_name').value = instructor.first_name;
            document.getElementById('last_name').value = instructor.last_name;
            document.getElementById('email').value = instructor.email;
            document.getElementById('username').value = instructor.username;
            document.getElementById('password').required = false;
            document.getElementById('instructorForm').action = 'instructors.php';
            document.querySelector('input[name="action"]').value = 'edit';
            new bootstrap.Modal(document.getElementById('createInstructorModal')).show();
        }

        function deleteInstructor(instructorId) {
            if (confirm('Are you sure you want to delete this instructor?')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = 'instructors.php?t=' + Date.now(); // Add timestamp to prevent caching
                
                const actionInput = document.createElement('input');
                actionInput.type = 'hidden';
                actionInput.name = 'action';
                actionInput.value = 'delete';
                
                const instructorIdInput = document.createElement('input');
                instructorIdInput.type = 'hidden';
                instructorIdInput.name = 'instructor_id';
                instructorIdInput.value = instructorId;
                
                form.appendChild(actionInput);
                form.appendChild(instructorIdInput);
                document.body.appendChild(form);
                form.submit();
            }
        }

        function printInstructorList() {
            window.open('print_instructors.php', '_blank');
        }

        function exportInstructorList() {
            window.location.href = 'export_instructors.php';
        }

        function viewSchedule(instructorId) {
            window.open('instructor_schedule.php?id=' + instructorId, '_blank');
        }

        // Add this new function to show login credentials after account creation
        function showLoginCredentials(username, password) {
            const modal = new bootstrap.Modal(document.getElementById('credentialsModal'));
            document.getElementById('instructorUsername').textContent = username;
            document.getElementById('instructorPassword').textContent = password;
            modal.show();
        }

        // Modify the form submission to show credentials
        document.getElementById('instructorForm').addEventListener('submit', function(e) {
            e.preventDefault();
            const formData = new FormData(this);
            
            fetch('instructors.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.text())
            .then(html => {
                if (formData.get('action') === 'add') {
                    showLoginCredentials(formData.get('username'), formData.get('password'));
                }
                window.location.reload();
            });
        });
    </script>

    <!-- Add this new modal for showing login credentials -->
    <div class="modal fade" id="credentialsModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Instructor Account Created</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>Please provide these login credentials to the instructor:</p>
                    <div class="alert alert-info">
                        <p><strong>Username:</strong> <span id="instructorUsername"></span></p>
                        <p><strong>Password:</strong> <span id="instructorPassword"></span></p>
                    </div>
                    <p class="text-danger">Please note: These credentials will not be shown again. Make sure to save them securely.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="button" class="btn btn-primary" onclick="window.print()">Print Credentials</button>
                </div>
            </div>
        </div>
    </div>

<?php require_once __DIR__ . '/includes/layout_bottom.php'; ?>