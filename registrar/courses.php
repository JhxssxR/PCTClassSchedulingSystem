<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is a registrar/admin
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'registrar'], true)) {
    header('Location: ../auth/login.php');
    exit();
}

// Courses schema compatibility: older DBs use credits/description, newer might use units.
$course_cols_stmt = $conn->prepare('DESCRIBE courses');
$course_cols_stmt->execute();
$course_cols = [];
foreach ($course_cols_stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $course_cols[$r['Field']] = true;
}

$units_col = isset($course_cols['units']) ? 'units' : (isset($course_cols['credits']) ? 'credits' : 'units');

// Pagination (6 per page)
$per_page = 6;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) $page = 1;

$total_courses = (int)$conn->query('SELECT COUNT(*) FROM courses')->fetchColumn();
$total_pages = (int)max(1, (int)ceil($total_courses / $per_page));
if ($page > $total_pages) $page = $total_pages;
$offset = ($page - 1) * $per_page;

$qs_prev = http_build_query(array_merge($_GET, ['page' => max(1, $page - 1)]));
$qs_next = http_build_query(array_merge($_GET, ['page' => min($total_pages, $page + 1)]));

// Get paginated courses with their schedule and enrollment counts
$stmt = $conn->prepare("
    SELECT c.*,
           (SELECT COUNT(*) FROM schedules WHERE course_id = c.id AND status = 'active') as schedule_count,
           (SELECT COUNT(*) FROM enrollments e
            JOIN schedules s ON e.schedule_id = s.id
            WHERE s.course_id = c.id AND e.status IN ('approved', 'enrolled')) as enrollment_count
    FROM courses c
    ORDER BY c.course_code
    LIMIT :limit OFFSET :offset
");
$stmt->bindValue(':limit', $per_page, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$courses = $stmt->fetchAll(PDO::FETCH_ASSOC);

$card_colors = [
    'bg-blue-600',
    'bg-emerald-600',
    'bg-orange-500',
    'bg-violet-600',
    'bg-sky-600',
    'bg-rose-600',
];

$page_title = 'Courses';
$breadcrumbs = 'Registrar / Courses';
$active_page = 'courses';
require_once __DIR__ . '/includes/layout_top.php';
?>

<div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-4">
    <div>
        <h1 class="text-2xl font-semibold text-slate-900">Courses</h1>
        <p class="text-sm text-slate-500"><?php echo (int)$total_courses; ?> courses</p>
    </div>

    <button id="addCourseBtn" type="button" class="inline-flex items-center gap-2 rounded-xl bg-emerald-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-emerald-700">
        <i class="bi bi-plus-lg"></i>
        <span>Add Course</span>
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

<div class="mt-6">
    <div class="relative max-w-md">
        <i class="bi bi-search absolute left-3 top-1/2 -translate-y-1/2 text-slate-400"></i>
        <input id="courseSearch" type="text" placeholder="Search courses…" class="w-full rounded-xl border border-slate-200 bg-white pl-10 pr-3 py-2.5 text-sm outline-none focus:ring-2 focus:ring-emerald-200" />
    </div>
</div>

<div class="mt-6 grid grid-cols-1 lg:grid-cols-2 gap-5">
    <?php foreach ($courses as $i => $course): ?>
        <?php
            $color = $card_colors[$i % count($card_colors)];
            $units_val = isset($course[$units_col]) ? $course[$units_col] : null;
            $course_for_js = $course;
            $course_for_js['units'] = $units_val;
        ?>
        <div class="course-card rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden" data-search="<?php echo htmlspecialchars(strtolower(($course['course_code'] ?? '') . ' ' . ($course['course_name'] ?? ''))); ?>">
            <div class="p-5 <?php echo $color; ?> text-white">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <div class="text-xs font-semibold opacity-90"><?php echo htmlspecialchars($course['course_code'] ?? ''); ?></div>
                        <div class="mt-1 text-lg font-semibold leading-snug"><?php echo htmlspecialchars($course['course_name'] ?? ''); ?></div>
                    </div>
                    <div class="h-10 w-10 rounded-xl bg-white/15 flex items-center justify-center">
                        <i class="bi bi-book text-xl"></i>
                    </div>
                </div>
            </div>

            <div class="p-5">
                <div class="grid grid-cols-2 gap-3">
                    <div class="rounded-xl bg-slate-50 border border-slate-200 px-3 py-3 text-center">
                        <div class="text-lg font-semibold text-slate-900"><?php echo (int)($course['enrollment_count'] ?? 0); ?></div>
                        <div class="text-xs text-slate-500">Students</div>
                    </div>
                    <div class="rounded-xl bg-slate-50 border border-slate-200 px-3 py-3 text-center">
                        <div class="text-lg font-semibold text-slate-900"><?php echo (int)($course['schedule_count'] ?? 0); ?></div>
                        <div class="text-xs text-slate-500">Classes</div>
                    </div>
                </div>

                <div class="mt-4 flex items-center justify-between">
                    <?php if ((int)($course['schedule_count'] ?? 0) > 0): ?>
                        <span class="inline-flex items-center gap-2 rounded-full bg-emerald-50 text-emerald-700 ring-1 ring-inset ring-emerald-200 px-3 py-1 text-xs font-semibold">
                            <span class="h-1.5 w-1.5 rounded-full bg-emerald-500"></span>
                            Active
                        </span>
                    <?php else: ?>
                        <span class="inline-flex items-center gap-2 rounded-full bg-slate-100 text-slate-700 ring-1 ring-inset ring-slate-200 px-3 py-1 text-xs font-semibold">
                            <span class="h-1.5 w-1.5 rounded-full bg-slate-500"></span>
                            No classes
                        </span>
                    <?php endif; ?>

                    <div class="flex items-center gap-1.5 text-slate-500">
                        <a class="h-9 w-9 inline-flex items-center justify-center rounded-xl hover:bg-slate-100" href="../admin/classes.php?search=<?php echo urlencode((string)($course['course_code'] ?? '')); ?>" title="View classes">
                            <i class="bi bi-eye"></i>
                        </a>
                        <button type="button" class="h-9 w-9 inline-flex items-center justify-center rounded-xl hover:bg-slate-100" onclick='editCourse(<?php echo json_encode($course_for_js, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>)' title="Edit">
                            <i class="bi bi-pencil"></i>
                        </button>
                        <?php if ((int)($course['schedule_count'] ?? 0) === 0): ?>
                            <button type="button" class="h-9 w-9 inline-flex items-center justify-center rounded-xl hover:bg-slate-100" onclick="deleteCourse(<?php echo (int)$course['id']; ?>)" title="Delete">
                                <i class="bi bi-trash"></i>
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<?php if ($total_pages > 1): ?>
    <div class="mt-8 flex items-center justify-between">
        <div class="text-sm text-slate-500">Page <?php echo (int)$page; ?> of <?php echo (int)$total_pages; ?></div>
        <div class="flex items-center gap-2">
            <a href="?<?php echo htmlspecialchars($qs_prev); ?>" class="inline-flex items-center rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50 <?php echo ($page <= 1) ? 'pointer-events-none opacity-50' : ''; ?>">Prev</a>
            <a href="?<?php echo htmlspecialchars($qs_next); ?>" class="inline-flex items-center rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50 <?php echo ($page >= $total_pages) ? 'pointer-events-none opacity-50' : ''; ?>">Next</a>
        </div>
    </div>
<?php endif; ?>

<!-- Add Course Modal -->
<div id="addCourseModal" class="fixed inset-0 z-50 hidden" aria-hidden="true">
    <div class="absolute inset-0 bg-slate-900/50" data-modal-close="addCourseModal"></div>
    <div class="relative mx-auto my-10 w-[92%] max-w-xl">
        <div class="rounded-2xl bg-white shadow-xl border border-slate-200 overflow-hidden">
            <div class="px-5 py-4 border-b border-slate-200 flex items-center justify-between">
                <div class="text-base font-semibold text-slate-900">Add Course</div>
                <button type="button" class="h-9 w-9 inline-flex items-center justify-center rounded-xl hover:bg-slate-100" data-modal-close="addCourseModal" aria-label="Close">
                    <i class="bi bi-x-lg"></i>
                </button>
            </div>

            <form action="process_course.php" method="POST" class="p-5">
                <input type="hidden" name="action" value="add">
                <div class="grid grid-cols-1 gap-4">
                    <div>
                        <label class="block text-sm font-semibold text-slate-700 mb-1">Course code</label>
                        <input type="text" name="course_code" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm" required>
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-slate-700 mb-1">Course name</label>
                        <input type="text" name="course_name" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm" required>
                    </div>
                    <div>
                        <input type="hidden" name="units" value="3">
                    </div>
                </div>
                <div class="mt-5 flex items-center justify-end gap-3">
                    <button type="button" class="inline-flex items-center rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-50" data-modal-close="addCourseModal">Cancel</button>
                    <button type="submit" class="inline-flex items-center rounded-xl bg-emerald-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-emerald-700">Add Course</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Course Modal -->
<div id="editCourseModal" class="fixed inset-0 z-50 hidden" aria-hidden="true">
    <div class="absolute inset-0 bg-slate-900/50" data-modal-close="editCourseModal"></div>
    <div class="relative mx-auto my-10 w-[92%] max-w-xl">
        <div class="rounded-2xl bg-white shadow-xl border border-slate-200 overflow-hidden">
            <div class="px-5 py-4 border-b border-slate-200 flex items-center justify-between">
                <div class="text-base font-semibold text-slate-900">Edit Course</div>
                <button type="button" class="h-9 w-9 inline-flex items-center justify-center rounded-xl hover:bg-slate-100" data-modal-close="editCourseModal" aria-label="Close">
                    <i class="bi bi-x-lg"></i>
                </button>
            </div>

            <form action="process_course.php" method="POST" class="p-5">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="course_id" id="edit_course_id">
                <div class="grid grid-cols-1 gap-4">
                    <div>
                        <label class="block text-sm font-semibold text-slate-700 mb-1">Course code</label>
                        <input type="text" name="course_code" id="edit_course_code" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm" required>
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-slate-700 mb-1">Course name</label>
                        <input type="text" name="course_name" id="edit_course_name" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm" required>
                    </div>
                    <div>
                        <input type="hidden" name="units" id="edit_units" value="3">
                    </div>
                </div>
                <div class="mt-5 flex items-center justify-end gap-3">
                    <button type="button" class="inline-flex items-center rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-50" data-modal-close="editCourseModal">Cancel</button>
                    <button type="submit" class="inline-flex items-center rounded-xl bg-emerald-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-emerald-700">Update Course</button>
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

        document.getElementById('addCourseBtn')?.addEventListener('click', function () {
            openModal('addCourseModal');
        });
        document.querySelectorAll('[data-modal-close]')?.forEach(function (b) {
            b.addEventListener('click', function () {
                const target = b.getAttribute('data-modal-close');
                if (target) closeModal(target);
            });
        });

        window.editCourse = function (courseData) {
            document.getElementById('edit_course_id').value = courseData.id;
            document.getElementById('edit_course_code').value = courseData.course_code || '';
            document.getElementById('edit_course_name').value = courseData.course_name || '';
            document.getElementById('edit_units').value = courseData.units || courseData.credits || 3;
            openModal('editCourseModal');
        }

        window.deleteCourse = function (courseId) {
            if (confirm('Are you sure you want to delete this course? This action cannot be undone.')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = 'process_course.php';

                const actionInput = document.createElement('input');
                actionInput.type = 'hidden';
                actionInput.name = 'action';
                actionInput.value = 'delete';

                const idInput = document.createElement('input');
                idInput.type = 'hidden';
                idInput.name = 'course_id';
                idInput.value = courseId;

                form.appendChild(actionInput);
                form.appendChild(idInput);
                document.body.appendChild(form);
                form.submit();
            }
        }

        const searchInput = document.getElementById('courseSearch');
        const cards = Array.from(document.querySelectorAll('.course-card'));
        function applySearch() {
            const q = (searchInput?.value || '').trim().toLowerCase();
            cards.forEach(function (c) {
                const hay = (c.getAttribute('data-search') || '').toLowerCase();
                c.style.display = (!q || hay.includes(q)) ? '' : 'none';
            });
        }
        searchInput?.addEventListener('input', applySearch);
    })();
</script>

<?php require_once __DIR__ . '/includes/layout_bottom.php'; ?>
